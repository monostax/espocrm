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
 * Virtual RecordService for GoogleMeetTranscriptEntry entity.
 *
 * Fetches transcript entry data from the Google Meet REST API v2.
 * First discovers transcripts for a conference record, then fetches
 * entries for each transcript.
 *
 * Called by the GoogleMeetConferenceRecord controller as a child service.
 */
class GoogleMeetTranscriptEntry
{
    public const ENTITY_TYPE = 'GoogleMeetTranscriptEntry';

    public function __construct(
        private EntityManager $entityManager,
        private GoogleMeetApiClient $apiClient,
        private Log $log,
    ) {}

    /**
     * List transcript entries for a conference record.
     * First lists all transcripts, then fetches entries for each.
     *
     * @throws Error
     */
    public function find(string $accessToken, string $conferenceRecordId): RecordCollection
    {
        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $totalCount = 0;

        try {
            $transcriptsResponse = $this->apiClient->listTranscripts($accessToken, $conferenceRecordId);
            $transcripts = $transcriptsResponse['transcripts'] ?? [];

            foreach ($transcripts as $transcript) {
                $transcriptName = $transcript['name'] ?? '';
                $transcriptId = $this->extractTranscriptId($transcriptName);

                if (!$transcriptId) {
                    continue;
                }

                try {
                    $pageToken = null;

                    do {
                        $params = ['pageSize' => 100];

                        if ($pageToken) {
                            $params['pageToken'] = $pageToken;
                        }

                        $entriesResponse = $this->apiClient->listTranscriptEntries(
                            $accessToken,
                            $conferenceRecordId,
                            $transcriptId,
                            $params,
                        );
                        $entries = $entriesResponse['transcriptEntries'] ?? [];

                        foreach ($entries as $entry) {
                            $entity = $this->mapToEntity($entry);
                            $collection->append($entity);
                            $totalCount++;
                        }

                        $pageToken = $entriesResponse['nextPageToken'] ?? null;
                    } while ($pageToken);
                } catch (\Throwable $e) {
                    $this->log->warning(
                        "GoogleMeetTranscriptEntry: Failed to list entries for transcript {$transcriptId}: "
                        . $e->getMessage()
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->log->error(
                "GoogleMeetTranscriptEntry: Failed to list transcripts for {$conferenceRecordId}: "
                . $e->getMessage()
            );
        }

        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * Read a single transcript entry.
     *
     * @throws Error
     */
    public function read(
        string $accessToken,
        string $conferenceRecordId,
        string $transcriptId,
        string $entryId,
    ): Entity {
        try {
            $data = $this->apiClient->getTranscriptEntry(
                $accessToken,
                $conferenceRecordId,
                $transcriptId,
                $entryId,
            );
        } catch (\Throwable $e) {
            throw new Error("Transcript entry not found or inaccessible: " . $e->getMessage());
        }

        return $this->mapToEntity($data);
    }

    private function mapToEntity(array $data): Entity
    {
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $resourceName = $data['name'] ?? '';
        $entryId = $this->extractResourceId($resourceName);

        $entity->set('id', $entryId);
        $entity->set('name', $resourceName);
        $entity->set('participant', $data['participant'] ?? null);
        $entity->set('text', $data['text'] ?? null);
        $entity->set('languageCode', $data['languageCode'] ?? null);
        $entity->set('startTime', $this->formatTimestamp($data['startTime'] ?? null));
        $entity->set('endTime', $this->formatTimestamp($data['endTime'] ?? null));

        $entity->setAsFetched();

        return $entity;
    }

    /**
     * Extract transcript ID from resource name like
     * "conferenceRecords/{id}/transcripts/{transcriptId}".
     */
    private function extractTranscriptId(string $resourceName): string
    {
        $parts = explode('/', $resourceName);

        return end($parts);
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
