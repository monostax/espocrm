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

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Util;
use Espo\Core\Exceptions\Forbidden;

/**
 * Hook to automatically create/manage GeminiFileSearchStore when aiIndexEnabled is toggled.
 * 
 * IMPORTANT: This hook runs BEFORE save and will ABORT the save if store creation fails.
 * This ensures a category cannot have aiIndexEnabled=true without a valid Gemini store.
 * 
 * When a KnowledgeBaseCategory has aiIndexEnabled set to true:
 * - Creates a new GeminiFileSearchStore for that category
 * - Links the store to the category
 * - FAILS the save if store creation fails
 * 
 * When aiIndexEnabled is set to false:
 * - Logs the change (store is kept for reference)
 */
class HandleAIIndex implements BeforeSave
{
    public static int $order = 10;

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * Before a category is saved, handle AI index store creation.
     * Throws exception if store creation fails to abort the save.
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        // Skip if this is a silent save (internal operations)
        if ($options->get(SaveOption::SILENT)) {
            return;
        }

        // Skip if aiIndexEnabled wasn't changed
        if (!$entity->isAttributeChanged('aiIndexEnabled')) {
            return;
        }

        $aiIndexEnabled = $entity->get('aiIndexEnabled');
        $categoryName = $entity->get('name');

        if ($aiIndexEnabled) {
            // Check if store already exists
            $existingStoreId = $entity->get('geminiFileSearchStoreId');
            
            if ($existingStoreId) {
                // Verify the store still exists
                $store = $this->entityManager->getEntityById('GeminiFileSearchStore', $existingStoreId);
                if ($store && $store->get('geminiStoreName')) {
                    $this->log->debug("GoogleGemini: Category already has valid store {$existingStoreId}");
                    return;
                }
                // Store reference exists but is invalid, clear it and create new
                $entity->set('geminiFileSearchStoreId', null);
            }

            // Create store - this will throw exception on failure
            $this->createStoreForCategory($entity);
        } else {
            // When disabled, we leave the store for reference
            $categoryId = $entity->getId() ?? 'new';
            $this->log->info("GoogleGemini: AI indexing disabled for category {$categoryId} ({$categoryName})");
        }
    }

    /**
     * Create a GeminiFileSearchStore for a KnowledgeBaseCategory.
     * 
     * @throws Forbidden if store creation fails (aborts the save)
     */
    private function createStoreForCategory(Entity $category): void
    {
        // For new entities, generate an ID if not set
        $categoryId = $category->getId();
        if (!$categoryId) {
            $categoryId = Util::generateId();
            $category->set('id', $categoryId);
        }
        
        $categoryName = $category->get('name');

        $this->log->info("GoogleGemini: Creating store for category {$categoryId} ({$categoryName})");

        // Get API key
        $apiKey = getenv('GOOGLE_GENERATIVE_AI_API_KEY');
        if (!$apiKey) {
            $this->log->error("GoogleGemini: GOOGLE_GENERATIVE_AI_API_KEY not set");
            throw new Forbidden(
                "Cannot enable AI indexing: Google Gemini API key is not configured. " .
                "Please contact your administrator."
            );
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->log->error("GoogleGemini: cURL error creating store for category {$categoryId}: {$curlError}");
            throw new Forbidden(
                "Cannot enable AI indexing: Failed to connect to Google Gemini API. " .
                "Please try again later or contact your administrator."
            );
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            $this->log->error("GoogleGemini: Failed to create store for category {$categoryId}: HTTP {$httpCode}: {$response}");
            
            // Parse error message if available
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? "HTTP error {$httpCode}";
            
            throw new Forbidden(
                "Cannot enable AI indexing: Google Gemini API error - {$errorMessage}. " .
                "Please try again later or contact your administrator."
            );
        }

        $result = json_decode($response, true);

        if (!$result || !isset($result['name'])) {
            $this->log->error("GoogleGemini: Invalid response when creating store for category {$categoryId}: {$response}");
            throw new Forbidden(
                "Cannot enable AI indexing: Received invalid response from Google Gemini API. " .
                "Please try again later or contact your administrator."
            );
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

        // Link the store to the category (will be saved with the category)
        $category->set('geminiFileSearchStoreId', $store->getId());

        $this->log->info("GoogleGemini: Linked store {$store->getId()} to category {$categoryId}");
    }
}
