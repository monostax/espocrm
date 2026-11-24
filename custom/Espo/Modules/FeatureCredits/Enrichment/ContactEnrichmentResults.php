<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\RecordId;
use Espo\Modules\FeatureCredits\Verification\BrazilianPhone;
use Espo\Modules\FeatureCredits\Verification\IdentityInput;
use Espo\Modules\FeatureCredits\Verification\Kind;
use Espo\Modules\FeatureCredits\Verification\Operations;
use Espo\Modules\FeatureCredits\Verification\TwilioLookup;
use Espo\Modules\FeatureCredits\Verification\WhatsAppPhone;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * One row per email or phone that enrichment found for a Contact, for record queries
 * (`enrichmentResults.any(type = "email" AND valid = true)`):
 *  - type   email | phone
 *  - valid  the latest completed check of that value, still within its 90-day retention, passed: email deliverable;
 *           phone registered on WhatsApp or identity verified (first name or date of birth matched)
 *  - added  the value is one of the Contact's channel identities (email, WhatsApp, SMS) not marked as someone else's
 *
 * Rows never hold the value: purchased results stay encrypted in their receipts and a row carries a keyed hash of the
 * normalized value only. Rows are recomputed from the sources whenever one changes (purchase, verification, retention
 * expiry, channel identity), so a row is a projection, never a record of its own.
 */
class ContactEnrichmentResults
{
    private const TABLE = 'contact_enrichment_result';

    public function __construct(
        private EntityManager $em,
        private Crypt $crypt,
        private Config $config,
        private Log $log,
    ) {}

    /** Recompute some Contacts; never fails the caller (a later change or the rebuild backfill catches up). */
    public function refreshQuietly(array $contactIds): void
    {
        foreach (array_unique(array_filter($contactIds, 'is_string')) as $contactId) {
            try {
                $this->refresh($contactId);
            } catch (Throwable $e) {
                $this->log->warning('Contact enrichment results refresh failed for ' . $contactId . ': ' . $e->getMessage());
            }
        }
    }

    /** Whether a Contact has rows: a channel identity change only matters then. */
    public function hasRows(string $contactId): bool
    {
        $q = $this->em->getPDO()->prepare('SELECT 1 FROM ' . self::TABLE . ' WHERE contact_id = ? LIMIT 1');
        $q->execute([$contactId]);
        return (bool) $q->fetchColumn();
    }

    public function refresh(string $contactId): void
    {
        $pdo = $this->em->getPDO();
        $q = $pdo->prepare('SELECT tenant_id FROM contact WHERE id = ? AND deleted = FALSE');
        $q->execute([$contactId]);
        $tenantId = $q->fetchColumn();
        $rows = is_string($tenantId) && $tenantId !== '' ? $this->rows($pdo, $tenantId, $contactId) : [];
        $this->em->getTransactionManager()->run(function () use ($pdo, $contactId, $tenantId, $rows): void {
            $pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE contact_id = ?')->execute([$contactId]);
            $insert = $pdo->prepare('INSERT INTO ' . self::TABLE . ' (id, tenant_id, contact_id, type, value_hash, valid, added, found_at, deleted)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $row) {
                $insert->execute([RecordId::generate(), $tenantId, $contactId, $row['type'], $row['hash'],
                    (int) $row['valid'], (int) $row['added'], $row['foundAt'], 0]);
            }
        });
    }

    /**
     * Contacts with purchased email/phone results but no rows yet (first deploy, or a refresh that failed).
     *
     * @return int Contacts refreshed.
     */
    public function backfill(int $limit = 5000): int
    {
        $q = $this->em->getPDO()->prepare("SELECT DISTINCT q.target_id FROM enrichment_attempt a
            JOIN enrichment_quote q ON q.id = a.quote_id AND q.tenant_id = a.tenant_id
            WHERE q.target_type = 'Contact' AND a.product IN ('email', 'phone') AND a.state = 'matched'
            AND a.purchased_at IS NOT NULL AND a.erased_at IS NULL AND a.deleted = FALSE AND a.result_ciphertext IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM " . self::TABLE . " r WHERE r.contact_id = q.target_id)
            LIMIT " . max(1, $limit));
        $q->execute();
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
        $this->refreshQuietly($ids);
        return count($ids);
    }

    /** @return list<array{type: string, hash: string, valid: bool, added: bool, foundAt: string}> */
    private function rows(PDO $pdo, string $tenantId, string $contactId): array
    {
        $found = $this->found($pdo, $tenantId, $contactId);
        if (!$found) return [];
        $key = IdentityInput::hmacKey((string) $this->config->get('cryptKey', 'feature-credits-default-key'));
        $outcomes = $this->outcomes($pdo, $tenantId, $contactId);
        [$emails, $phones] = $this->channels($pdo, $tenantId, $contactId);
        $rows = [];
        foreach ($found as $item) {
            if ($item['type'] === 'email') {
                $subject = hash_hmac('sha256', Operations::hash([Kind::EMAIL, $tenantId, $contactId, $item['value']]), $key);
                $valid = ($outcomes[Kind::EMAIL][$subject] ?? null) === true;
                $added = isset($emails[$item['value']]);
            } else {
                $valid = false;
                if ($item['whatsapp'] !== null) {
                    $subject = hash_hmac('sha256', Operations::hash([Kind::WHATSAPP, $tenantId, $contactId, $item['whatsapp']]), $key);
                    $valid = ($outcomes[Kind::WHATSAPP][$subject] ?? null) === true;
                }
                if (!$valid && $item['brazil'] !== null) {
                    $valid = ($outcomes[Kind::PHONE][IdentityInput::subjectHash($key, $tenantId, $contactId, $item['brazil'])] ?? null) === true;
                }
                $added = ($item['whatsapp'] !== null && isset($phones[$item['whatsapp']])) ||
                    ($item['brazil'] !== null && isset($phones[$item['brazil']]));
            }
            $rows[] = [
                'type' => $item['type'],
                'hash' => hash_hmac('sha256', json_encode(['contact-enrichment-result-v1', $tenantId, $contactId, $item['type'], $item['value']], JSON_THROW_ON_ERROR), $key),
                'valid' => $valid,
                'added' => $added,
                'foundAt' => $item['foundAt'],
            ];
        }
        return $rows;
    }

