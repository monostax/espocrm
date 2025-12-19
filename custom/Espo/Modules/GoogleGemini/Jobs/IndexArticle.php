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
 * Uses the article's team ID as the store name.
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

        if (!$articleId) {
            $this->log->error('GoogleGemini IndexArticle: No articleId provided');
            return;
        }

        $article = $this->entityManager->getEntityById('KnowledgeBaseArticle', $articleId);

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
        $storeName = "fileSearchStores/team-{$teamId}";

        // Ensure the store exists
        $this->ensureStoreExists($teamId, $storeName);

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
            $success = $this->geminiService->waitForOperation($operationName, 120);

            if (!$success) {
                throw new \Exception('Operation timed out or failed');
            }

            // Get the document name from operation result
            // The document name is typically in the response metadata
            $documentName = $this->extractDocumentName($result, $storeName);
            
            $this->updateArticleStatus($article, 'Indexed', null, $documentName);
            $this->log->info("GoogleGemini IndexArticle: Successfully indexed {$articleId}");
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
     * Ensure a File Search Store exists for the team.
     */
    private function ensureStoreExists(string $teamId, string $storeName): void
    {
        $apiKey = getenv('GEMINI_API_KEY');
        
        if (!$apiKey) {
            throw new \Exception('GEMINI_API_KEY not set');
        }

        // Check if store exists
        $url = self::API_BASE . '/' . $storeName . '?key=' . $apiKey;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            // Store exists
            return;
        }

        // Create the store with specific ID
        $team = $this->entityManager->getEntityById('Team', $teamId);
        $teamName = $team ? $team->get('name') : "Team {$teamId}";
        $storeId = "team-{$teamId}";

        $createUrl = self::API_BASE . '/fileSearchStores?fileSearchStoreId=' . urlencode($storeId) . '&key=' . $apiKey;
        
        $ch = curl_init($createUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'displayName' => "Knowledge Base - {$teamName}",
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            $this->log->error("GoogleGemini: Failed to create store {$storeName}: HTTP {$httpCode}: {$response}");
            throw new \Exception("Failed to create store: HTTP {$httpCode}");
        }

        $this->log->info("GoogleGemini: Created store {$storeName} for team {$teamId}");

        // Also create/update local GeminiFileSearchStore entity
        $this->syncLocalStore($teamId, $storeName, $teamName);
    }

    /**
     * Create or update local GeminiFileSearchStore entity.
     */
    private function syncLocalStore(string $teamId, string $storeName, string $teamName): void
    {
        $existing = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStore')
            ->where(['geminiStoreName' => $storeName])
            ->findOne();

        if ($existing) {
            return;
        }

        $entity = $this->entityManager->createEntity('GeminiFileSearchStore', [
            'name' => "Knowledge Base - {$teamName}",
            'geminiStoreName' => $storeName,
            'status' => 'Active',
        ], ['silent' => true]);

        // Link to team
        $this->entityManager
            ->getRDBRepository('GeminiFileSearchStore')
            ->getRelation($entity, 'teams')
            ->relateById($teamId);
    }

    /**
     * Extract document name from operation response.
     */
    private function extractDocumentName(array $result, string $storeName): ?string
    {
        // The document name might be in the response or we need to derive it
        if (isset($result['response']['name'])) {
            return $result['response']['name'];
        }

        // If not available, we'll need to list documents and find the latest
        // For now, return null and rely on subsequent sync
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
