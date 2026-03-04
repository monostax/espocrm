<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokensProvider;

/**
 * Virtual RecordService for WhatsAppBusinessAccountWebhook entity.
 *
 * Fetches subscribed apps (webhook configuration) for a WABA
 * via GET /<WABA_ID>/subscribed_apps on the Meta Graph API.
 */
class WhatsAppBusinessAccountWebhook
{
    public const ENTITY_TYPE = 'WhatsAppBusinessAccountWebhook';

    private const DEFAULT_API_VERSION = 'v21.0';

    public function __construct(
        private EntityManager $entityManager,
        private MetaGraphApiClient $apiClient,
        private TokensProvider $tokensProvider,
        private WhatsAppOAuthHelper $oAuthHelper,
        private Log $log,
        private Acl $acl,
    ) {}

    /**
     * Find all subscribed apps (webhooks) for a given WABA.
     *
     * @param string $oAuthAccountId The OAuthAccount ID
     * @param string $businessAccountId The WABA ID
     * @return RecordCollection
     * @throws Error
     * @throws Forbidden
     */
    public function find(string $oAuthAccountId, string $businessAccountId): RecordCollection
    {
        if (!$this->acl->checkScope('WhatsAppBusinessAccount', 'read')) {
            throw new Forbidden("No read access to WhatsAppBusinessAccount.");
        }

        try {
            $this->oAuthHelper->validateOAuthAccountAccess($oAuthAccountId);
        } catch (NotFound $e) {
            $this->log->warning(
                "WhatsAppBusinessAccountWebhook: OAuthAccount {$oAuthAccountId} not found, returning empty result."
            );

            return RecordCollection::create(
                $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE),
                0
            );
        }

        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $totalCount = 0;

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
        } catch (\Throwable $e) {
            $this->log->warning(
                "WhatsAppBusinessAccountWebhook: Failed to get tokens for OAuthAccount {$oAuthAccountId}: " . $e->getMessage()
            );

            return RecordCollection::create($collection, 0);
        }

        $accessToken = $tokens->getAccessToken();

        if (!$accessToken || !$businessAccountId) {
            $this->log->warning(
                "WhatsAppBusinessAccountWebhook: OAuthAccount {$oAuthAccountId} missing accessToken or businessAccountId."
            );

            return RecordCollection::create($collection, 0);
        }

        try {
            $subscribedApps = $this->apiClient->getSubscribedApps(
                $accessToken,
                $businessAccountId,
                self::DEFAULT_API_VERSION
            );

            foreach ($subscribedApps as $appData) {
                $entity = $this->mapToEntity($appData, $oAuthAccountId, $businessAccountId);
                $collection->append($entity);
                $totalCount++;
            }
        } catch (Error $e) {
            $this->log->error(
                "WhatsAppBusinessAccountWebhook: Failed to fetch subscribed apps for WABA {$businessAccountId}: " . $e->getMessage()
            );
        }

        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * Map a subscribed app from the Meta API response to an EspoCRM entity.
     *
     * API response shape per entry:
     * {
     *   "whatsapp_business_api_data": { "id": "...", "link": "...", "name": "..." },
     *   "override_callback_uri": "https://..."
     * }
     */
    private function mapToEntity(
        array $data,
        string $oAuthAccountId,
        string $businessAccountId,
    ): Entity {
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $appInfo = $data['whatsapp_business_api_data'] ?? [];
        $appId = $appInfo['id'] ?? '';

        $entity->set('id', $oAuthAccountId . '_' . $businessAccountId . '_' . $appId);
        $entity->set('name', $appInfo['name'] ?? '');
        $entity->set('appId', $appId);
        $entity->set('overrideCallbackUri', $data['override_callback_uri'] ?? null);

        $entity->setAsFetched();

        return $entity;
    }
}
