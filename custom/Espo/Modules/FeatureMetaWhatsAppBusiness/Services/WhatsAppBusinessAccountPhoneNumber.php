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
 * Virtual RecordService for WhatsAppBusinessAccountPhoneNumber entity.
 *
 * Fetches phone number data from the Meta Graph API as a child
 * of WhatsAppBusinessAccount. Uses OAuthAccount for authentication.
 */
class WhatsAppBusinessAccountPhoneNumber
{
    public const ENTITY_TYPE = 'WhatsAppBusinessAccountPhoneNumber';

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
     * Find phone numbers for a given OAuthAccount and Business Account.
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
            $oAuthAccount = $this->oAuthHelper->validateOAuthAccountAccess($oAuthAccountId);
        } catch (NotFound $e) {
            $this->log->warning(
                "WhatsAppBusinessAccountPhoneNumber: OAuthAccount {$oAuthAccountId} not found, returning empty result."
            );

            return RecordCollection::create(
                $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE),
                0
            );
        }

        $accountName = $oAuthAccount->get('name');

        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $totalCount = 0;

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
        } catch (\Throwable $e) {
            $this->log->warning(
                "WhatsAppBusinessAccountPhoneNumber: Failed to get tokens for OAuthAccount {$oAuthAccountId}: " . $e->getMessage()
            );

            return RecordCollection::create($collection, 0);
        }

        $accessToken = $tokens->getAccessToken();

        if (!$accessToken || !$businessAccountId) {
            $this->log->warning(
                "WhatsAppBusinessAccountPhoneNumber: OAuthAccount {$oAuthAccountId} missing accessToken or businessAccountId."
            );

            return RecordCollection::create($collection, 0);
        }

        try {
            $phoneNumbers = $this->apiClient->getPhoneNumbers($accessToken, $businessAccountId, self::DEFAULT_API_VERSION);

            foreach ($phoneNumbers as $phoneData) {
                $entity = $this->mapPhoneNumberToEntity($phoneData, $oAuthAccountId, $accountName);
                $collection->append($entity);
                $totalCount++;
            }
        } catch (Error $e) {
            $this->log->error(
                "WhatsAppBusinessAccountPhoneNumber: Failed to fetch phone numbers for OAuthAccount {$oAuthAccountId}: " . $e->getMessage()
            );
        }

        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * Read a single phone number.
     *
     * @param string $oAuthAccountId The OAuthAccount ID
     * @param string $businessAccountId The WABA ID
     * @param string $phoneNumberId The Meta phone number ID
     * @return stdClass Entity value map
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function read(string $oAuthAccountId, string $businessAccountId, string $phoneNumberId): stdClass
    {
        if (!$this->acl->checkScope('WhatsAppBusinessAccount', 'read')) {
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

        if (!$accessToken || !$businessAccountId) {
            throw new Error("OAuthAccount is missing accessToken or businessAccountId.");
        }

        $phoneNumbers = $this->apiClient->getPhoneNumbers($accessToken, $businessAccountId, self::DEFAULT_API_VERSION);

        foreach ($phoneNumbers as $phoneData) {
            if (($phoneData['id'] ?? '') === $phoneNumberId) {
                $entity = $this->mapPhoneNumberToEntity($phoneData, $oAuthAccountId, $accountName);

                return $entity->getValueMap();
            }
        }

        throw new NotFound("Phone number '{$phoneNumberId}' not found.");
    }

    /**
     * Map Meta API phone number data to an EspoCRM entity.
     *
     * @param array<string, mixed> $data API response data for a single phone number
     * @param string $oAuthAccountId OAuthAccount ID
     * @param string|null $oAuthAccountName OAuthAccount name
     * @return Entity
     */
    private function mapPhoneNumberToEntity(
        array $data,
        string $oAuthAccountId,
        ?string $oAuthAccountName = null,
    ): Entity {
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $phoneNumberId = $data['id'] ?? '';

        // Composite ID: oAuthAccountId_phoneNumberId
        $entity->set('id', $oAuthAccountId . '_' . $phoneNumberId);
        $entity->set('name', $data['verified_name'] ?? '');
        $entity->set('phoneNumberId', $phoneNumberId);
        $entity->set('displayPhoneNumber', $data['display_phone_number'] ?? '');
        $entity->set('qualityRating', $data['quality_rating'] ?? 'NA');
        $entity->set('oAuthAccountId', $oAuthAccountId);
        $entity->set('oAuthAccountName', $oAuthAccountName);

        $entity->setAsFetched();

        return $entity;
    }
}
