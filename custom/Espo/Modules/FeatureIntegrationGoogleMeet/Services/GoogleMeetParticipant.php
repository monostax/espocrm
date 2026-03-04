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

use Espo\Core\Exceptions\Error;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Virtual RecordService for GoogleMeetParticipant entity.
 *
 * Fetches participant data from the Google Meet REST API v2.
 * Called by the GoogleMeetConferenceRecord controller as a child service.
 */
class GoogleMeetParticipant
{
    public const ENTITY_TYPE = 'GoogleMeetParticipant';

    public function __construct(
        private EntityManager $entityManager,
        private GoogleMeetApiClient $apiClient,
        private Log $log,
    ) {}

    /**
     * List participants for a conference record.
     *
     * @throws Error
     */
    public function find(string $accessToken, string $conferenceRecordId): RecordCollection
    {
        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $totalCount = 0;

        try {
            $response = $this->apiClient->listParticipants($accessToken, $conferenceRecordId);
            $items = $response['participants'] ?? [];

            foreach ($items as $item) {
                $entity = $this->mapToEntity($item);
                $collection->append($entity);
                $totalCount++;
            }
        } catch (\Throwable $e) {
            $this->log->error(
                "GoogleMeetParticipant: Failed to list participants for {$conferenceRecordId}: " . $e->getMessage()
            );
        }

        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * Read a single participant.
     *
     * @throws Error
     */
    public function read(string $accessToken, string $conferenceRecordId, string $participantId): Entity
    {
        try {
            $data = $this->apiClient->getParticipant($accessToken, $conferenceRecordId, $participantId);
        } catch (\Throwable $e) {
            throw new Error("Participant not found or inaccessible: " . $e->getMessage());
        }

        return $this->mapToEntity($data);
    }

    private function mapToEntity(array $data): Entity
    {
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $resourceName = $data['name'] ?? '';
        $participantId = $this->extractResourceId($resourceName);

        $entity->set('id', $participantId);

        [$displayName, $userType] = $this->resolveUserInfo($data);
        $entity->set('name', $displayName ?: $resourceName);

        $entity->set('displayName', $displayName);
        $entity->set('userType', $userType);

        $entity->set('earliestStartTime', $this->formatTimestamp($data['earliestStartTime'] ?? null));
        $entity->set('latestEndTime', $this->formatTimestamp($data['latestEndTime'] ?? null));

        $entity->setAsFetched();

        return $entity;
    }

    /**
     * Extract display name and user type from participant data.
     * The API returns one of: signedinUser, anonymousUser, or phoneUser.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveUserInfo(array $data): array
    {
        if (isset($data['signedinUser'])) {
            $displayName = $data['signedinUser']['displayName'] ?? 'Signed-in User';
            return [$displayName, 'signedin'];
        }

        if (isset($data['anonymousUser'])) {
            $displayName = $data['anonymousUser']['displayName'] ?? 'Anonymous User';
            return [$displayName, 'anonymous'];
        }

        if (isset($data['phoneUser'])) {
            $displayName = $data['phoneUser']['displayName'] ?? 'Phone User';
            return [$displayName, 'phone'];
        }

        return ['Unknown', 'unknown'];
    }

    private function extractResourceId(string $resourceName): string
    {
        $parts = explode('/', $resourceName);

        return end($parts);
    }

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
}
