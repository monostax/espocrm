<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDataset;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Encrypts password-type secrets on MetaCapiDataset at rest.
 *
 * Espo does NOT auto-encrypt `password` fields; the convention (see
 * FeatureMetaInstagram\Services\ChatwootWebhookConfigService) is to encrypt
 * manually via Crypt before saving.
 *
 * Each field is only re-encrypted when the user actually changes it.
 * (Unchanged saves keep the existing ciphertext.)
 *
 * @implements BeforeSave<MetaCapiDataset>
 */
class EncryptAccessToken implements BeforeSave
{
    public static int $order = 5;

    /** Sentinel returned by Espo for masked password fields when nothing was typed. */
    private const MASKED_VALUE_PLACEHOLDER = '********';

    /** All password-type fields on the entity that must be encrypted at rest. */
    private const ENCRYPTED_FIELDS = [
        'accessToken',
    ];

    public function __construct(
        private Crypt $crypt,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaCapiDataset) {
            return;
        }

        foreach (self::ENCRYPTED_FIELDS as $field) {
            $this->encryptField($entity, $field);
        }
    }

    private function encryptField(MetaCapiDataset $entity, string $field): void
    {
        if (!$entity->isAttributeChanged($field)) {
            return;
        }

        $value = $entity->get($field);

        // User did not change the value — restore previous ciphertext.
        if ($value === null || $value === '' || $value === self::MASKED_VALUE_PLACEHOLDER) {
            $fetched = $entity->getFetched($field);
            $entity->set($field, $fetched);

            return;
        }

        $entity->set($field, $this->crypt->encrypt((string) $value));
    }
}
