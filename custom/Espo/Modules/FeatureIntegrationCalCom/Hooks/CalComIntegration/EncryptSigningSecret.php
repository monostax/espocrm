<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Hooks\CalComIntegration;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureIntegrationCalCom\Entities\CalComIntegration;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Encrypts password-type secrets on CalComIntegration at rest.
 *
 * Same pattern as FeatureMetaConversionsApi: Espo does NOT auto-encrypt
 * `password` fields; we encrypt manually via Crypt.
 *
 * @implements BeforeSave<CalComIntegration>
 */
class EncryptSigningSecret implements BeforeSave
{
    public static int $order = 5;

    private const MASKED_VALUE_PLACEHOLDER = '********';

    private const ENCRYPTED_FIELDS = [
        'signingSecret',
    ];

    public function __construct(
        private Crypt $crypt,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof CalComIntegration) {
            return;
        }

        foreach (self::ENCRYPTED_FIELDS as $field) {
            $this->encryptField($entity, $field);
        }
    }

    private function encryptField(CalComIntegration $entity, string $field): void
    {
        if (!$entity->isAttributeChanged($field)) {
            return;
        }

        $value = $entity->get($field);

        if ($value === null || $value === '' || $value === self::MASKED_VALUE_PLACEHOLDER) {
            $fetched = $entity->getFetched($field);
            $entity->set($field, $fetched);

            return;
        }

        $entity->set($field, $this->crypt->encrypt((string) $value));
    }
}
