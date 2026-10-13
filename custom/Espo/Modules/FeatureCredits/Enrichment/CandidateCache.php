<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\{Conflict,NotFound};
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureCredits\Accounting\{GrantInput,RecordId,WalletLock};
use InvalidArgumentException;
use PDO;

/** Restricted server-only acquisition store. No raw supplier payload or ordinary record API. */
final class CandidateCache
{
    public function __construct(private WalletLock $lock, private Crypt $crypt,
        private ActorAccess $actors, private ProviderAvailability $availability) {}

    /**
     * Called only with adapter-normalized, contract-permitted data after a durable supplier claim.
     * sourceKey identifies that claim's result item; candidateKey is the supplier's stable person key.
     * expiresAt is the earlier of the supplier's permitted expiry and acquisition + 90 days.
     * This method deliberately neither dispatches a supplier nor activates a capability.
     */
    public function store(string $tenant, string $quote, string $provider, string $sourceKey, string $candidateKey,
        string $acquiredAt, string $expiresAt, array $values, array $incidental = [], bool $naturalPerson = false): array
    {
        return $this->lock->run($tenant,fn (PDO $pdo,array $wallet,string $now): array =>
            $this->storeLocked($pdo,$now,$tenant,$quote,$provider,$sourceKey,$candidateKey,$acquiredAt,$expiresAt,$values,$incidental,$naturalPerson));
    }

