<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Hooks\TrackingSource;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Encrypts TrackingSource secrets at rest.
 *
 * Espo does NOT auto-encrypt `password` fields; convention is to encrypt
 * manually via Crypt in a beforeSave ORM hook. Mirror of
 * `Espo\Modules\FeatureMetaLeadAds\Hooks\MetaFacebookPage\EncryptPageAccessToken`.
 *
 * Covers:
 *   - signingSecret              (HMAC for the trusted ingest path)
 *   - identityVerificationSecret (HMAC for verified identify() on public path)
 *
 * Masked-placeholder handling: the companion OutputFilter replaces stored
 * secrets with '********' on read, so an untouched edit form round-trips
 * that sentinel — in which case the fetched (already encrypted) value is
 * restored instead of double-encrypting the mask.
 *
 * @implements BeforeSave<TrackingSource>
 */
class EncryptSecrets implements BeforeSave
{
    public static int $order = 5;

    /** Sentinel used for masked password fields when nothing was typed. */
    private const MASKED_VALUE_PLACEHOLDER = '********';

    /** All password-type fields on the entity that must be encrypted at rest. */
    private const ENCRYPTED_FIELDS = [
        'signingSecret',
        'identityVerificationSecret',
    ];

    public function __construct(
        private Crypt $crypt,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof TrackingSource) {
            return;
        }

        foreach (self::ENCRYPTED_FIELDS as $field) {
            $this->encryptField($entity, $field);
        }
    }

    private function encryptField(TrackingSource $entity, string $field): void
    {
        if (!$entity->isAttributeChanged($field)) {
            return;
        }

        $value = $entity->get($field);

        // Masked-placeholder => user didn't change anything; restore fetched.
        if ($value === self::MASKED_VALUE_PLACEHOLDER) {
            $entity->set($field, $entity->getFetched($field));

            return;
        }

        // Empty => explicit clear (allowed; validation hook enforces
        // kind-based requirements separately).
        if ($value === null || $value === '') {
            return;
        }

        $entity->set($field, $this->crypt->encrypt((string) $value));
    }
}
