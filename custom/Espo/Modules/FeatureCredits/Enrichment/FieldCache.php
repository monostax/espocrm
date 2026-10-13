<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureCredits\Enrichment\Providers\{Registry,Result};
use PDO;

/** Restricted sibling material on protected attempts; never a public result projection. */
final class FieldCache
{
    public function __construct(private Crypt $crypt) {}

    public function store(PDO $pdo, array $terms, array $attempt, Result $result, Registry $providers, string $now): void
    {
        if (!$result->hidden) return;
        $fields = [];
        foreach ($result->hidden as $product => $values) {
            if ($product === $attempt['product']) throw new Conflict('Hidden field duplicates requested product.');
            $revision = $providers->get($attempt['provider'])->revision($attempt['tenant_id'], $terms['request']['targetType'], $product, $terms['identity']);
            if ($revision !== null) $fields[$product] = ['revision' => $revision, 'values' => $values];
        }
        if (!$fields) return;
        $expires = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->modify('+' . $result->retentionSeconds . ' seconds')->format('Y-m-d H:i:s');
        $payload = ['scope' => self::scope($terms), 'provider' => $attempt['provider'], 'attemptId' => $attempt['id'],
            'quoteId' => $attempt['quote_id'], 'acquiredAt' => $now, 'expiresAt' => $expires, 'fields' => $fields];
        $pdo->prepare('UPDATE enrichment_attempt SET cache_ciphertext=?,cache_hash=?,cache_expires_at=? WHERE id=?')
            ->execute([$this->crypt->encrypt(json_encode($payload, JSON_THROW_ON_ERROR)), Quotes::hash($payload), $expires, $attempt['id']]);
    }

    /** Called only at the current affordable, available step of the confirmed route. */
    public function find(PDO $pdo, array $terms, string $product, string $provider, string $revision, string $now): ?array
    {
        if ($terms['request']['refresh']) return null;
        $request = $terms['request'];
        $q = $pdo->prepare('SELECT a.* FROM enrichment_attempt a JOIN enrichment_quote q ON q.id=a.quote_id AND q.tenant_id=a.tenant_id
            WHERE a.tenant_id=? AND q.target_type=? AND q.target_id=? AND a.provider=? AND a.deleted=FALSE AND q.deleted=FALSE
            AND a.erased_at IS NULL AND q.erased_at IS NULL AND a.cache_ciphertext IS NOT NULL AND a.cache_expires_at>?
            ORDER BY a.completed_at DESC,a.id');
        $q->execute([$request['tenantId'], $request['targetType'], $request['targetId'], $provider, $now]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode($this->crypt->decrypt($row['cache_ciphertext']), true, 32, JSON_THROW_ON_ERROR);
            if (!hash_equals($row['cache_hash'] ?? '', Quotes::hash($payload)) || $payload['attemptId'] !== $row['id'] ||
                $payload['quoteId'] !== $row['quote_id'] || $payload['provider'] !== $provider ||
                $payload['acquiredAt'] !== $row['completed_at'] || $payload['expiresAt'] !== $row['cache_expires_at']) {
                throw new Conflict('Hidden field cache integrity differs.');
            }
            if ($payload['scope'] !== self::scope($terms) || ($payload['fields'][$product]['revision'] ?? null) !== $revision) continue;
            $p = $pdo->prepare("SELECT a.* FROM enrichment_attempt a JOIN enrichment_quote q ON q.id=a.quote_id AND q.tenant_id=a.tenant_id
                JOIN credit_usage u ON u.id=q.usage_id AND u.tenant_id=q.tenant_id
                WHERE a.tenant_id=? AND a.source_attempt_id=? AND a.product=? AND a.provider=?
                AND a.deleted=FALSE AND q.deleted=FALSE AND u.deleted=FALSE AND a.erased_at IS NULL
                AND a.purchased_at IS NOT NULL AND a.state='matched' AND u.state='settled' ORDER BY a.purchased_at,a.id");
            $p->execute([$request['tenantId'],$row['id'],$product,$provider]);
            if ($purchase = $p->fetch(PDO::FETCH_ASSOC)) {
                $values = json_decode($this->crypt->decrypt($purchase['result_ciphertext'] ?? ''),true,32,JSON_THROW_ON_ERROR);
                if (!hash_equals($purchase['result_hash'] ?? '',self::resultHash($request['tenantId'],$purchase['quote_id'],
                    $purchase['id'],$provider,$product,$values,$purchase))) throw new Conflict('Existing cached purchase integrity differs.');
                return ['purchaseId' => $purchase['id']];
            }
            return ['values' => $payload['fields'][$product]['values'], 'sourceAttemptId' => $row['id'],
                'acquisitionQuoteId' => $row['quote_id'], 'acquiredAt' => $payload['acquiredAt'], 'expiresAt' => $payload['expiresAt']];
        }
        return null;
    }

    public static function resultHash(string $tenant, string $quote, string $id, string $provider, string $product, array $values, array $row = []): string
    {
        $binding = [$tenant, $quote, $id, $provider, $product, $values];
        if (($row['source_attempt_id'] ?? null) !== null) $binding[] = [$row['source_attempt_id'], $row['acquisition_quote_id'], $row['acquired_at']];
        return Quotes::hash($binding);
    }

    private static function scope(array $terms): string
    {
        $request = $terms['request'];
        return Quotes::hash([$request['tenantId'], $request['targetType'], $request['targetId'], $terms['identity']]);
    }
}
