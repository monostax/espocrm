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

namespace Espo\Modules\GoogleGemini\Hooks\KnowledgeBaseCategory;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;

/**
 * Hook to automatically create/manage GeminiFileSearchStore when aiIndexEnabled is toggled.
 * 
 * When a KnowledgeBaseCategory has aiIndexEnabled set to true:
 * - Creates a new GeminiFileSearchStore for that category
 * - Links the store to the category
 * 
 * When aiIndexEnabled is set to false:
 * - Optionally cleans up the store (or leaves it for reference)
 */
class HandleAIIndex implements AfterSave
{
    public static int $order = 10;

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * After a category is saved, handle AI index store creation/removal.
     */
    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        // Skip if this is a silent save
        if ($options->get(SaveOption::SILENT)) {
            return;
        }

        // Skip if aiIndexEnabled wasn't changed
        if (!$entity->isAttributeChanged('aiIndexEnabled')) {
            return;
        }

        $aiIndexEnabled = $entity->get('aiIndexEnabled');
        $categoryId = $entity->getId();
        $categoryName = $entity->get('name');

        if ($aiIndexEnabled) {
            // Create store if it doesn't exist
            $existingStoreId = $entity->get('geminiFileSearchStoreId');
            
            if (!$existingStoreId) {
                $this->createStoreForCategory($entity);
            }
        } else {
            // When disabled, we leave the store for reference but could optionally clean up
            $this->log->info("GoogleGemini: AI indexing disabled for category {$categoryId} ({$categoryName})");
        }
    }

    /**
     * Create a GeminiFileSearchStore for a KnowledgeBaseCategory.
     */
    private function createStoreForCategory(Entity $category): void
    {
        $categoryId = $category->getId();
        $categoryName = $category->get('name');

        $this->log->info("GoogleGemini: Creating store for category {$categoryId} ({$categoryName})");

        try {
            // Get API key
            $apiKey = getenv('GOOGLE_GENERATIVE_AI_API_KEY');
            if (!$apiKey) {
                $this->log->error("GoogleGemini: GOOGLE_GENERATIVE_AI_API_KEY not set");
                return;
            }

            // Create store via Gemini API
            $displayName = "kb-category-{$categoryId}";
            $createUrl = self::API_BASE . '/fileSearchStores?key=' . $apiKey;

            $ch = curl_init($createUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'displayName' => $displayName,
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 && $httpCode !== 201) {
                $this->log->error("GoogleGemini: Failed to create store for category {$categoryId}: HTTP {$httpCode}: {$response}");
                return;
            }

            $result = json_decode($response, true);

            if (!$result || !isset($result['name'])) {
                $this->log->error("GoogleGemini: Invalid response when creating store: {$response}");
                return;
            }

            $geminiStoreName = $result['name'];
            $this->log->info("GoogleGemini: Created store {$geminiStoreName} for category {$categoryId}");

            // Create local GeminiFileSearchStore entity
            $store = $this->entityManager->createEntity('GeminiFileSearchStore', [
                'name' => $categoryName,
                'geminiStoreName' => $geminiStoreName,
                'status' => 'Active',
                'createdById' => 'system',
            ], [
                'silent' => true,
                'skipHooks' => true,
            ]);

            // Link the store to the category
            $category->set('geminiFileSearchStoreId', $store->getId());
            $this->entityManager->saveEntity($category, [
                'silent' => true,
                'skipHooks' => true,
            ]);

            $this->log->info("GoogleGemini: Linked store {$store->getId()} to category {$categoryId}");

        } catch (\Exception $e) {
            $this->log->error("GoogleGemini: Failed to create store for category {$categoryId}: " . $e->getMessage());
        }
    }
}
