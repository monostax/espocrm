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
use Espo\Modules\GoogleGemini\Services\GeminiIndexingService;

/**
 * Job to index a KnowledgeBaseArticle to Gemini File Search.
 * Uses a per-category File Search Store. Each KnowledgeBaseCategory with
 * aiIndexEnabled=true has its own GeminiFileSearchStore.
 * 
 * This job is NON-BLOCKING: it uploads content to Gemini and creates
 * GeminiFileSearchStoreUploadOperation entities to track the async operations.
 * A separate scheduled job (ProcessUploadOperations) polls these operations.
 *
 * Delete operations are guaranteed: failed deletions are retried with a
 * backoff, and in-flight upload operations are resolved so that documents
 * created after the delete request are also removed.
 */
class IndexArticle implements Job
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    /** Maximum number of retry attempts for delete operations. */
    private const MAX_DELETE_ATTEMPTS = 10;

    public function __construct(
        private EntityManager $entityManager,
        private GeminiFileSearchService $geminiService,
        private GeminiIndexingService $indexingService,
        private FileStorageManager $fileStorageManager,
        private Log $log
    ) {}

    public function run(Data $data): void
    {
        $articleId = $data->get('articleId');
        $operation = $data->get('operation') ?? 'index';
        $geminiDocumentName = $data->get('geminiDocumentName');
        $geminiAttachmentDocuments = $this->normalizeAttachmentDocuments($data->get('geminiAttachmentDocuments'));
        $attempt = (int) ($data->get('attempt') ?? 0);

        if (!$articleId) {
            $this->log->error('GoogleGemini IndexArticle: No articleId provided');
            return;
        }

        $article = $this->entityManager->getEntityById('KnowledgeBaseArticle', $articleId);

        // For delete operations, we can proceed even if article is already deleted:
        // document names come from the job data and in-flight upload operations
        // are resolved by article ID.
        if (!$article && $operation === 'delete') {
            $this->log->info("GoogleGemini IndexArticle: Article {$articleId} already deleted, using stored document names");
            $this->deleteDocumentsByName($geminiDocumentName, $geminiAttachmentDocuments, $articleId, $attempt);
            return;
        }

        if (!$article) {
            $this->log->warning("GoogleGemini IndexArticle: Article {$articleId} not found");
            return;
        }

        try {
            match ($operation) {
                'index', 'update' => $this->indexArticle($article),
                'delete' => $this->deleteArticle($article, $attempt),
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
     * 
     * Articles are indexed to all KnowledgeBaseCategories that have aiIndexEnabled=true.
     */
    private function indexArticle(Entity $article): void
    {
        $articleId = $article->getId();
        
        // Get all categories for this article that have AI indexing enabled
        $storeInfo = $this->getStoresForArticleCategories($article);

        if (empty($storeInfo)) {
            $this->log->warning("GoogleGemini IndexArticle: Article {$articleId} has no AI-enabled categories, skipping");
            $this->updateArticleStatus($article, 'NotIndexed', 'No AI-enabled categories');
            return;
        }

        // Delete all previously indexed documents (article body + attachments)
        $this->deleteExistingDocuments($article);

        // Resolve previous upload operations: delete documents they created
        // (referenced on the article or not) and flag still-in-flight uploads
        // so their resulting documents are discarded upon completion.
        $this->resolveOperationsForReindex($articleId);

        // Build content
        $content = $this->buildArticleContent($article);
        $displayName = 'KB: ' . $article->get('name');

        // Track the first store for linking (for backwards compatibility)
        $firstStoreId = null;

        // Upload to each category's store
        foreach ($storeInfo as $info) {
            $storeName = $info['storeName'];
            $categoryId = $info['categoryId'];
            $storeId = $info['storeId'];

            if ($firstStoreId === null) {
                $firstStoreId = $storeId;
            }

            // Prepare metadata
            $metadata = [
                'articleId' => $articleId,
                'articleName' => $article->get('name'),
                'entityType' => 'KnowledgeBaseArticle',
                'documentType' => 'articleBody',
                'categoryId' => $categoryId,
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
                $this->log->error("GoogleGemini IndexArticle: Upload to store {$storeName} failed for article {$articleId}");
                continue;
            }

            if (!isset($result['name'])) {
                $this->log->error("GoogleGemini IndexArticle: No operation name in response for store {$storeName}");
                continue;
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

            // Upload attachments to this store
            $this->uploadAttachments($article, $storeName, $categoryId);

            $this->log->debug("GoogleGemini IndexArticle: Uploaded {$articleId} to store {$storeName} (category {$categoryId})");
        }

        // Set article status to Pending - ProcessUploadOperations will update to Indexed
        $this->updateArticleStatus($article, 'Pending', null, null, $firstStoreId);

        $this->log->info("GoogleGemini IndexArticle: Uploaded {$articleId} to " . count($storeInfo) . " store(s), operations created for async processing");
    }

    /**
     * Upload all attachments for an article and create operation entities.
     * NON-BLOCKING: creates operation entities instead of waiting.
     */
    private function uploadAttachments(Entity $article, string $storeName, string $categoryId): void
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
                $this->uploadSingleAttachment($attachment, $article, $storeName, $categoryId);
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
    private function uploadSingleAttachment(Attachment $attachment, Entity $article, string $storeName, string $categoryId): void
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
            'categoryId' => $categoryId,
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
        $this->entityManager->createEntity('GeminiFileSearchStoreUploadOperation', [
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

        $this->log->debug("GoogleGemini IndexArticle: Created operation entity for {$operationName}");
    }

    /**
     * Resolve previous upload operations when re-indexing an article.
     *
     * Documents created by uploads are removed even when they were never
     * referenced on the article (e.g. rapid consecutive saves):
     *
     * - Completed operations: the tracked document is deleted (it is being
     *   superseded by the re-index) and the operation is marked superseded.
     * - Pending/Processing operations are polled once: if done, the resulting
     *   document is deleted; if still in flight, the operation is flagged with
     *   discardDocument so ProcessUploadOperations deletes the document once
     *   the upload completes, instead of abandoning it.
     */
    private function resolveOperationsForReindex(string $articleId): void
    {
        $operations = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStoreUploadOperation')
            ->where([
                'knowledgeBaseArticleId' => $articleId,
                'status' => ['Pending', 'Processing', 'Completed'],
            ])
            ->find();

        foreach ($operations as $operation) {
            $status = $operation->get('status');

            if ($status === 'Completed') {
                // The document is superseded by the re-index - remove it.
                // deleteDocument() treats 404 as success, so documents already
                // removed via the article references are handled gracefully.
                $trackedDocName = $operation->get('geminiDocumentName');

                if ($trackedDocName && !$this->geminiService->deleteDocument($trackedDocName)) {
                    $this->log->warning(
                        "GoogleGemini IndexArticle: Failed to delete superseded document {$trackedDocName} " .
                        "for article {$articleId} (reconciliation job will retry)"
                    );
                }

                $operation->set('status', 'Failed');
                $operation->set('errorMessage', 'Superseded: article re-indexed');
                $operation->set('completedAt', date('Y-m-d H:i:s'));
                $this->entityManager->saveEntity($operation, ['silent' => true]);

                continue;
            }

            // Pending/Processing: poll once - the upload may already have created a document.
            $operationName = $operation->get('operationName');
            $result = $operationName ? $this->geminiService->getOperationStatus($operationName) : null;

            if ($operationName && ($result === null || !($result['done'] ?? false))) {
                // Still in flight (or API error). Flag the operation so that
                // ProcessUploadOperations deletes the resulting document once
                // the upload completes, instead of orphaning it.
                $operation->set('discardDocument', true);
                $operation->set('errorMessage', 'Superseded: article re-indexed, document will be discarded');
                $this->entityManager->saveEntity($operation, ['silent' => true]);

                $this->log->debug(
                    "GoogleGemini IndexArticle: Operation {$operation->getId()} still in flight for article {$articleId}, flagged for discard"
                );

                continue;
            }

            if ($result !== null && !isset($result['error'])) {
                $createdDocName = $this->extractDocumentName($result);

                if ($createdDocName && !$this->geminiService->deleteDocument($createdDocName)) {
                    $this->log->warning(
                        "GoogleGemini IndexArticle: Failed to delete document {$createdDocName} from " .
                        "cancelled upload for article {$articleId} (reconciliation job will retry)"
                    );
                }
            }

            $operation->set('status', 'Failed');
            $operation->set('errorMessage', 'Cancelled: article re-indexed');
            $operation->set('completedAt', date('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($operation, ['silent' => true]);
        }

        $count = count($operations);
        if ($count > 0) {
            $this->log->debug("GoogleGemini IndexArticle: Resolved {$count} previous operations for article {$articleId}");
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
        $attachmentDocuments = $this->normalizeAttachmentDocuments($article->get('geminiAttachmentDocuments'));
        foreach ($attachmentDocuments as $doc) {
            if (isset($doc['documentName'])) {
                $this->geminiService->deleteDocument($doc['documentName']);
            }
        }
    }

    /**
     * Delete an article from Gemini File Search (body + all attachments).
     *
     * Removal is guaranteed: in-flight upload operations are resolved (their
     * resulting documents deleted), document references are only cleared once
     * the corresponding document is confirmed removed, and any leftover work
     * is retried with a backoff.
     */
    private function deleteArticle(Entity $article, int $attempt = 0): void
    {
        $articleId = $article->getId();

        // Resolve upload operations first: in-flight uploads may create
        // documents after this job runs, and completed operations track
        // documents that might not be referenced on the article yet.
        $unresolvedOperations = $this->resolveOperationsForDelete($articleId);

        $remainingBodyDoc = null;
        $remainingAttachmentDocs = [];

        // Delete article body document
        $documentName = $article->get('geminiDocumentName');
        if ($documentName && !$this->geminiService->deleteDocument($documentName)) {
            $remainingBodyDoc = $documentName;
            $this->log->warning("GoogleGemini IndexArticle: Failed to delete body document for {$articleId}");
        }

        // Delete attachment documents
        $attachmentDocuments = $this->normalizeAttachmentDocuments($article->get('geminiAttachmentDocuments'));
        foreach ($attachmentDocuments as $doc) {
            if (!isset($doc['documentName'])) {
                continue;
            }

            if (!$this->geminiService->deleteDocument($doc['documentName'])) {
                $remainingAttachmentDocs[] = $doc;
                $this->log->warning("GoogleGemini IndexArticle: Failed to delete attachment document {$doc['documentName']} for {$articleId}");
            }
        }

        $fullyRemoved = $remainingBodyDoc === null
            && empty($remainingAttachmentDocs)
            && $unresolvedOperations === 0;

        // Keep only the references that were NOT confirmed removed, so that
        // retries target exactly the leftover documents.
        $article->set('geminiDocumentName', $remainingBodyDoc);
        $article->set('geminiAttachmentDocuments', $remainingAttachmentDocs);
        $article->set('geminiLastProcessedAt', date('Y-m-d H:i:s'));

        if ($fullyRemoved) {
            $article->set('geminiIndexStatus', 'NotIndexed');
            $article->set('geminiIndexError', null);
            $this->saveArticleSilently($article);

            $this->log->info("GoogleGemini IndexArticle: Removed all documents from file search store for {$articleId}");
            return;
        }

        if ($attempt >= self::MAX_DELETE_ATTEMPTS) {
            $article->set('geminiIndexStatus', 'Failed');
            $article->set('geminiIndexError', 'Failed to remove documents from file search store after ' . $attempt . ' retries');
            $this->saveArticleSilently($article);

            $this->log->error(
                "GoogleGemini IndexArticle: Giving up removing documents for {$articleId} after {$attempt} retries"
            );
            return;
        }

        $article->set('geminiIndexError', 'Removal from file search store incomplete, retrying');
        $this->saveArticleSilently($article);

        $this->log->warning(
            "GoogleGemini IndexArticle: Removal incomplete for {$articleId} " .
            "({$unresolvedOperations} unresolved operation(s)), scheduling retry " . ($attempt + 1)
        );

        $this->indexingService->queueArticleIndexing(
            $articleId,
            'delete',
            null,
            null,
            $attempt + 1
        );
    }

    /**
     * Delete documents from Gemini by name (when article entity is already deleted).
     * Used for mass delete operations where the article is deleted before the job runs.
     *
     * Removal is guaranteed: failed deletions and unresolved upload operations
     * trigger a retry carrying only the leftover document names.
     */
    private function deleteDocumentsByName(?string $documentName, ?array $attachmentDocuments, string $articleId, int $attempt = 0): void
    {
        // Resolve upload operations that may still create (or already track)
        // documents for this deleted article.
        $unresolvedOperations = $this->resolveOperationsForDelete($articleId);

        $deletedCount = 0;
        $remainingBodyDoc = null;
        $remainingAttachmentDocs = [];

        // Delete article body document
        if ($documentName) {
            if ($this->geminiService->deleteDocument($documentName)) {
                $deletedCount++;
                $this->log->debug("GoogleGemini IndexArticle: Deleted body document {$documentName} for removed article {$articleId}");
            } else {
                $remainingBodyDoc = $documentName;
                $this->log->warning("GoogleGemini IndexArticle: Failed to delete body document {$documentName} for removed article {$articleId}");
            }
        }

        // Delete attachment documents
        foreach ($this->normalizeAttachmentDocuments($attachmentDocuments) as $doc) {
            if (!isset($doc['documentName'])) {
                continue;
            }

            if ($this->geminiService->deleteDocument($doc['documentName'])) {
                $deletedCount++;
                $this->log->debug("GoogleGemini IndexArticle: Deleted attachment document {$doc['documentName']} for removed article {$articleId}");
            } else {
                $remainingAttachmentDocs[] = $doc;
                $this->log->warning("GoogleGemini IndexArticle: Failed to delete attachment document {$doc['documentName']} for removed article {$articleId}");
            }
        }

        $fullyRemoved = $remainingBodyDoc === null
            && empty($remainingAttachmentDocs)
            && $unresolvedOperations === 0;

        if ($fullyRemoved) {
            $this->log->info("GoogleGemini IndexArticle: Deleted {$deletedCount} document(s) for removed article {$articleId}");
            return;
        }

        if ($attempt >= self::MAX_DELETE_ATTEMPTS) {
            $this->log->error(
                "GoogleGemini IndexArticle: Giving up removing documents for removed article {$articleId} " .
                "after {$attempt} retries (" . count($remainingAttachmentDocs) . " attachment doc(s), " .
                ($remainingBodyDoc ? 'body doc pending, ' : '') .
                "{$unresolvedOperations} unresolved operation(s))"
            );
            return;
        }

        $this->log->warning(
            "GoogleGemini IndexArticle: Removal incomplete for removed article {$articleId} " .
            "({$unresolvedOperations} unresolved operation(s)), scheduling retry " . ($attempt + 1)
        );

        $this->indexingService->queueArticleIndexing(
            $articleId,
            'delete',
            $remainingBodyDoc,
            $remainingAttachmentDocs,
            $attempt + 1
        );
    }

    /**
     * Resolve upload operations for an article being removed from the file search store.
     *
     * - Pending/Processing operations are polled: if done, the resulting document
     *   is deleted and the operation is cancelled; if still in flight, it counts
     *   as unresolved so the caller schedules a retry.
     * - Completed operations that track a document name have that document
     *   deleted too (it may not be referenced on the article yet).
     *
     * @return int Number of operations that could not be resolved yet.
     */
    private function resolveOperationsForDelete(string $articleId): int
    {
        $operations = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStoreUploadOperation')
            ->where([
                'knowledgeBaseArticleId' => $articleId,
                'status' => ['Pending', 'Processing', 'Completed'],
            ])
            ->find();

        $unresolved = 0;

        foreach ($operations as $operation) {
            $status = $operation->get('status');

            if ($status === 'Completed') {
                // Document already tracked on the operation - make sure it is gone.
                $trackedDocName = $operation->get('geminiDocumentName');
                if ($trackedDocName && !$this->geminiService->deleteDocument($trackedDocName)) {
                    $unresolved++;
                }
                continue;
            }

            $operationName = $operation->get('operationName');
            $result = $operationName ? $this->geminiService->getOperationStatus($operationName) : null;

            if ($operationName && ($result === null || !($result['done'] ?? false))) {
                // Still in flight (or API error) - the document may be created
                // later, so the caller must retry.
                $unresolved++;
                continue;
            }

            if ($result !== null && !isset($result['error'])) {
                $createdDocName = $this->extractDocumentName($result);
                if ($createdDocName && !$this->geminiService->deleteDocument($createdDocName)) {
                    $unresolved++;
                    continue;
                }
            }

            $operation->set('status', 'Failed');
            $operation->set('errorMessage', 'Cancelled: content removed from file search store');
            $operation->set('completedAt', date('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($operation, ['silent' => true]);
        }

        if ($unresolved > 0) {
            $this->log->debug("GoogleGemini IndexArticle: {$unresolved} upload operation(s) unresolved for article {$articleId}");
        }

        return $unresolved;
    }

    /**
     * Extract document name from a completed operation response.
     */
    private function extractDocumentName(array $operationResult): ?string
    {
        return $operationResult['response']['documentName']
            ?? $operationResult['response']['name']
            ?? $operationResult['response']['document']['name']
            ?? $operationResult['metadata']['document']
            ?? null;
    }

    /**
     * Normalize an attachment documents list to an array of associative arrays.
     * Values may come as stdClass objects (from job data or JSON attributes).
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAttachmentDocuments(mixed $documents): array
    {
        if (!$documents) {
            return [];
        }

        $normalized = [];

        foreach ((array) $documents as $doc) {
            if (is_object($doc)) {
                $doc = (array) $doc;
            }

            if (is_array($doc)) {
                $normalized[] = $doc;
            }
        }

        return $normalized;
    }

    /**
     * Save the article without triggering indexing hooks.
     */
    private function saveArticleSilently(Entity $article): void
    {
        $this->entityManager->saveEntity($article, [
            'silent' => true,
            'skipGeminiIndexing' => true,
        ]);
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
     * Get all stores for the article's categories that have AI indexing enabled.
     * Returns array of ['storeName' => string, 'storeId' => string, 'categoryId' => string].
     */
    private function getStoresForArticleCategories(Entity $article): array
    {
        $articleId = $article->getId();
        $storeInfo = [];

        // Get all categories for this article
        $categories = $this->entityManager
            ->getRDBRepository('KnowledgeBaseArticle')
            ->getRelation($article, 'categories')
            ->find();

        foreach ($categories as $category) {
            // Check if this category has AI indexing enabled
            if (!$category->get('aiIndexEnabled')) {
                continue;
            }

            // Get the linked GeminiFileSearchStore
            $storeId = $category->get('geminiFileSearchStoreId');
            if (!$storeId) {
                $this->log->warning("GoogleGemini IndexArticle: Category {$category->getId()} has aiIndexEnabled but no store");
                continue;
            }

            $store = $this->entityManager->getEntityById('GeminiFileSearchStore', $storeId);
            if (!$store) {
                $this->log->warning("GoogleGemini IndexArticle: Store {$storeId} not found for category {$category->getId()}");
                continue;
            }

            $storeName = $store->get('geminiStoreName');
            if (!$storeName) {
                $this->log->warning("GoogleGemini IndexArticle: Store {$storeId} has no geminiStoreName");
                continue;
            }

            $storeInfo[] = [
                'storeName' => $storeName,
                'storeId' => $storeId,
                'categoryId' => $category->getId(),
            ];
        }

        return $storeInfo;
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



