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
 * Virtual RecordService for WhatsAppBusinessAccountMessageTemplate entity.
 *
 * Fetches message template data from the Meta Graph API as a child
 * of WhatsAppBusinessAccount. Uses OAuthAccount for authentication.
 */
class WhatsAppBusinessAccountMessageTemplate
{
    public const ENTITY_TYPE = 'WhatsAppBusinessAccountMessageTemplate';

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
     * Find message templates for a given OAuthAccount and Business Account.
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

        $oAuthAccount = $this->oAuthHelper->validateOAuthAccountAccess($oAuthAccountId);
        $accountName = $oAuthAccount->get('name');

        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $totalCount = 0;

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
        } catch (\Throwable $e) {
            $this->log->warning(
                "WhatsAppBusinessAccountMessageTemplate: Failed to get tokens for OAuthAccount {$oAuthAccountId}: " . $e->getMessage()
            );

            return RecordCollection::create($collection, 0);
        }

        $accessToken = $tokens->getAccessToken();

        if (!$accessToken || !$businessAccountId) {
            $this->log->warning(
                "WhatsAppBusinessAccountMessageTemplate: OAuthAccount {$oAuthAccountId} missing accessToken or businessAccountId."
            );

            return RecordCollection::create($collection, 0);
        }

        try {
            $templates = $this->apiClient->getMessageTemplates($accessToken, $businessAccountId, self::DEFAULT_API_VERSION);

            foreach ($templates as $templateData) {
                $entity = $this->mapTemplateToEntity($templateData, $oAuthAccountId, $accountName);
                $collection->append($entity);
                $totalCount++;
            }
        } catch (Error $e) {
            $this->log->error(
                "WhatsAppBusinessAccountMessageTemplate: Failed to fetch templates for OAuthAccount {$oAuthAccountId}: " . $e->getMessage()
            );
        }

        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * Read a single message template.
     *
     * @param string $oAuthAccountId The OAuthAccount ID
     * @param string $businessAccountId The WABA ID
     * @param string $templateId The Meta template ID
     * @return stdClass Entity value map
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function read(string $oAuthAccountId, string $businessAccountId, string $templateId): stdClass
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

        $templates = $this->apiClient->getMessageTemplates($accessToken, $businessAccountId, self::DEFAULT_API_VERSION);

        foreach ($templates as $templateData) {
            if (($templateData['id'] ?? '') === $templateId) {
                $entity = $this->mapTemplateToEntity($templateData, $oAuthAccountId, $accountName);

                return $entity->getValueMap();
            }
        }

        throw new NotFound("Message template '{$templateId}' not found.");
    }

    /**
     * Map Meta API message template data to an EspoCRM entity.
     *
     * @param array<string, mixed> $data API response data for a single template
     * @param string $oAuthAccountId OAuthAccount ID
     * @param string|null $oAuthAccountName OAuthAccount name
     * @return Entity
     */
    private function mapTemplateToEntity(
        array $data,
        string $oAuthAccountId,
        ?string $oAuthAccountName = null,
    ): Entity {
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $templateId = $data['id'] ?? '';

        // Composite ID: oAuthAccountId_templateId
        $entity->set('id', $oAuthAccountId . '_' . $templateId);
        $entity->set('name', $data['name'] ?? '');
        $entity->set('templateId', $templateId);
        $entity->set('language', $data['language'] ?? '');
        $entity->set('status', $data['status'] ?? '');
        $entity->set('category', $data['category'] ?? '');
        $entity->set('oAuthAccountId', $oAuthAccountId);
        $entity->set('oAuthAccountName', $oAuthAccountName);

        // Serialize components array to JSON text for display.
        if (isset($data['components']) && is_array($data['components'])) {
            $entity->set('components', json_encode($data['components'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $entity->set('components', '');
        }

        $entity->setAsFetched();

        return $entity;
    }
}
