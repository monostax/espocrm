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

namespace Espo\Modules\GoogleGemini\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\GoogleGemini\Services\GeminiFileSearchService;

/**
 * Job to index a KnowledgeBaseArticle to Gemini File Search.
 * Uses a per-team File Search Store. Stores are created on-demand and 
 * their Google-generated names are saved in the GeminiFileSearchStore entity.
 */
class IndexArticle implements Job
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private EntityManager $entityManager,
        private GeminiFileSearchService $geminiService,
        private Log $log
    ) {}

    public function run(Data $data): void
    {
        $articleId = $data->get('articleId');
        $operation = $data->get('operation') ?? 'index';
        $geminiDocumentName = $data->get('geminiDocumentName');

        if (!$articleId) {
            $this->log->error('GoogleGemini IndexArticle: No articleId provided');
            return;
        }

        $article = $this->entityManager->getEntityById('KnowledgeBaseArticle', $articleId);

        // For delete operations, we can proceed even if article is already deleted
        // as long as we have the geminiDocumentName
        if (!$article && $operation === 'delete' && $geminiDocumentName) {
            $this->log->info("GoogleGemini IndexArticle: Article {$articleId} already deleted, using stored document name");
            $this->deleteDocumentByName($geminiDocumentName, $articleId);
            return;
        }

        if (!$article) {
            $this->log->warning("GoogleGemini IndexArticle: Article {$articleId} not found");
            return;
        }

        try {
            match ($operation) {
                'index', 'update' => $this->indexArticle($article),
                'delete' => $this->deleteArticle($article),
                default => $this->log->warning("GoogleGemini IndexArticle: Unknown operation: {$operation}"),
            };
        } catch (\Exception $e) {
            $this->log->error("GoogleGemini IndexArticle: Failed for {$articleId}: " . $e->getMessage());
            $this->updateArticleStatus($article, 'Failed', $e->getMessage());
        }
    }

    /**
     * Index or update an article in Gemini File Search.
     */
    private function indexArticle(Entity $article): void
    {
        $articleId = $article->getId();
        $teamIds = $article->getLinkMultipleIdList('teams');

        if (empty($teamIds)) {
            $this->log->warning("GoogleGemini IndexArticle: Article {$articleId} has no teams, skipping");
            $this->updateArticleStatus($article, 'Failed', 'Article has no teams assigned');
            return;
        }

        // Use the first team ID as the store
        $teamId = $teamIds[0];

        // Get or create the store for this team (returns actual Gemini store name)
        $storeName = $this->getOrCreateStoreForTeam($teamId);

        // If article was previously indexed, delete the old document first
        $existingDocName = $article->get('geminiDocumentName');
        if ($existingDocName) {
            $this->geminiService->deleteDocument($existingDocName);
        }

        // Build content
        $content = $this->buildArticleContent($article);
        $displayName = 'KB: ' . $article->get('name');

        // Prepare metadata
        $metadata = [
            'articleId' => $articleId,
            'articleName' => $article->get('name'),
            'entityType' => 'KnowledgeBaseArticle',
            'teamId' => $teamId,
        ];

        if ($article->get('language')) {
            $metadata['language'] = $article->get('language');
        }

        // Upload to Gemini
        $result = $this->geminiService->uploadToFileSearchStore(
            $content,
            $displayName,
            $metadata,
            'text/plain',
            null,
            $storeName
        );

        if ($result === null) {
            throw new \Exception('Upload to Gemini failed');
        }

        // Wait for operation to complete
        if (isset($result['name'])) {
            $operationName = $result['name'];
            $operationResult = $this->geminiService->waitForOperation($operationName, 120);

            if ($operationResult === null) {
                throw new \Exception('Operation timed out or failed');
            }

            // Get the document name from the completed operation response
            $documentName = $this->extractDocumentName($operationResult);
            
            if (!$documentName) {
                $this->log->warning("GoogleGemini IndexArticle: Could not extract document name from response: " . json_encode($operationResult));
            }
            
            $this->updateArticleStatus($article, 'Indexed', null, $documentName);
            $this->log->info("GoogleGemini IndexArticle: Successfully indexed {$articleId}" . ($documentName ? " as {$documentName}" : ""));
        } else {
            throw new \Exception('No operation name in response');
        }
    }

    /**
     * Delete an article from Gemini File Search.
     */
    private function deleteArticle(Entity $article): void
    {
        $documentName = $article->get('geminiDocumentName');

        if (!$documentName) {
            $this->log->debug("GoogleGemini IndexArticle: Article {$article->getId()} has no Gemini document");
            return;
        }

        $success = $this->geminiService->deleteDocument($documentName);

        if ($success) {
            $this->updateArticleStatus($article, 'NotIndexed', null, null);
            $this->log->info("GoogleGemini IndexArticle: Deleted document for {$article->getId()}");
        } else {
            $this->log->warning("GoogleGemini IndexArticle: Failed to delete document for {$article->getId()}");
        }
    }

    /**
     * Delete a document from Gemini by name (when article entity is already deleted).
     * Used for mass delete operations where the article is deleted before the job runs.
     */
    private function deleteDocumentByName(string $documentName, string $articleId): void
    {
        $success = $this->geminiService->deleteDocument($documentName);

        if ($success) {
            $this->log->info("GoogleGemini IndexArticle: Deleted document {$documentName} for removed article {$articleId}");
        } else {
            $this->log->warning("GoogleGemini IndexArticle: Failed to delete document {$documentName} for removed article {$articleId}");
        }
    }

    /**
     * Build the content string for indexing.
     */
    private function buildArticleContent(Entity $article): string
    {
        $name = $article->get('name') ?? '';
        $description = $article->get('description') ?? '';
        $bodyPlain = $article->get('bodyPlain') ?: strip_tags($article->get('body') ?? '');

        $content = "# {$name}\n\n";

        if ($description) {
            $content .= "{$description}\n\n";
        }

        $content .= $bodyPlain;

        return $content;
    }

    /**
     * Get or create a File Search Store for the team.
     * Returns the actual Gemini store name (e.g., "fileSearchStores/knowledge-base-teamname-abc123def456").
     */
    private function getOrCreateStoreForTeam(string $teamId): string
    {
        $apiKey = getenv('GOOGLE_GENERATIVE_AI_API_KEY');
        
        if (!$apiKey) {
            throw new \Exception('GOOGLE_GENERATIVE_AI_API_KEY not set');
        }

        // First, check if we already have a store for this team in our database
        $existingStore = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStore')
            ->join('teams')
            ->where(['teams.id' => $teamId])
            ->findOne();

        if ($existingStore) {
            $storeName = $existingStore->get('geminiStoreName');
            if ($storeName) {
                // Verify the store actually exists in Gemini
                if ($this->verifyStoreExists($storeName, $apiKey)) {
                    return $storeName;
                }
                
                // Store doesn't exist in Gemini, delete the stale record
                $this->log->warning("GoogleGemini: Store {$storeName} not found in Gemini, removing stale record");
                $this->entityManager->removeEntity($existingStore);
            }
        }

        // No valid store exists, create a new one via Gemini API
        // Use team-{teamId} as displayName so the generated store name is predictable
        $createUrl = self::API_BASE . '/fileSearchStores?key=' . $apiKey;
        
        $ch = curl_init($createUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'displayName' => "team-{$teamId}",
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            $this->log->error("GoogleGemini: Failed to create store for team {$teamId}: HTTP {$httpCode}: {$response}");
            throw new \Exception("Failed to create store: HTTP {$httpCode}");
        }

        $result = json_decode($response, true);
        
        if (!$result || !isset($result['name'])) {
            $this->log->error("GoogleGemini: Invalid response when creating store: {$response}");
            throw new \Exception('Invalid response from Gemini API - no store name returned');
        }

        $storeName = $result['name'];
        $this->log->info("GoogleGemini: Created store {$storeName} for team {$teamId}");

        // Save the store to our database
        $this->syncLocalStore($teamId, $storeName);

        return $storeName;
    }

    /**
     * Verify a File Search Store exists in Gemini.
     */
    private function verifyStoreExists(string $storeName, string $apiKey): bool
    {
        $url = self::API_BASE . '/' . $storeName . '?key=' . $apiKey;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /**
     * Create or update local GeminiFileSearchStore entity.
     */
    private function syncLocalStore(string $teamId, string $storeName): void
    {
        $existing = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStore')
            ->where(['geminiStoreName' => $storeName])
            ->findOne();

        if ($existing) {
            return;
        }

        $entity = $this->entityManager->createEntity('GeminiFileSearchStore', [
            'name' => "team-{$teamId}",
            'geminiStoreName' => $storeName,
            'status' => 'Active',
            'createdById' => 'system',
        ], [
            'silent' => true,
            'skipCreatedBy' => true,
            'skipStream' => true,
        ]);

        // Link to team
        $this->entityManager
            ->getRDBRepository('GeminiFileSearchStore')
            ->getRelation($entity, 'teams')
            ->relateById($teamId);
    }

    /**
     * Extract document name from completed operation response.
     * 
     * The Gemini API returns the document name in the operation result at:
     * - response.name (standard path)
     * - response.document.name (alternate path)
     * - metadata.document (some API versions)
     */
    private function extractDocumentName(array $operationResult): ?string
    {
        // Standard path: response.name
        if (isset($operationResult['response']['name'])) {
            return $operationResult['response']['name'];
        }

        // Alternate path: response.document.name
        if (isset($operationResult['response']['document']['name'])) {
            return $operationResult['response']['document']['name'];
        }

        // Some API versions use metadata
        if (isset($operationResult['metadata']['document'])) {
            return $operationResult['metadata']['document'];
        }

        return null;
    }

    /**
     * Update article's Gemini indexing status.
     */
    private function updateArticleStatus(
        Entity $article,
        string $status,
        ?string $error = null,
        ?string $documentName = null
    ): void {
        $article->set('geminiIndexStatus', $status);
        $article->set('geminiIndexError', $error);

        if ($documentName !== null) {
            $article->set('geminiDocumentName', $documentName);
        }

        if ($status === 'Indexed') {
            $article->set('geminiIndexedAt', date('Y-m-d H:i:s'));
        }

        $this->entityManager->saveEntity($article, [
            'silent' => true,
            'skipGeminiIndexing' => true,
        ]);
    }
}
