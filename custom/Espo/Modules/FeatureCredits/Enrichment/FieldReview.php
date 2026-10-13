<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\{Conflict,NotFound};
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureCredits\Accounting\{Amount,GrantInput};
use PDO;

/** Ordinary field purchases use the existing atomically settled attempt receipt. */
final class FieldReview
{
    public function __construct(private ReviewLock $lock,private Access $access,private Crypt $crypt) {}

    public function read(string $tenant,string $id): array
    {
        GrantInput::identity($tenant); GrantInput::identity($id);
        return $this->lock->run($tenant,fn (PDO $pdo): array => $this->reviewLocked($pdo,$tenant,$id));
    }

    public function reviewLocked(PDO $pdo,string $tenant,string $id): array
    {
        $saved = $this->readLocked($pdo,$tenant,$id);
        $input = new QuoteInput((object) $saved['terms']['request']);
        $target = $this->access->target($input);
        $changed = !$this->identityMatches($pdo,$saved,$this->access->identity($target));
        $permissions = $this->access->fieldApplicationPermissions($target,array_keys(FieldApplication::choices($saved['receipt']['product'],$saved['values'])));
        $reason = $saved['erased'] ? 'data_erased' : ($changed ? 'record_identity_changed' :
            (!$permissions['actorIsHuman'] ? 'human_required' : (!$permissions['recordEditAllowed'] || !$permissions['editableFields'] ? 'application_permission_required' : null)));
        return ['receipt' => $saved['receipt'],'values' => (object) $saved['values'],'erased' => $saved['erased'],
            'provenance' => ['actorId' => $saved['terms']['actorId'],'targetType' => $input->data['targetType'],'targetId' => $input->data['targetId'],
                'requestedProducts' => $input->data['products'],'waterfall' => $input->data['waterfall'],'refresh' => $input->data['refresh'],
                'delivery' => $saved['delivery'],'acquisitionQuoteId' => $saved['acquisitionQuoteId'],
                'sourceAttemptId' => $saved['sourceAttemptId']],
            'identityChanged' => $changed,'application' => [...$saved['applicationStatus'],'available' => !$saved['applicationStatus']['applied'] && $reason === null,'recheckRequired' => true,
                'permissions' => $permissions,'reason' => $reason]];
    }

    /** A sibling's atomic application may change this quote's email/phone inputs.
     * Accept only the exact full identity recorded by a validated same-quote receipt;
     * unrelated/manual changes still require review. Original snapshots stay immutable.
     */
    public function identityMatches(PDO $pdo,array $saved,array $identity): bool
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Application identity checks require a transaction.');
        $hash = Quotes::hash($identity);
        if (hash_equals(Quotes::hash($saved['terms']['identity']),$hash)) return true;
        $request = $saved['terms']['request'];
        $q = $pdo->prepare('SELECT id,application_receipt FROM enrichment_attempt WHERE tenant_id=? AND quote_id=?
            AND deleted=FALSE AND application_hash IS NOT NULL AND applied_identity_hash=?');
        $q->execute([$request['tenantId'],$saved['receipt']['quoteId'],$hash]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sibling = $this->readLocked($pdo,$request['tenantId'],$row['id']);
            if ($sibling['erased'] || !$sibling['applicationStatus']['applied']) continue;
            $receipt = json_decode($row['application_receipt'],true,32,JSON_THROW_ON_ERROR);
            if (($receipt['source'] ?? null) !== 'field' || ($receipt['targetType'] ?? null) !== $request['targetType'] ||
                ($receipt['targetId'] ?? null) !== $request['targetId']) throw new Conflict('Sibling application receipt binding differs.');
            return true;
        }
        return false;
    }