    /** Internal primitive: acquisition rows and their durable supplier outcome must commit together. */
    public function storeLocked(PDO $pdo,string $now,string $tenant,string $quote,string $provider,string $sourceKey,string $candidateKey,
        string $acquiredAt,string $expiresAt,array $values,array $incidental = [],bool $naturalPerson = false): array
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Acquisition requires the tenant transaction.');
        foreach ([$sourceKey,$candidateKey] as $key) {
            if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) throw new InvalidArgumentException('Opaque server acquisition keys required.');
        }
        GrantInput::timestamp($acquiredAt); GrantInput::timestamp($expiresAt);
        if ($expiresAt <= $acquiredAt || $expiresAt > self::after($acquiredAt,90*86400)) {
            throw new InvalidArgumentException('Invalid supplier retention interval.');
        }
            [$row,$terms,$input] = $this->contextLocked($pdo,$tenant,$quote);
            $product = $input->data['products'][0];
            $values = CandidateProjection::normalize($product,$values);
            if ($product !== 'cnpj' && (!$naturalPerson || !isset($values['name']) ||
                ($product === 'socios' && !isset($values['role'])))) {
                throw new InvalidArgumentException('Complete natural-person identity and relationship required.');
            }
            if ($product === 'cnpj' && $incidental) throw new InvalidArgumentException('Registrant discovery has no personal contact cache.');
            $incidental = self::incidental($incidental);
            $scope = self::scope($terms);
            $revision = $terms['availability'][$provider][$product] ?? null;
            if (!is_string($revision)) throw new Conflict('Supplier is outside accepted discovery capability.');
            $payload = ['values' => $values,'incidental' => $incidental];
            $hash = Quotes::hash([$scope,$sourceKey,$candidateKey,$provider,$revision,$acquiredAt,$expiresAt,$payload]);
            $q = $pdo->prepare('SELECT * FROM enrichment_candidate_cache WHERE tenant_id=? AND scope_hash=? AND provider=? AND source_key=?');
            $q->execute([$tenant,$scope,$provider,$sourceKey]);
            if ($saved = $q->fetch(PDO::FETCH_ASSOC)) {
                if ($saved['deleted'] || !hash_equals($saved['input_hash'],$hash)) throw new Conflict('Acquisition replay differs.');
                // Never recreate purged hidden material or extend original retention on replay.
                return self::receipt($saved);
            }
            $this->assertLiveLocked($pdo,$row,$terms,$input,$provider,$now);
            if ($acquiredAt < $row['admitted_at'] || $acquiredAt > $now || $expiresAt <= $now) {
                throw new Conflict('Acquisition is outside the admitted interval or expired.');
            }
            $q = $pdo->prepare('SELECT COALESCE(MAX(result_order),0)+1 FROM enrichment_candidate_cache WHERE tenant_id=? AND scope_hash=?');
            $q->execute([$tenant,$scope]); $order = (int) $q->fetchColumn();
            $saved = ['id' => RecordId::generate(),'tenant_id' => $tenant,'quote_id' => $quote,'scope_hash' => $scope,
                'source_key' => $sourceKey,'candidate_key' => $candidateKey,'input_hash' => $hash,'provider' => $provider,'product' => $product,
                'capability_revision' => $revision,'payload_ciphertext' => $this->crypt->encrypt(json_encode($payload,JSON_THROW_ON_ERROR)),
                'acquired_at' => $acquiredAt,'expires_at' => $expiresAt,'result_order' => $order,'identity_scope_hash' => self::identityScope($terms)];
            $pdo->prepare('INSERT INTO enrichment_candidate_cache (id,tenant_id,quote_id,scope_hash,source_key,candidate_key,input_hash,
                provider,product,capability_revision,payload_ciphertext,acquired_at,expires_at,result_order,identity_scope_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_values($saved));
            return self::receipt($saved);
    }

    /** Internal cache-first traversal: returns opaque IDs only, preserving acquisition/result insertion order. */
    public function eligible(string $tenant,string $quote,string $provider): array
    {
        return $this->lock->run($tenant,function (PDO $pdo,array $wallet,string $now) use ($tenant,$quote,$provider): array {
            [$row,$terms,$input] = $this->contextLocked($pdo,$tenant,$quote);
            $this->assertLiveLocked($pdo,$row,$terms,$input,$provider,$now);
            // A quoted fresh lookup must not silently become an old-cache purchase.
            if ($input->data['refresh']) return [];
            $q = $pdo->prepare('SELECT id FROM enrichment_candidate_cache WHERE tenant_id=? AND scope_hash=? AND provider=?
                AND capability_revision=? AND deleted=FALSE AND expires_at>? AND payload_ciphertext IS NOT NULL
                ORDER BY acquired_at,result_order');
            $q->execute([$tenant,self::scope($terms),$provider,$terms['availability'][$provider][$input->data['products'][0]],$now]);
            return $q->fetchAll(PDO::FETCH_COLUMN);
        });
    }

    /** Must be called while holding the shared tenant lock, inside the charge/access transaction. */
    public function loadLocked(PDO $pdo,array $terms,string $quote,string $id,string $now): array
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Candidate purchase requires an accounting transaction.');
        GrantInput::identity($id);
        $q = $pdo->prepare('SELECT * FROM enrichment_candidate_cache WHERE id=? AND tenant_id=?');
        $q->execute([$id,$terms['request']['tenantId']]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['deleted'] || $row['scope_hash'] !== self::scope($terms) ||
            $row['identity_scope_hash'] !== self::identityScope($terms)) throw new NotFound('Unknown discovery acquisition.');
        if (CandidateConflicts::statusLocked($pdo,$row['tenant_id'],$id) !== null) throw new Conflict('Candidate identity is blocked.');
        if ($row['expires_at'] <= $now || $row['payload_ciphertext'] === null ||
            $row['capability_revision'] !== ($terms['availability'][$row['provider']][$row['product']] ?? null) ||
            ($terms['request']['refresh'] && $row['quote_id'] !== $quote)) {
            throw new Conflict('Acquisition is expired or incompatible with accepted terms.');
        }
        $payload = json_decode($this->crypt->decrypt($row['payload_ciphertext']),true,32,JSON_THROW_ON_ERROR);
        $expected = Quotes::hash([$row['scope_hash'],$row['source_key'],$row['candidate_key'],$row['provider'],
            $row['capability_revision'],$row['acquired_at'],$row['expires_at'],$payload]);
        if (!hash_equals($expected,$row['input_hash'])) throw new Conflict('Acquisition integrity mismatch.');
        return [$row,$payload['values']]; // Incidental values never cross into Finder purchase/review.
    }

    public function purge(string $tenant): int
    {
        return $this->lock->run($tenant,static function (PDO $pdo,array $wallet,string $now) use ($tenant): int {
            $q = $pdo->prepare('UPDATE enrichment_candidate_cache SET payload_ciphertext=NULL
                WHERE tenant_id=? AND expires_at<=? AND payload_ciphertext IS NOT NULL');
            $q->execute([$tenant,$now]);
            return $q->rowCount();
        });
    }

    public static function scope(array $terms): string
    {
        $request = $terms['request'];
        // Exact filter reuse is conservative: never claim a cached page exhausts another search.
        return Quotes::hash([$request['tenantId'],$request['targetId'],$terms['identity'],
            $request['products'],$request['filters'] ?? []]);
    }

    /** Search filters control acquisition reuse, not whether this Account already purchased a person. */
    public static function identityScope(array $terms): string
    {
        $request = $terms['request'];
        return Quotes::hash([$request['tenantId'],$request['targetId'],$terms['identity'],$request['products']]);
    }

    /** Shared internal discovery authorization; caller owns the tenant transaction. */
    public function contextLocked(PDO $pdo,string $tenant,string $quote): array
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Discovery requires the tenant transaction.');
        GrantInput::identity($quote);
        $q = $pdo->prepare('SELECT * FROM enrichment_quote WHERE id=? AND tenant_id=? FOR UPDATE'); $q->execute([$quote,$tenant]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('Unknown discovery quote.');
        $terms = Quotes::decode($row); $input = new QuoteInput((object) $terms['request']);
        if (!$input->isDiscovery() || !$row['confirmed_at'] || !$row['usage_id'] || !$row['admitted_at']) {
            throw new Conflict('Discovery acquisition is not admitted.');
        }
        $q = $pdo->prepare('SELECT id FROM account WHERE id=? AND tenant_id=? AND deleted=FALSE FOR UPDATE');
        $q->execute([$input->data['targetId'],$tenant]);
        if (!$q->fetchColumn()) throw new NotFound('Unknown discovery Account.');
        $access = $this->actors->forActor($terms['actorId']); $target = $access->target($input);
        if (Quotes::hash($access->discoveryIdentity($input,$target)) !== Quotes::hash($terms['identity']) ||
            Quotes::hash($access->discoveryPermissions($input,$target)) !== Quotes::hash($terms['permissions'])) {
            throw new Conflict('Discovery identity or permissions changed.');
        }
        return [$row,$terms,$input];
    }

    public function assertLiveLocked(PDO $pdo,array $row,array $terms,QuoteInput $input,string $provider,string $now): void
    {
        $q = $pdo->prepare('SELECT u.state FROM credit_usage u JOIN credit_reservation r ON r.usage_id=u.id
            WHERE u.id=? AND u.tenant_id=? AND r.tenant_id=u.tenant_id AND u.deleted=FALSE AND r.deleted=FALSE
            AND u.operation_type=? AND u.operation_key=? AND u.execution_id=? AND r.execution_id=u.execution_id AND r.state=?');
        $q->execute([$row['usage_id'],$input->data['tenantId'],'enrichment','enrichment:' . $row['id'],'enrichment:' . $row['id'],'held']);
        if ($q->fetchColumn() !== 'admitted' || $now >= self::after($row['admitted_at'],300)) throw new Conflict('Discovery acquisition is closed.');
        $available = $this->availability->inspect($input->data['tenantId'],'Account',$input->data['products'],$terms['identity'],$input->data['waterfall']);
        $product = $input->data['products'][0]; $revision = $available[$provider][$product] ?? null;
        if ($revision === null || $revision !== ($terms['availability'][$provider][$product] ?? null)) throw new Conflict('Discovery capability changed.');
    }

    private static function incidental(array $data): array
    {
        if (array_diff(array_keys($data),['email','phone'])) throw new InvalidArgumentException('Unsupported incidental fields.');
        foreach ($data as $field => &$values) {
            if (!is_array($values) || !array_is_list($values) || count($values) > 50) throw new InvalidArgumentException('Invalid incidental values.');
            foreach ($values as $value) {
                if (!is_string($value) || strlen($value) > 254 || ($field === 'email' ?
                    !filter_var($value,FILTER_VALIDATE_EMAIL) : !preg_match('/\A\+[1-9][0-9]{7,14}\z/',$value))) {
                    throw new InvalidArgumentException('Invalid incidental contact value.');
                }
            }
            $values = array_values(array_unique($values)); sort($values,SORT_STRING);
        }
        unset($values); ksort($data);
        return $data;
    }

    private static function receipt(array $row): array
    {
        return ['id' => $row['id'],'provider' => $row['provider'],'product' => $row['product'],
            'acquiredAt' => $row['acquired_at'],'expiresAt' => $row['expires_at']];
    }

    private static function after(string $time,int $seconds): string
    {
        return (new \DateTimeImmutable($time,new \DateTimeZone('UTC')))->modify("$seconds seconds")->format('Y-m-d H:i:s');
    }
}
