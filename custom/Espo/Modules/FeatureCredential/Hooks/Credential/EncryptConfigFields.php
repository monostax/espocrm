<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredential\Hooks\Credential;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialConfigCipher;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use stdClass;
use Throwable;

/**
 * Encrypts secret fields inside `Credential.config` before persisting.
 *
 * # Why this hook exists
 *
 * `CredentialType.encryptionFields` was historically declarative-only — no
 * code path actually encrypted these fields at rest, even though the seeders
 * carefully labelled `password`, `accessToken`, `refreshToken`, `clientSecret`,
 * etc. as secrets. This hook closes that gap.
 *
 * # When it runs
 *
 *   - On every Credential CREATE (config carries fresh user-submitted secrets)
 *   - On every Credential UPDATE (config may include rotated/edited secrets)
 *
 * # Idempotency
 *
 * {@see CredentialConfigCipher::encryptFields()} skips values that already
 * carry the encryption marker (`enc:v1:`). So:
 *
 *   - Multiple saves in one request → second save is a no-op for secret fields
 *   - Round-trip API GET then PUT without modifying secret fields → the
 *     ConfigLoader-decrypted value is re-encrypted, marker preserved on disk
 *
 * # Backward compatibility
 *
 * Legacy rows (pre-hook) have plaintext `config`. They keep working through
 * the transparent decrypt-on-read path in {@see CredentialConfigCipher::decryptFields()},
 * and get encrypted lazily the next time they're saved. No batch migration
 * is required.
 *
 * # Order
 *
 * Runs at priority 5 — same as the existing `EncryptSigningSecret` pattern
 * used elsewhere (e.g. CalCom). Early enough to precede generic validation
 * hooks but late enough that any normalising hook can run first.
 *
 * @implements BeforeSave<Entity>
 */
class EncryptConfigFields implements BeforeSave
{
    public static int $order = 5;

    public function __construct(
        private CredentialConfigCipher $cipher,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        // Only re-encrypt when config actually changed in this save. Otherwise
        // we'd waste CPU + risk re-encrypting an OAuth-merged config (where
        // ConfigLoader transiently injected live tokens at read time).
        if (!$entity->isAttributeChanged('config')) {
            return;
        }

        $raw = $entity->get('config');

        if (!is_string($raw) || $raw === '') {
            return;
        }

        $decoded = json_decode($raw);

        if (!$decoded instanceof stdClass) {
            // Malformed JSON: leave it alone. validateConfig() / the schema
            // pipeline will surface the error to the user.
            return;
        }

        $credentialType = $this->cipher->loadCredentialType(
            (string) ($entity->get('credentialTypeId') ?? ''),
        );

        try {
            $encrypted = $this->cipher->encryptFields($decoded, $credentialType);
        } catch (Throwable) {
            // Defensive: any unexpected failure must not block the save.
            // Cipher already logged the inner cause.
            return;
        }

        $entity->set('config', json_encode($encrypted));
    }
}