    /** Internal only; caller must check current target/field access before exposing any result. */
    public function readLocked(PDO $pdo,string $tenant,string $id): array
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Field review requires a transaction.');
        GrantInput::identity($id);
        $q = $pdo->prepare('SELECT * FROM enrichment_attempt WHERE tenant_id=? AND id=? AND deleted=FALSE AND purchased_at IS NOT NULL');
        $q->execute([$tenant,$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('Unknown purchased field result.');
        $q = $pdo->prepare('SELECT * FROM enrichment_quote WHERE tenant_id=? AND id=? FOR UPDATE');
        $q->execute([$tenant,$row['quote_id']]); $quote = $q->fetch(PDO::FETCH_ASSOC);
        if (!$quote) throw new Conflict('Purchased quote is unavailable.');
        $terms = Quotes::decode($quote); $input = new QuoteInput((object) $terms['request']);
        $routes = array_values(array_filter($terms['routes'][$row['product']] ?? [],static fn ($r) => $r['provider'] === $row['provider']));
        $q = $pdo->prepare('SELECT * FROM credit_usage WHERE tenant_id=? AND id=?');
        $q->execute([$tenant,$quote['usage_id']]); $usage = $q->fetch(PDO::FETCH_ASSOC);
        $q = $pdo->prepare('SELECT * FROM credit_reservation WHERE tenant_id=? AND usage_id=?');
        $q->execute([$tenant,$quote['usage_id']]); $hold = $q->fetch(PDO::FETCH_ASSOC);
        if ($input->isDiscovery() || $quote['confirmed_at'] === null || $quote['admitted_at'] === null || !$usage || $usage['deleted'] ||
            $usage['state'] !== 'settled' || $usage['operation_type'] !== 'enrichment' ||
            $usage['operation_key'] !== 'enrichment:' . $quote['id'] || $usage['execution_id'] !== 'enrichment:' . $quote['id'] ||
            !$hold || $hold['deleted'] || $hold['state'] !== 'settled' || $hold['execution_id'] !== $usage['execution_id'] ||
            $row['completed_at'] === null || $row['completed_at'] > $row['purchased_at'] || $row['purchased_at'] !== $usage['settled_at'] ||
            !in_array($row['product'],$input->data['products'],true) ||
            $row['state'] !== 'matched' || count($routes) !== 1 || $routes[0]['credits'] !== $row['credits']) {
            throw new Conflict('Purchased field financial binding differs.');
        }
        $q = $pdo->prepare('SELECT credits FROM enrichment_attempt WHERE tenant_id=? AND quote_id=? AND purchased_at IS NOT NULL AND deleted=FALSE');
        $q->execute([$tenant,$quote['id']]); $total = Amount::fromString('0');
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $amount) $total = $total->plus(Amount::fromString($amount));
        if ((string) $total !== $usage['settled_credits']) throw new Conflict('Purchased field charge differs.');
        $erased = ($row['erased_at'] ?? null) !== null;
        $values = $row['result_ciphertext'] === null ? [] : json_decode($this->crypt->decrypt($row['result_ciphertext']),true,32,JSON_THROW_ON_ERROR);
        if (!$erased && !$values) throw new Conflict('Purchased field material is unavailable.');
        if ($erased ? $row['result_ciphertext'] !== null :
            !hash_equals($row['result_hash'] ?? '',FieldCache::resultHash($tenant,$quote['id'],$id,$row['provider'],$row['product'],$values,$row))) {
            throw new Conflict('Purchased field material integrity differs.');
        }
        return ['terms' => $terms,'values' => $values,'erased' => $erased,
            'delivery' => ($row['source_attempt_id'] ?? null) === null ? 'live' : 'cache',
            'acquisitionQuoteId' => $row['acquisition_quote_id'] ?? $quote['id'], 'sourceAttemptId' => $row['source_attempt_id'] ?? null,
            'applicationStatus' => ApplicationStatus::fromRow($row),'receipt' => ['id' => $id,'quoteId' => $quote['id'],
            'usageId' => $usage['id'],'product' => $row['product'],'provider' => $row['provider'],'policyId' => $terms['policyId'],
            'credits' => $row['credits'],'acquiredAt' => $row['acquired_at'] ?? $row['completed_at'],'purchasedAt' => $row['purchased_at']]];
    }
}
