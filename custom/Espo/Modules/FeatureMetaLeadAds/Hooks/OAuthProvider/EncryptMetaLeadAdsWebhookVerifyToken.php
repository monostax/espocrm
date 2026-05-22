<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Hooks\OAuthProvider;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Crypt;
use Espo\Entities\OAuthProvider;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Encrypts `webhookVerifyToken` on OAuthProvider rows whose `provider` is
 * `meta-leadads` when the admin pastes a new value via the UI.
 *
 * Espo's core does NOT auto-encrypt the custom `webhookVerifyToken` field
 * (it was added by FeatureMetaInstagram as type=password but encryption is
 * application-level). FeatureMetaInstagram has its own server-side action
 * that GENERATES a random token and stores ciphertext — it never accepts a
 * user-pasted value.
 *
 * For Lead Ads we let the admin paste the verify token directly (it must
 * match what they configure in the Meta App Dashboard → Webhooks). This
 * hook makes that paste path safe by encrypting on save.
 *
 * Scoped to provider=='meta-leadads' so it cannot collide with the
 * Instagram flow which manually encrypts before calling save (a global
 * hook would double-encrypt that path).
 *
 * @implements BeforeSave<OAuthProvider>
 */
class EncryptMetaLeadAdsWebhookVerifyToken implements BeforeSave
{
    public static int $order = 5;

    private const MASKED_VALUE_PLACEHOLDER = '********';
    private const PROVIDER_DISCRIMINATOR = 'meta-leadads';

    public function __construct(
        private Crypt $crypt,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof OAuthProvider) {
            return;
        }

        // Only act on Meta Lead Ads providers.
        if ($entity->get('provider') !== self::PROVIDER_DISCRIMINATOR) {
            return;
        }

        if (!$entity->isAttributeChanged('webhookVerifyToken')) {
            return;
        }

        $value = $entity->get('webhookVerifyToken');

        // Empty or masked => user didn't change anything; restore fetched.
        if ($value === null || $value === '' || $value === self::MASKED_VALUE_PLACEHOLDER) {
            $entity->set('webhookVerifyToken', $entity->getFetched('webhookVerifyToken'));

            return;
        }

        $entity->set('webhookVerifyToken', $this->crypt->encrypt((string) $value));
    }
}