    /**
     * Normalized values from the Contact's purchased email/phone receipts, one per value.
     *
     * @return array<string, array{type: string, value: string, whatsapp: ?string, brazil: ?string, foundAt: string}>
     */
    private function found(PDO $pdo, string $tenantId, string $contactId): array
    {
        $q = $pdo->prepare("SELECT a.*, q.id AS receipt_quote_id FROM enrichment_attempt a
            JOIN enrichment_quote q ON q.id = a.quote_id AND q.tenant_id = a.tenant_id AND q.deleted = FALSE
            WHERE a.tenant_id = ? AND q.target_type = 'Contact' AND q.target_id = ? AND a.product IN ('email', 'phone')
            AND a.state = 'matched' AND a.purchased_at IS NOT NULL AND a.erased_at IS NULL AND a.deleted = FALSE
            AND a.result_ciphertext IS NOT NULL ORDER BY a.purchased_at, a.id");
        $q->execute([$tenantId, $contactId]);
        $found = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $attempt) {
            $values = json_decode($this->crypt->decrypt($attempt['result_ciphertext']), true, 32);
            // Same integrity binding verification checks before it trusts a purchased value.
            if (!is_array($values) || !hash_equals((string) ($attempt['result_hash'] ?? ''), FieldCache::resultHash(
                $tenantId, $attempt['receipt_quote_id'], $attempt['id'], (string) $attempt['provider'], $attempt['product'], $values, $attempt,
            ))) {
                continue;
            }
            foreach (array_values($values) as $raw) {
                if (!is_string($raw) || trim($raw) === '') continue;
                if ($attempt['product'] === 'email') {
                    $value = Kind::email($raw);
                    $whatsapp = $brazil = null;
                } else {
                    $whatsapp = WhatsAppPhone::e164($raw);
                    $brazil = BrazilianPhone::e164($raw);
                    $value = $whatsapp ?? $brazil;
                }
                if ($value === null) continue;
                $found[$attempt['product'] . ':' . $value] ??= ['type' => $attempt['product'], 'value' => $value,
                    'whatsapp' => $whatsapp, 'brazil' => $brazil, 'foundAt' => (string) $attempt['purchased_at']];
            }
        }
        return $found;
    }

    /**
     * Whether the latest completed check of each value passed, by kind and subject hash. Checks past their retention
     * (or erased) no longer count, as in the Contact's verification badges.
     *
     * @return array<string, array<string, bool>>
     */
    private function outcomes(PDO $pdo, string $tenantId, string $contactId): array
    {
        $q = $pdo->prepare("SELECT id, verification_kind, subject_hash, outcome, evidence_ciphertext, evidence_hash
            FROM phone_verification_operation
            WHERE tenant_id = ? AND contact_id = ? AND state = 'completed' AND deleted = FALSE AND erased_at IS NULL
            AND retention_expires_at > ? AND evidence_ciphertext IS NOT NULL
            ORDER BY completed_at DESC, id DESC");
        $q->execute([$tenantId, $contactId, gmdate('Y-m-d H:i:s')]);
        $outcomes = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $op) {
            $kind = $op['verification_kind'] ?: Kind::PHONE;
            if (isset($outcomes[$kind][$op['subject_hash']])) continue;
            $outcomes[$kind][$op['subject_hash']] = match ($kind) {
                Kind::EMAIL => $op['outcome'] === 'deliverable',
                Kind::WHATSAPP => $op['outcome'] === 'registered',
                default => $this->phoneVerified($tenantId, $op),
            };
        }
        return $outcomes;
    }

    /** The phone match rule badges apply: first name or date of birth matched exactly, from the retained evidence. */
    private function phoneVerified(string $tenantId, array $op): bool
    {
        if (!in_array($op['outcome'], ['verified', 'not_verified'], true)) return false;
        $evidence = json_decode($this->crypt->decrypt($op['evidence_ciphertext']), true, 16);
        if (!is_array($evidence) || !hash_equals((string) $op['evidence_hash'], Operations::evidenceHash($tenantId, $op['id'], $op['outcome'], $evidence))) {
            return false;
        }
        return TwilioLookup::hasExactFirstNameOrDobMatch($evidence['fieldOutcomes'] ?? null);
    }

    /** @return array{array<string, true>, array<string, true>} normalized emails and phones the Contact has as channels */
    private function channels(PDO $pdo, string $tenantId, string $contactId): array
    {
        $q = $pdo->prepare("SELECT channel_type, source_id FROM contact_channel_identity
            WHERE tenant_id = ? AND contact_id = ? AND deleted = FALSE AND channel_type IN ('email', 'whatsapp', 'sms')
            AND (ownership_status IS NULL OR ownership_status <> 'rejected')");
        $q->execute([$tenantId, $contactId]);
        $emails = [];
        $phones = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $identity) {
            if ($identity['channel_type'] === 'email') {
                $email = Kind::email($identity['source_id']);
                if ($email !== null) $emails[$email] = true;
                continue;
            }
            foreach ([WhatsAppPhone::e164($identity['source_id']), BrazilianPhone::e164($identity['source_id'])] as $phone) {
                if ($phone !== null) $phones[$phone] = true;
            }
        }
        return [$emails, $phones];
    }
}
