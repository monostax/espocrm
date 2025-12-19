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

namespace Espo\Modules\GoogleGemini\Services;

use Espo\Core\Record\Service as RecordService;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Di;
use Espo\ORM\Entity;
use stdClass;

/**
 * Hybrid service for GeminiFileSearchStore entity.
 * Handles both local EspoCRM entity operations and external Gemini API operations.
 * 
 * @extends RecordService<Entity>
 */
class GeminiFileSearchStoreService extends RecordService implements
    Di\LogAware
{
    use Di\LogSetter;

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * Create a new File Search Store in both Gemini API and local database.
     *
     * @param stdClass $data
     * @return Entity
     * @throws Error
     * @throws BadRequest
     */
    protected function beforeCreateEntity(Entity $entity, $data): void
    {
        // Create the store in Gemini API first
        $geminiResult = $this->createInGemini($entity->get('name'));

        if ($geminiResult === null) {
            throw new Error('Failed to create File Search Store in Gemini API');
        }

        // Set the Gemini store name on the entity
        $entity->set('geminiStoreName', $geminiResult['name']);
        $entity->set('status', 'Active');
    }

    /**
     * Sync store data from Gemini API after reading.
     */
    protected function afterReadEntity(Entity $entity): void
    {
        $this->syncStoreFromGemini($entity);
    }

    /**
     * Delete from Gemini API before deleting locally.
     */
    protected function beforeDeleteEntity(Entity $entity): void
    {
        $geminiStoreName = $entity->get('geminiStoreName');

        if ($geminiStoreName) {
            $this->deleteFromGemini($geminiStoreName);
        }
    }

    /**
     * Sync store statistics from Gemini API.
     */
    public function syncStoreFromGemini(Entity $entity): bool
    {
        $geminiStoreName = $entity->get('geminiStoreName');

        if (!$geminiStoreName) {
            return false;
        }

        $storeData = $this->getStoreFromGemini($geminiStoreName);

        if ($storeData === null) {
            $entity->set('status', 'Failed');
            $this->entityManager->saveEntity($entity);
            return false;
        }

        // Update local entity with data from API
        $entity->set('activeDocumentsCount', (int)($storeData['activeDocumentsCount'] ?? 0));
        $entity->set('pendingDocumentsCount', (int)($storeData['pendingDocumentsCount'] ?? 0));
        $entity->set('failedDocumentsCount', (int)($storeData['failedDocumentsCount'] ?? 0));
        $entity->set('sizeBytes', (int)($storeData['sizeBytes'] ?? 0));
        $entity->set('lastSyncAt', date('Y-m-d H:i:s'));

        // Determine status based on document counts
        if (($storeData['pendingDocumentsCount'] ?? 0) > 0) {
            $entity->set('status', 'Pending');
        } elseif (($storeData['failedDocumentsCount'] ?? 0) > 0) {
            $entity->set('status', 'Failed');
        } else {
            $entity->set('status', 'Active');
        }

        $this->entityManager->saveEntity($entity);

        return true;
    }

    /**
     * Sync all stores from Gemini API to local database.
     *
     * @return array{created: int, updated: int, errors: int}
     */
    public function syncAllFromGemini(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'errors' => 0];
        $pageToken = null;

        do {
            $response = $this->listStoresFromGemini($pageToken);

            if ($response === null) {
                $this->log->error('GoogleGemini: Failed to list stores from Gemini API');
                break;
            }

            foreach ($response['fileSearchStores'] ?? [] as $storeData) {
                try {
                    $result = $this->syncSingleStoreFromGemini($storeData);
                    if ($result === 'created') {
                        $stats['created']++;
                    } else {
                        $stats['updated']++;
                    }
                } catch (\Exception $e) {
                    $this->log->error('GoogleGemini: Error syncing store: ' . $e->getMessage());
                    $stats['errors']++;
                }
            }

            $pageToken = $response['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        return $stats;
    }

    /**
     * Sync a single store from Gemini API data.
     */
    private function syncSingleStoreFromGemini(array $storeData): string
    {
        $geminiStoreName = $storeData['name'];

        // Check if we already have this store locally
        $existing = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStore')
            ->where(['geminiStoreName' => $geminiStoreName])
            ->findOne();

        if ($existing) {
            // Update existing
            $existing->set('activeDocumentsCount', (int)($storeData['activeDocumentsCount'] ?? 0));
            $existing->set('pendingDocumentsCount', (int)($storeData['pendingDocumentsCount'] ?? 0));
            $existing->set('failedDocumentsCount', (int)($storeData['failedDocumentsCount'] ?? 0));
            $existing->set('sizeBytes', (int)($storeData['sizeBytes'] ?? 0));
            $existing->set('lastSyncAt', date('Y-m-d H:i:s'));
            $existing->set('status', 'Active');
            $this->entityManager->saveEntity($existing);
            return 'updated';
        }

        // Create new local entity
        $entity = $this->entityManager->getNewEntity('GeminiFileSearchStore');
        $entity->set('name', $storeData['displayName'] ?? $geminiStoreName);
        $entity->set('geminiStoreName', $geminiStoreName);
        $entity->set('activeDocumentsCount', (int)($storeData['activeDocumentsCount'] ?? 0));
        $entity->set('pendingDocumentsCount', (int)($storeData['pendingDocumentsCount'] ?? 0));
        $entity->set('failedDocumentsCount', (int)($storeData['failedDocumentsCount'] ?? 0));
        $entity->set('sizeBytes', (int)($storeData['sizeBytes'] ?? 0));
        $entity->set('lastSyncAt', date('Y-m-d H:i:s'));
        $entity->set('status', 'Active');
        $this->entityManager->saveEntity($entity);

        return 'created';
    }

    // ==================== Gemini API Methods ====================

    private function getApiKey(): ?string
    {
        return getenv('GOOGLE_GENERATIVE_AI_API_KEY') ?: null;
    }

    /**
     * Create a File Search Store in Gemini API.
     */
    private function createInGemini(string $displayName): ?array
    {
        $apiKey = $this->getApiKey();

        if (!$apiKey) {
            $this->log->error('GOOGLE_GENERATIVE_AI_API_KEY environment variable not set');
            return null;
        }

        try {
            $url = self::API_BASE . '/fileSearchStores?key=' . $apiKey;

            $data = [
                'displayName' => $displayName,
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->log->error('Failed to create File Search Store in Gemini. HTTP ' . $httpCode . ': ' . $response);
                return null;
            }

            return json_decode($response, true);

        } catch (\Exception $e) {
            $this->log->error('Exception creating File Search Store in Gemini: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get a File Search Store from Gemini API.
     */
    private function getStoreFromGemini(string $storeName): ?array
    {
        $apiKey = $this->getApiKey();

        if (!$apiKey) {
            return null;
        }

        try {
            $url = self::API_BASE . '/' . $storeName . '?key=' . $apiKey;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->log->warning('Failed to get File Search Store from Gemini. HTTP ' . $httpCode);
                return null;
            }

            return json_decode($response, true);

        } catch (\Exception $e) {
            $this->log->error('Exception getting File Search Store from Gemini: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * List File Search Stores from Gemini API.
     */
    private function listStoresFromGemini(?string $pageToken = null): ?array
    {
        $apiKey = $this->getApiKey();

        if (!$apiKey) {
            return null;
        }

        try {
            $url = self::API_BASE . '/fileSearchStores?key=' . $apiKey . '&pageSize=20';

            if ($pageToken !== null) {
                $url .= '&pageToken=' . urlencode($pageToken);
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->log->warning('Failed to list File Search Stores from Gemini. HTTP ' . $httpCode);
                return null;
            }

            return json_decode($response, true);

        } catch (\Exception $e) {
            $this->log->error('Exception listing File Search Stores from Gemini: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a File Search Store from Gemini API.
     */
    private function deleteFromGemini(string $storeName): bool
    {
        $apiKey = $this->getApiKey();

        if (!$apiKey) {
            return false;
        }

        try {
            $url = self::API_BASE . '/' . $storeName . '?key=' . $apiKey . '&force=true';

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->log->warning('Failed to delete File Search Store from Gemini. HTTP ' . $httpCode);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            $this->log->error('Exception deleting File Search Store from Gemini: ' . $e->getMessage());
            return false;
        }
    }
}
