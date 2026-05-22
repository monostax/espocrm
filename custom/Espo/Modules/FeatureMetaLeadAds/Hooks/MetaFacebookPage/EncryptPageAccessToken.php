<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Hooks\MetaFacebookPage;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Crypt;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaFacebookPage;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Encrypts `pageAccessToken` on MetaFacebookPage at rest.
 *
 * Espo does NOT auto-encrypt `password` fields; convention is to encrypt
 * manually via Crypt in a beforeSave hook (see
 * FeatureIntegrationCalCom\Hooks\CalComIntegration\EncryptSigningSecret for
 * the equivalent pattern).
 *
 * The Page Access Token is typically NOT entered by hand — it's obtained
 * via PageSyncService after the user OAuths the `meta-leadads` provider.
 * Even so, the field is exposed as `password` in the UI in case admin
 * needs to paste/rotate a token manually.
 *
 * @implements BeforeSave<MetaFacebookPage>
 */
class EncryptPageAccessToken implements BeforeSave
{
    public static int $order = 5;

    /** Sentinel returned by Espo for masked password fields when nothing was typed. */
    private const MASKED_VALUE_PLACEHOLDER = '********';

    /** All password-type fields on the entity that must be encrypted at rest. */
    private const ENCRYPTED_FIELDS = [
        'pageAccessToken',
    ];

    public function __construct(
        private Crypt $crypt,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaFacebookPage) {
            return;
        }

        foreach (self::ENCRYPTED_FIELDS as $field) {
            $this->encryptField($entity, $field);
        }
    }

    private function encryptField(MetaFacebookPage $entity, string $field): void
    {
        if (!$entity->isAttributeChanged($field)) {
            return;
        }

        $value = $entity->get($field);

        // Empty or masked-placeholder => user didn't change anything; restore fetched.
        if ($value === null || $value === '' || $value === self::MASKED_VALUE_PLACEHOLDER) {
            $fetched = $entity->getFetched($field);
            $entity->set($field, $fetched);

            return;
        }

        $entity->set($field, $this->crypt->encrypt((string) $value));
    }
}
