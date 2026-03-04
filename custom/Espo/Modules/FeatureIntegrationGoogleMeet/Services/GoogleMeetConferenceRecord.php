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
 * Virtual RecordService for GoogleMeetConferenceRecord entity.
 *
 * Fetches conference record data from the Google Meet REST API v2.
 * Uses Credential entities for authentication and team-based ACL.
 */
class GoogleMeetConferenceRecord
{
    public const ENTITY_TYPE = 'GoogleMeetConferenceRecord';

    public function __construct(
        private EntityManager $entityManager,
        private GoogleMeetApiClient $apiClient,
        private GoogleMeetCredentialHelper $credentialHelper,
        private TokensProvider $tokensProvider,
        private Log $log,
        private Acl $acl,
    ) {}

    /**
     * List conference records.
     *
     * @param string|null $credentialId  Single credential filter.
     * @param string[]|null $credentialIds  Multiple credential filter.
     * @throws Error
     * @throws Forbidden
     */
    public function find(?string $credentialId = null, ?array $credentialIds = null): RecordCollection
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to GoogleMeetConferenceRecord.");
        }

        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
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
                $response = $this->apiClient->listConferenceRecords($accessToken);
                $items = $response['conferenceRecords'] ?? [];

                foreach ($items as $item) {
                    $entity = $this->mapToEntity($item, $credId);
                    $collection->append($entity);
                    $totalCount++;
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
     * List conference records filtered by a specific space.
     *
     * @throws Error
     * @throws Forbidden
     */
    public function findBySpace(string $credentialId, string $spaceName): RecordCollection
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to GoogleMeetConferenceRecord.");
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

        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $totalCount = 0;

        try {
            $filter = 'space.name = "' . $spaceName . '"';
            $response = $this->apiClient->listConferenceRecords($accessToken, ['filter' => $filter]);
            $items = $response['conferenceRecords'] ?? [];

            foreach ($items as $item) {
                $entity = $this->mapToEntity($item, $credentialId);
                $collection->append($entity);
                $totalCount++;
            }
        } catch (\Throwable $e) {
            $this->log->error(
                "GoogleMeetConferenceRecord: Failed to list records by space: " . $e->getMessage()
            );
        }

        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function read(string $credentialId, string $conferenceRecordId): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to GoogleMeetConferenceRecord.");
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
            $data = $this->apiClient->getConferenceRecord($accessToken, $conferenceRecordId);
        } catch (\Throwable $e) {
            throw new NotFound("Conference record '{$conferenceRecordId}' not found or inaccessible.");
        }

        $entity = $this->mapToEntity($data, $credentialId);

        return $entity->getValueMap();
    }

    private function mapToEntity(array $data, string $credentialId): Entity
    {
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $resourceName = $data['name'] ?? '';
        $recordId = $this->extractResourceId($resourceName);

        $startTime = $this->formatTimestamp($data['startTime'] ?? null);
        $endTime = $this->formatTimestamp($data['endTime'] ?? null);

        $entity->set('id', $credentialId . '_' . $recordId);
        $entity->set('name', $this->buildFriendlyName($data['space'] ?? null, $startTime));
        $entity->set('startTime', $startTime);
        $entity->set('endTime', $endTime);
        $entity->set('expireTime', $this->formatTimestamp($data['expireTime'] ?? null));
        $entity->set('space', $data['space'] ?? null);
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

    private function buildFriendlyName(?string $space, ?string $startTime): string
    {
        $spaceCode = '';

        if ($space) {
            $parts = explode('/', $space);
            $spaceCode = end($parts);
        }

        if ($startTime) {
            try {
                $dt = new \DateTime($startTime);
                $dateStr = $dt->format('M j, Y H:i');
            } catch (\Throwable) {
                $dateStr = $startTime;
            }

            return $spaceCode
                ? "Meeting {$spaceCode} — {$dateStr}"
                : "Meeting — {$dateStr}";
        }

        return $spaceCode ? "Meeting {$spaceCode}" : 'Meeting';
    }

    private function extractResourceId(string $resourceName): string
    {
        $parts = explode('/', $resourceName);

        return end($parts);
    }

    /**
     * Convert a Google API timestamp (RFC 3339) to EspoCRM datetime format.
     */
    private function formatTimestamp(?string $timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }

        try {
            $dt = new \DateTime($timestamp);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
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
                        "GoogleMeetConferenceRecord: Credential {$id} not accessible, skipping."
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
                    "GoogleMeetConferenceRecord: Credential {$credentialId} not accessible."
                );
                return [];
            }
        }

        return $this->credentialHelper->getAccessibleCredentials();
    }
}
