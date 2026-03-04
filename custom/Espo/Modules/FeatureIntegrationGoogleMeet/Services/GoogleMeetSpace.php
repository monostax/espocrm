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

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services;

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
 * Virtual RecordService for GoogleMeetSpace entity.
 *
 * Fetches Space data from the Google Meet REST API v2
 * instead of a local database. Uses Credential entities for
 * authentication and team-based ACL.
 *
 * NOTE: The Google Meet API has no list endpoint for spaces.
 * find() discovers spaces via conferenceRecords, and read()
 * fetches a single space by name or meeting code.
 */
class GoogleMeetSpace
{
    public const ENTITY_TYPE = 'GoogleMeetSpace';

    public function __construct(
        private EntityManager $entityManager,
        private GoogleMeetApiClient $apiClient,
        private GoogleMeetCredentialHelper $credentialHelper,
        private TokensProvider $tokensProvider,
        private Log $log,
        private Acl $acl,
    ) {}

    /**
     * Discover spaces by listing conference records and extracting unique spaces.
     *
     * @param string|null $credentialId  Single credential filter.
     * @param string[]|null $credentialIds  Multiple credential filter.
     * @throws Error
     * @throws Forbidden
     */
    public function find(?string $credentialId = null, ?array $credentialIds = null): RecordCollection
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to GoogleMeetSpace.");
        }

        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $seenSpaces = [];
        $totalCount = 0;
        $errors = [];

        $credentials = $this->resolveCredentials($credentialId, $credentialIds);

        foreach ($credentials as $credential) {
            $credId = $credential->getId();

            try {
                $oAuthAccountId = $this->credentialHelper->getOAuthAccountId($credential);
                $tokens = $this->tokensProvider->get($oAuthAccountId);
                $accessToken = $tokens->getAccessToken();
            } catch (\Throwable $e) {
                $errors[$credId] = $e->getMessage();
                continue;
            }

            if (!$accessToken) {
                $errors[$credId] = 'Missing access token.';
                continue;
            }

            try {
                $records = $this->apiClient->listConferenceRecords($accessToken);
                $items = $records['conferenceRecords'] ?? [];

                foreach ($items as $record) {
                    $spaceName = $record['space'] ?? null;

                    if (!$spaceName || isset($seenSpaces[$credId . '_' . $spaceName])) {
                        continue;
                    }

                    try {
                        $spaceId = $this->extractResourceId($spaceName);
                        $spaceData = $this->apiClient->getSpace($accessToken, $spaceId);
                        $entity = $this->mapSpaceToEntity($spaceData, $credId);
                        $collection->append($entity);
                        $seenSpaces[$credId . '_' . $spaceName] = true;
                        $totalCount++;
                    } catch (\Throwable $e) {
                        $this->log->warning(
                            "GoogleMeetSpace: Failed to fetch space {$spaceName}: " . $e->getMessage()
                        );
                    }
                }
            } catch (\Throwable $e) {
                $errors[$credId] = $e->getMessage();
            }
        }

        if ($totalCount === 0 && !empty($errors)) {
            $firstError = reset($errors);
            throw new Error($firstError);
        }

        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function read(string $credentialId, string $spaceId): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to GoogleMeetSpace.");
        }

        $credential = $this->credentialHelper->validateCredentialAccess($credentialId);
        $oAuthAccountId = $this->credentialHelper->getOAuthAccountId($credential);

        try {
            $tokens = $this->tokensProvider->get($oAuthAccountId);
        } catch (\Throwable $e) {
            throw new Error("Failed to get OAuth tokens: " . $e->getMessage());
        }

        $accessToken = $tokens->getAccessToken();

        if (!$accessToken) {
            throw new Error("Credential is missing an access token.");
        }

        try {
            $spaceData = $this->apiClient->getSpace($accessToken, $spaceId);
        } catch (\Throwable $e) {
            throw new NotFound("Space '{$spaceId}' not found or inaccessible.");
        }

        $entity = $this->mapSpaceToEntity($spaceData, $credentialId);

        return $entity->getValueMap();
    }

    private function mapSpaceToEntity(array $data, string $credentialId): Entity
    {
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $spaceName = $data['name'] ?? '';
        $spaceId = $this->extractResourceId($spaceName);

        $entity->set('id', $credentialId . '_' . $spaceId);
        $entity->set('name', $spaceName);
        $entity->set('meetingUri', $data['meetingUri'] ?? null);
        $entity->set('meetingCode', $data['meetingCode'] ?? null);
        $entity->set('accessType', $data['config']['accessType'] ?? null);

        $activeConference = $data['activeConference'] ?? null;
        $entity->set('hasActiveConference', $activeConference !== null);
        $entity->set('activeConferenceRecord', $activeConference['conferenceRecord'] ?? null);

        $entity->set('credentialId', $credentialId);
        $entity->set('credentialName', $this->resolveCredentialName($credentialId));

        $entity->setAsFetched();

        return $entity;
    }

    private function resolveCredentialName(string $credentialId): ?string
    {
        $credential = $this->entityManager->getEntityById('Credential', $credentialId);

        return $credential?->get('name');
    }

    /**
     * Extract the resource ID from a resource name like "spaces/abc123".
     */
    private function extractResourceId(string $resourceName): string
    {
        $parts = explode('/', $resourceName);

        return end($parts);
    }

    /**
     * @return Entity[]
     */
    private function resolveCredentials(?string $credentialId, ?array $credentialIds): array
    {
        if ($credentialIds) {
            $credentials = [];
            foreach ($credentialIds as $id) {
                try {
                    $credentials[] = $this->credentialHelper->validateCredentialAccess($id);
                } catch (\Throwable $e) {
                    $this->log->warning(
                        "GoogleMeetSpace: Credential {$id} not accessible, skipping."
                    );
                }
            }
            return $credentials;
        }

        if ($credentialId) {
            try {
                return [$this->credentialHelper->validateCredentialAccess($credentialId)];
            } catch (\Throwable $e) {
                $this->log->warning(
                    "GoogleMeetSpace: Credential {$credentialId} not accessible."
                );
                return [];
            }
        }

        return $this->credentialHelper->getAccessibleCredentials();
    }
}
