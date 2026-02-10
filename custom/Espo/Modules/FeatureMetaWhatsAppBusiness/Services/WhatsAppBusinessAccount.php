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
use stdClass;

/**
 * Virtual RecordService for WhatsAppBusinessAccount entity.
 *
 * Fetches WhatsApp Business Account data from the Meta Graph API
 * instead of a local database. Uses OAuthAccount entities for
 * authentication, with dynamic WABA discovery.
 */
class WhatsAppBusinessAccount
{
    public const ENTITY_TYPE = 'WhatsAppBusinessAccount';

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
     * Find WhatsApp Business Accounts from one, many, or all OAuthAccounts.
     *
     * Discovers businesses via GET /me/businesses, then fetches WABAs
     * for each business via GET /{businessId}/owned_whatsapp_business_accounts.
     *
     * @param string|null $oAuthAccountId If provided, fetches from this OAuthAccount only.
     * @param string[]|null $oAuthAccountIds If provided, fetches from these OAuthAccounts.
     *                                       Takes precedence over $oAuthAccountId when both are set.
     *                                       When neither is provided, iterates all accessible OAuthAccounts.
     * @return RecordCollection
     * @throws Error
     * @throws Forbidden
     */
    public function find(?string $oAuthAccountId = null, ?array $oAuthAccountIds = null): RecordCollection
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to WhatsAppBusinessAccount.");
        }

        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $totalCount = 0;

        if ($oAuthAccountIds) {
            $oAuthAccounts = array_map(
                fn (string $id) => $this->oAuthHelper->validateOAuthAccountAccess($id),
                $oAuthAccountIds
            );
        } elseif ($oAuthAccountId) {
            $oAuthAccounts = [$this->oAuthHelper->validateOAuthAccountAccess($oAuthAccountId)];
        } else {
            $oAuthAccounts = $this->oAuthHelper->getAccessibleOAuthAccounts();
        }

        foreach ($oAuthAccounts as $oAuthAccount) {
            $accountId = $oAuthAccount->getId();
            $accountName = $oAuthAccount->get('name');

            try {
                $tokens = $this->tokensProvider->get($accountId);
            } catch (\Throwable $e) {
                $this->log->warning(
                    "WhatsAppBusinessAccount: Failed to get tokens for OAuthAccount {$accountId}: " . $e->getMessage()
                );

                continue;
            }

            $accessToken = $tokens->getAccessToken();

            if (!$accessToken) {
                $this->log->warning(
                    "WhatsAppBusinessAccount: OAuthAccount {$accountId} has no access token, skipping."
                );

                continue;
            }

            try {
                // Discover businesses accessible to the token.
                $businesses = $this->apiClient->discoverBusinesses($accessToken, self::DEFAULT_API_VERSION);

                foreach ($businesses as $business) {
                    $businessId = $business['id'] ?? null;

                    if (!$businessId) {
                        continue;
                    }

                    try {
                        // Discover WABAs owned by each business.
                        $wabas = $this->apiClient->discoverWabas($accessToken, $businessId, self::DEFAULT_API_VERSION);

                        foreach ($wabas as $wabaData) {
                            $entity = $this->mapAccountToEntity($wabaData, $accountId, $accountName);
                            $collection->append($entity);
                            $totalCount++;
                        }
                    } catch (Error $e) {
                        $this->log->warning(
                            "WhatsAppBusinessAccount: Failed to discover WABAs for business {$businessId}: " . $e->getMessage()
                        );
                    }
                }
            } catch (Error $e) {
                $this->log->error(
                    "WhatsAppBusinessAccount: Failed to discover businesses for OAuthAccount {$accountId}: " . $e->getMessage()
                );
            }
        }

        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * Read a single WhatsApp Business Account.
     *
     * @param string $oAuthAccountId The OAuthAccount ID
     * @param string $wabaId The WhatsApp Business Account ID
     * @return stdClass Entity value map
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function read(string $oAuthAccountId, string $wabaId): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to WhatsAppBusinessAccount.");
        }

        $oAuthAccount = $this->oAuthHelper->validateOAuthAccountAccess($oAuthAccountId);
        $accountName = $oAuthAccount->get('name');

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
        } catch (\Throwable $e) {
            throw new Error("Failed to get OAuth tokens: " . $e->getMessage());
        }

        $accessToken = $tokens->getAccessToken();

        if (!$accessToken) {
            throw new Error("OAuthAccount is missing an access token.");
        }

        try {
            $wabaData = $this->apiClient->getBusinessAccount($accessToken, $wabaId, self::DEFAULT_API_VERSION);
        } catch (Error $e) {
            throw new NotFound("WhatsApp Business Account '{$wabaId}' not found or inaccessible.");
        }

        $entity = $this->mapAccountToEntity($wabaData, $oAuthAccountId, $accountName);

        return $entity->getValueMap();
    }

    /**
     * Map Meta API response data to an EspoCRM entity.
     *
     * @param array<string, mixed> $data API response data
     * @param string $oAuthAccountId OAuthAccount ID
     * @param string|null $oAuthAccountName OAuthAccount name
     * @return Entity
     */
    private function mapAccountToEntity(
        array $data,
        string $oAuthAccountId,
        ?string $oAuthAccountName = null,
    ): Entity {
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $wabaId = $data['id'] ?? '';

        // Composite ID: oAuthAccountId_wabaId
        $entity->set('id', $oAuthAccountId . '_' . $wabaId);
        $entity->set('name', $data['name'] ?? '');
        $entity->set('wabaId', $wabaId);
        $entity->set('timezoneId', $data['timezone_id'] ?? null);
        $entity->set('messageTemplateNamespace', $data['message_template_namespace'] ?? null);
        $entity->set('currency', $data['currency'] ?? null);
        $entity->set('oAuthAccountId', $oAuthAccountId);
        $entity->set('oAuthAccountName', $oAuthAccountName);

        $entity->setAsFetched();

        return $entity;
    }
}
