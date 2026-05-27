<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredential\Tools\Credential;

use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * Applies field-level encryption/decryption/redaction to a Credential's
 * `config` JSON, driven by the {@see \Espo\Modules\FeatureCredential\Entities\CredentialType}'s
 * declarative `encryptionFields` (JSON array of field names).
 *
 * # Design
 *
 * Until now, `CredentialType.encryptionFields` was declarative only — no code
 * path actually encrypted secrets at rest. This service closes that gap.
 *
 * ## Encryption marker
 *
 * Encrypted values are prefixed with {@see self::MARKER} (`enc:v1:`). This
 * gives us two important properties:
 *
 *   1. **Idempotency**: re-encrypting an already-encrypted value is a no-op.
 *      Safe to call from BeforeSave hooks where the same entity may be
 *      saved multiple times in one request.
 *
 *   2. **Transparent backward compatibility**: legacy rows whose `config`
 *      pre-dates this hook are stored as plaintext. {@see decryptFields()}
 *      detects the absence of the marker and returns the raw value. So
 *      existing Credentials keep working immediately; they get encrypted
 *      lazily on next save (e.g. via the Service::update path, or via an
 *      explicit rotate()).
 *
 * ## Redaction
 *
 * {@see redactConfig()} replaces secret fields with `'***'` for use in
 * `CredentialHistory.previousValue` / `newValue`. The history table is
 * intended for "who changed what when" audit — exact secret values do not
 * belong there, and the encryption pipeline would only compound the
 * blast-radius if a history row leaked.
 *
 * ## OAuth interaction
 *
 * OAuth-sourced fields (those that {@see ConfigLoader} overwrites at read
 * time with live tokens from {@see \Espo\Tools\OAuth\TokensProvider}) MAY
 * also be listed in `encryptionFields` (e.g. the `oauth2` system type lists
 * `accessToken`/`refreshToken`/`clientSecret`). For those fields:
 *
 *   - Encryption on save: still happens. If the user persists tokens via
 *     manual config (no oAuthAccountId), the tokens are encrypted at rest.
 *   - Read-time decryption: still happens. ConfigLoader then OVERWRITES the
 *     decrypted token with the fresh value from TokensProvider when an
 *     OAuthAccount is linked. The decryption pass therefore is wasted work
 *     in the OAuth-linked case but never produces a wrong value.
 *
 * # Safety
 *
 * Encryption/decryption errors are non-fatal: the value is left as-is and
 * a warning logged. We refuse to throw inside a CRUD path because failing
 * a credential save mid-flight is worse than a temporarily un-encrypted
 * value (which gets caught on the next regular save).
 */
class CredentialConfigCipher
{
    /** Prefix marking a value as encrypted by this service. */
    public const MARKER = 'enc:v1:';

    /** Placeholder used by {@see redactConfig()} for secret fields. */
    public const REDACTED_PLACEHOLDER = '***';

    public function __construct(
        private Crypt $crypt,
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    /**
     * Encrypts the secret fields of $config in-place (returns a new object).
     *
     * Skips:
     *   - Fields not listed in $credentialType.encryptionFields
     *   - Fields whose value is null / '' / already marked encrypted
     *   - Non-string values (we don't recurse into nested objects/arrays;
     *     CredentialType schemas mark only scalar string fields as secret)
     */
    public function encryptFields(stdClass $config, ?Entity $credentialType): stdClass
    {
        $fields = $this->getEncryptionFields($credentialType);

        if (empty($fields)) {
            return $config;
        }

        $result = clone $config;

        foreach ($fields as $field) {
            if (!isset($result->{$field})) {
                continue;
            }

            $value = $result->{$field};

            if (!is_string($value) || $value === '') {
                continue;
            }

            if ($this->isMarkedEncrypted($value)) {
                continue;
            }

            try {
                $result->{$field} = self::MARKER . $this->crypt->encrypt($value);
            } catch (Throwable $e) {
                $this->log->warning(
                    "CredentialConfigCipher: failed to encrypt field '{$field}': {$e->getMessage()}",
                );
            }
        }

        return $result;
    }

    /**
     * Decrypts the secret fields of $config in-place (returns a new object).
     *
     * Only fields with the {@see self::MARKER} prefix are decrypted; plaintext
     * legacy values pass through unchanged.
     */
    public function decryptFields(stdClass $config, ?Entity $credentialType): stdClass
    {
        $fields = $this->getEncryptionFields($credentialType);

        if (empty($fields)) {
            return $config;
        }

        $result = clone $config;

        foreach ($fields as $field) {
            if (!isset($result->{$field})) {
                continue;
            }

            $value = $result->{$field};

            if (!is_string($value) || !$this->isMarkedEncrypted($value)) {
                continue;
            }

            $cipherText = substr($value, strlen(self::MARKER));

            try {
                $result->{$field} = $this->crypt->decrypt($cipherText);
            } catch (Throwable $e) {
                $this->log->warning(
                    "CredentialConfigCipher: failed to decrypt field '{$field}': {$e->getMessage()}",
                );

                // Leave as-is (still marker-prefixed) — caller will see a
                // corrupt-looking value and surface it as an error,
                // rather than us silently returning empty.
            }
        }

        return $result;
    }

    /**
     * Returns a JSON string with secret fields replaced by {@see self::REDACTED_PLACEHOLDER}.
     *
     * Accepts the same input shapes the rest of the codebase uses for `config`
     * (string JSON / stdClass / null). Returns null when input is null or
     * unparseable.
     */
    public function redactConfig(string|stdClass|null $config, ?Entity $credentialType): ?string
    {
        if ($config === null || $config === '') {
            return null;
        }

        if (is_string($config)) {
            $decoded = json_decode($config);
            if (!$decoded instanceof stdClass) {
                // Malformed JSON: redact entirely to avoid leaking anything.
                return self::REDACTED_PLACEHOLDER;
            }

            $config = $decoded;
        }

        $fields = $this->getEncryptionFields($credentialType);

        if (empty($fields)) {
            return json_encode($config);
        }

        $result = clone $config;

        foreach ($fields as $field) {
            if (isset($result->{$field}) && $result->{$field} !== null && $result->{$field} !== '') {
                $result->{$field} = self::REDACTED_PLACEHOLDER;
            }
        }

        return json_encode($result);
    }

    /**
     * Loads a CredentialType by id and returns it, or null on miss.
     *
     * Helper used by callers that have only a credentialTypeId.
     */
    public function loadCredentialType(?string $credentialTypeId): ?Entity
    {
        if (!$credentialTypeId) {
            return null;
        }

        return $this->entityManager->getEntityById('CredentialType', $credentialTypeId);
    }

    /**
     * Parses {@see CredentialType::encryptionFields} into a list<string>.
     *
     * Tolerant of:
     *   - null / missing → empty list
     *   - string JSON → decoded
     *   - already-decoded array
     *
     * @return list<string>
     */
    private function getEncryptionFields(?Entity $credentialType): array
    {
        if (!$credentialType) {
            return [];
        }

        $raw = $credentialType->get('encryptionFields');

        if (!$raw) {
            return [];
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (!is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded as $field) {
            if (is_string($field) && $field !== '') {
                $out[] = $field;
            }
        }

        return $out;
    }

    private function isMarkedEncrypted(string $value): bool
    {
        return str_starts_with($value, self::MARKER);
    }
}
