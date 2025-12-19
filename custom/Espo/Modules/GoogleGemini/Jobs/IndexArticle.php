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
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Entities\Attachment;
use Espo\Modules\GoogleGemini\Services\GeminiFileSearchService;

/**
 * Job to index a KnowledgeBaseArticle to Gemini File Search.
 * Uses a per-team File Search Store. Stores are created on-demand and 
 * their Google-generated names are saved in the GeminiFileSearchStore entity.
 * 
 * This job is NON-BLOCKING: it uploads content to Gemini and creates
 * GeminiFileSearchStoreUploadOperation entities to track the async operations.
 * A separate scheduled job (ProcessUploadOperations) polls these operations.
 */
class IndexArticle implements Job
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private EntityManager $entityManager,
        private GeminiFileSearchService $geminiService,
        private FileStorageManager $fileStorageManager,
        private Log $log
    ) {}

    public function run(Data $data): void
    {
        $articleId = $data->get('articleId');
        $operation = $data->get('operation') ?? 'index';
        $geminiDocumentName = $data->get('geminiDocumentName');
        $geminiAttachmentDocuments = $data->get('geminiAttachmentDocuments');

        if (!$articleId) {
            $this->log->error('GoogleGemini IndexArticle: No articleId provided');
            return;
        }

        $article = $this->entityManager->getEntityById('KnowledgeBaseArticle', $articleId);

        // For delete operations, we can proceed even if article is already deleted
        // as long as we have the document names
        if (!$article && $operation === 'delete' && ($geminiDocumentName || $geminiAttachmentDocuments)) {
            $this->log->info("GoogleGemini IndexArticle: Article {$articleId} already deleted, using stored document names");
            $this->deleteDocumentsByName($geminiDocumentName, $geminiAttachmentDocuments, $articleId);
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
     * This method is NON-BLOCKING: it uploads and creates operation tracking entities.
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

        // Delete all previously indexed documents (article body + attachments)
        $this->deleteExistingDocuments($article);

        // Cancel any pending operations for this article
        $this->cancelPendingOperations($articleId);

        // Build content
        $content = $this->buildArticleContent($article);
        $displayName = 'KB: ' . $article->get('name');

        // Prepare metadata
        $metadata = [
            'articleId' => $articleId,
            'articleName' => $article->get('name'),
            'entityType' => 'KnowledgeBaseArticle',
            'documentType' => 'articleBody',
            'teamId' => $teamId,
        ];

        if ($article->get('language')) {
            $metadata['language'] = $article->get('language');
        }

        // Upload article body to Gemini
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

        if (!isset($result['name'])) {
            throw new \Exception('No operation name in response');
        }

        // Create operation entity for article body (NON-BLOCKING)
        $this->createUploadOperation(
            $result['name'],
            $articleId,
            'ArticleBody',
            null,
            null,
            $article->get('name')
        );

        // Upload attachments and create operation entities for each
        $this->uploadAttachments($article, $storeName, $teamId);

        // Get the local FileSearchStore ID for linking
        $localStore = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStore')
            ->where(['geminiStoreName' => $storeName])
            ->findOne();
        $storeId = $localStore ? $localStore->getId() : null;

        // Set article status to Pending - ProcessUploadOperations will update to Indexed
        $this->updateArticleStatus($article, 'Pending', null, null, $storeId);

        $this->log->info("GoogleGemini IndexArticle: Uploaded {$articleId}, operations created for async processing");
    }

    /**
     * Upload all attachments for an article and create operation entities.
     * NON-BLOCKING: creates operation entities instead of waiting.
     */
    private function uploadAttachments(Entity $article, string $storeName, string $teamId): void
    {
        $articleId = $article->getId();

        // Get attachments linked to the article
        $attachments = $this->entityManager
            ->getRDBRepository('KnowledgeBaseArticle')
            ->getRelation($article, 'attachments')
            ->find();

        foreach ($attachments as $attachment) {
            /** @var Attachment $attachment */
            try {
                $this->uploadSingleAttachment($attachment, $article, $storeName, $teamId);
            } catch (\Exception $e) {
                $this->log->error(
                    "GoogleGemini IndexArticle: Failed to upload attachment {$attachment->getId()} " .
                    "for article {$articleId}: " . $e->getMessage()
                );
                // Continue with other attachments
            }
        }
    }

    /**
     * Upload a single attachment file and create an operation entity.
     * NON-BLOCKING: creates operation entity instead of waiting.
     */
    private function uploadSingleAttachment(Attachment $attachment, Entity $article, string $storeName, string $teamId): void
    {
        $attachmentId = $attachment->getId();
        $attachmentName = $attachment->getName() ?? 'unnamed';
        $mimeType = $attachment->getType() ?? 'application/octet-stream';

        // Check if file exists
        if (!$this->fileStorageManager->exists($attachment)) {
            $this->log->warning("GoogleGemini IndexArticle: Attachment file not found for {$attachmentId}");
            return;
        }

        // Get file contents
        $fileContents = $this->fileStorageManager->getContents($attachment);

        if (empty($fileContents)) {
            $this->log->warning("GoogleGemini IndexArticle: Empty file contents for attachment {$attachmentId}");
            return;
        }

        $displayName = 'KB Attachment: ' . $article->get('name') . ' - ' . $attachmentName;

        // Prepare metadata
        $metadata = [
            'articleId' => $article->getId(),
            'articleName' => $article->get('name'),
            'attachmentId' => $attachmentId,
            'attachmentName' => $attachmentName,
            'entityType' => 'KnowledgeBaseArticle',
            'documentType' => 'attachment',
            'teamId' => $teamId,
        ];

        if ($article->get('language')) {
            $metadata['language'] = $article->get('language');
        }

        // Upload to Gemini
        $result = $this->geminiService->uploadBinaryToFileSearchStore(
            $fileContents,
            $displayName,
            $mimeType,
            $metadata,
            $storeName
        );

        if ($result === null) {
            throw new \Exception("Upload to Gemini failed for attachment {$attachmentId}");
        }

        if (!isset($result['name'])) {
            throw new \Exception("No operation name in response for attachment {$attachmentId}");
        }

        // Create operation entity (NON-BLOCKING)
        $this->createUploadOperation(
            $result['name'],
            $article->getId(),
            'Attachment',
            $attachmentId,
            $attachmentName,
            $article->get('name') . ' - ' . $attachmentName
        );

        $this->log->debug("GoogleGemini IndexArticle: Uploaded attachment {$attachmentId}, operation created");
    }

    /**
     * Create a GeminiFileSearchStoreUploadOperation entity to track the async operation.
     */
    private function createUploadOperation(
        string $operationName,
        string $articleId,
        string $documentType,
        ?string $attachmentId,
        ?string $attachmentName,
        string $displayName
    ): void {
        $article = $this->entityManager->getEntityById('KnowledgeBaseArticle', $articleId);
        $teamIds = $article ? $article->getLinkMultipleIdList('teams') : [];

        $operation = $this->entityManager->createEntity('GeminiFileSearchStoreUploadOperation', [
            'name' => $displayName,
            'operationName' => $operationName,
            'status' => 'Pending',
            'documentType' => $documentType,
            'knowledgeBaseArticleId' => $articleId,
            'attachmentId' => $attachmentId,
            'attachmentName' => $attachmentName,
            'attempts' => 0,
        ], [
            'silent' => true,
            'skipCreatedBy' => true,
        ]);

        // Link to same teams as the article
        if (!empty($teamIds)) {
            foreach ($teamIds as $teamId) {
                $this->entityManager
                    ->getRDBRepository('GeminiFileSearchStoreUploadOperation')
                    ->getRelation($operation, 'teams')
                    ->relateById($teamId);
            }
        }

        $this->log->debug("GoogleGemini IndexArticle: Created operation entity for {$operationName}");
    }

    /**
     * Cancel any pending operations for an article (when re-indexing).
     */
    private function cancelPendingOperations(string $articleId): void
    {
        $pendingOperations = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStoreUploadOperation')
            ->where([
                'knowledgeBaseArticleId' => $articleId,
                'status' => ['Pending', 'Processing'],
            ])
            ->find();

        foreach ($pendingOperations as $operation) {
            $operation->set('status', 'Failed');
            $operation->set('errorMessage', 'Cancelled: article re-indexed');
            $operation->set('completedAt', date('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($operation, ['silent' => true]);
        }

        $count = count($pendingOperations);
        if ($count > 0) {
            $this->log->debug("GoogleGemini IndexArticle: Cancelled {$count} pending operations for article {$articleId}");
        }
    }

    /**
     * Delete all existing Gemini documents for an article (body + attachments).
     */
    private function deleteExistingDocuments(Entity $article): void
    {
        // Delete article body document
        $existingDocName = $article->get('geminiDocumentName');
        if ($existingDocName) {
            $this->geminiService->deleteDocument($existingDocName);
        }

        // Delete attachment documents
        $attachmentDocuments = $article->get('geminiAttachmentDocuments') ?? [];
        foreach ($attachmentDocuments as $doc) {
            if (isset($doc['documentName'])) {
                $this->geminiService->deleteDocument($doc['documentName']);
            }
        }
    }

    /**
     * Delete an article from Gemini File Search (body + all attachments).
     */
    private function deleteArticle(Entity $article): void
    {
        $articleId = $article->getId();
        $hasDocuments = false;
        $allDeleted = true;

        // Cancel any pending operations
        $this->cancelPendingOperations($articleId);

        // Delete article body document
        $documentName = $article->get('geminiDocumentName');
        if ($documentName) {
            $hasDocuments = true;
            if (!$this->geminiService->deleteDocument($documentName)) {
                $allDeleted = false;
                $this->log->warning("GoogleGemini IndexArticle: Failed to delete body document for {$articleId}");
            }
        }

        // Delete attachment documents
        $attachmentDocuments = $article->get('geminiAttachmentDocuments') ?? [];
        foreach ($attachmentDocuments as $doc) {
            if (isset($doc['documentName'])) {
                $hasDocuments = true;
                if (!$this->geminiService->deleteDocument($doc['documentName'])) {
                    $allDeleted = false;
                    $this->log->warning("GoogleGemini IndexArticle: Failed to delete attachment document {$doc['documentName']} for {$articleId}");
                }
            }
        }

        if (!$hasDocuments) {
            $this->log->debug("GoogleGemini IndexArticle: Article {$articleId} has no Gemini documents");
            return;
        }

        if ($allDeleted) {
            // Clear all document references
            $this->updateArticleStatus($article, 'NotIndexed', null, null, null, []);
            $this->log->info("GoogleGemini IndexArticle: Deleted all documents for {$articleId}");
        } else {
            $this->log->warning("GoogleGemini IndexArticle: Some documents failed to delete for {$articleId}");
        }
    }

    /**
     * Delete documents from Gemini by name (when article entity is already deleted).
     * Used for mass delete operations where the article is deleted before the job runs.
     */
    private function deleteDocumentsByName(?string $documentName, ?array $attachmentDocuments, string $articleId): void
    {
        $deletedCount = 0;
        $failedCount = 0;

        // Delete article body document
        if ($documentName) {
            if ($this->geminiService->deleteDocument($documentName)) {
                $deletedCount++;
                $this->log->debug("GoogleGemini IndexArticle: Deleted body document {$documentName} for removed article {$articleId}");
            } else {
                $failedCount++;
                $this->log->warning("GoogleGemini IndexArticle: Failed to delete body document {$documentName} for removed article {$articleId}");
            }
        }

        // Delete attachment documents
        if ($attachmentDocuments) {
            foreach ($attachmentDocuments as $doc) {
                if (isset($doc['documentName'])) {
                    if ($this->geminiService->deleteDocument($doc['documentName'])) {
                        $deletedCount++;
                        $this->log->debug("GoogleGemini IndexArticle: Deleted attachment document {$doc['documentName']} for removed article {$articleId}");
                    } else {
                        $failedCount++;
                        $this->log->warning("GoogleGemini IndexArticle: Failed to delete attachment document {$doc['documentName']} for removed article {$articleId}");
                    }
                }
            }
        }

        $this->log->info("GoogleGemini IndexArticle: Deleted {$deletedCount} document(s) for removed article {$articleId}" . 
            ($failedCount > 0 ? ", {$failedCount} failed" : ""));
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
     * Update article's Gemini indexing status.
     */
    private function updateArticleStatus(
        Entity $article,
        string $status,
        ?string $error = null,
        ?string $documentName = null,
        ?string $fileSearchStoreId = null,
        ?array $attachmentDocuments = null
    ): void {
        $article->set('geminiIndexStatus', $status);
        $article->set('geminiIndexError', $error);
        $article->set('geminiLastProcessedAt', date('Y-m-d H:i:s'));

        if ($documentName !== null) {
            $article->set('geminiDocumentName', $documentName);
        }

        if ($fileSearchStoreId !== null) {
            $article->set('geminiFileSearchStoreId', $fileSearchStoreId);
        }

        if ($attachmentDocuments !== null) {
            $article->set('geminiAttachmentDocuments', $attachmentDocuments);
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
