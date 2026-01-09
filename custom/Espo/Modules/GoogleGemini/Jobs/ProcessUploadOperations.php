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
 * Scheduled job to process pending Gemini File Search upload operations.
 * 
 * This job runs periodically (e.g., every minute) and:
 * 1. Finds all pending GeminiFileSearchStoreUploadOperation entities
 * 2. Polls the Gemini API for each operation's status
 * 3. Updates the operation entity when complete
 * 4. Updates the parent KnowledgeBaseArticle when all operations are done
 */
class ProcessUploadOperations implements Job
{
    private const MAX_OPERATIONS_PER_RUN = 50;
    private const MAX_ATTEMPTS = 60; // ~1 hour with 1-minute intervals

    public function __construct(
        private EntityManager $entityManager,
        private GeminiFileSearchService $geminiService,
        private Log $log
    ) {}

    public function run(Data $data): void
    {
        $this->log->debug('GoogleGemini ProcessUploadOperations: Starting');

        // Find pending operations, ordered by creation time
        $operations = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStoreUploadOperation')
            ->where([
                'status' => ['Pending', 'Processing'],
            ])
            ->order('createdAt')
            ->limit(self::MAX_OPERATIONS_PER_RUN)
            ->find();

        $count = count($operations);
        if ($count === 0) {
            $this->log->debug('GoogleGemini ProcessUploadOperations: No pending operations');
            return;
        }

        $this->log->info("GoogleGemini ProcessUploadOperations: Processing {$count} operations");

        $articlesToCheck = [];

        foreach ($operations as $operation) {
            try {
                $wasCompleted = $this->processOperation($operation);
                
                // Track articles that need status update check
                $articleId = $operation->get('knowledgeBaseArticleId');
                if ($wasCompleted && $articleId) {
                    $articlesToCheck[$articleId] = true;
                }
            } catch (\Exception $e) {
                $this->log->error(
                    "GoogleGemini ProcessUploadOperations: Error processing operation {$operation->getId()}: " .
                    $e->getMessage()
                );
            }
        }

        // Update article statuses for completed operations
        foreach (array_keys($articlesToCheck) as $articleId) {
            $this->updateArticleStatusIfComplete($articleId);
        }

        $this->log->debug('GoogleGemini ProcessUploadOperations: Completed');
    }

    /**
     * Process a single upload operation.
     * 
     * @param Entity $operation The operation entity
     * @return bool True if operation completed (success or failure)
     */
    private function processOperation(Entity $operation): bool
    {
        $operationId = $operation->getId();
        $operationName = $operation->get('operationName');
        $attempts = (int) $operation->get('attempts');

        // Check if max attempts exceeded
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->markOperationFailed($operation, 'Max polling attempts exceeded');
            return true;
        }

        // Update status to Processing and increment attempts
        $operation->set('status', 'Processing');
        $operation->set('attempts', $attempts + 1);
        $this->entityManager->saveEntity($operation, ['silent' => true]);

        // Poll the Gemini API for operation status
        $result = $this->geminiService->getOperationStatus($operationName);

        if ($result === null) {
            // API error - will retry on next run
            $this->log->warning("GoogleGemini ProcessUploadOperations: API error for operation {$operationId}");
            return false;
        }

        // Check if operation is done
        if (isset($result['done']) && $result['done'] === true) {
            if (isset($result['error'])) {
                // Operation failed
                $errorMessage = json_encode($result['error']);
                $this->markOperationFailed($operation, $errorMessage);
                $this->log->warning("GoogleGemini ProcessUploadOperations: Operation {$operationId} failed: {$errorMessage}");
            } else {
                // Operation succeeded
                $documentName = $this->extractDocumentName($result);
                $this->markOperationCompleted($operation, $result, $documentName);
                $this->log->info("GoogleGemini ProcessUploadOperations: Operation {$operationId} completed" .
                    ($documentName ? " with document {$documentName}" : ""));
            }
            return true;
        }

        // Still in progress - will check again on next run
        $this->log->debug("GoogleGemini ProcessUploadOperations: Operation {$operationId} still in progress (attempt {$attempts})");
        return false;
    }

    /**
     * Mark an operation as completed.
     */
    private function markOperationCompleted(Entity $operation, array $response, ?string $documentName): void
    {
        $operation->set('status', 'Completed');
        $operation->set('response', $response);
        $operation->set('geminiDocumentName', $documentName);
        $operation->set('completedAt', date('Y-m-d H:i:s'));
        $this->entityManager->saveEntity($operation, ['silent' => true]);
    }

    /**
     * Mark an operation as failed.
     */
    private function markOperationFailed(Entity $operation, string $errorMessage): void
    {
        $operation->set('status', 'Failed');
        $operation->set('errorMessage', $errorMessage);
        $operation->set('completedAt', date('Y-m-d H:i:s'));
        $this->entityManager->saveEntity($operation, ['silent' => true]);
    }

    /**
     * Update article status if all operations are complete.
     */
    private function updateArticleStatusIfComplete(string $articleId): void
    {
        $article = $this->entityManager->getEntityById('KnowledgeBaseArticle', $articleId);
        if (!$article) {
            return;
        }

        // Get all operations for this article
        $operations = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStoreUploadOperation')
            ->where(['knowledgeBaseArticleId' => $articleId])
            ->find();

        $pendingCount = 0;
        $completedCount = 0;
        $failedCount = 0;
        $articleBodyDocName = null;
        $attachmentDocuments = [];

        foreach ($operations as $op) {
            $status = $op->get('status');
            
            if ($status === 'Pending' || $status === 'Processing') {
                $pendingCount++;
            } elseif ($status === 'Completed') {
                $completedCount++;
                
                $docType = $op->get('documentType');
                $docName = $op->get('geminiDocumentName');
                
                if ($docType === 'ArticleBody' && $docName) {
                    $articleBodyDocName = $docName;
                } elseif ($docType === 'Attachment' && $docName) {
                    $attachmentDocuments[] = [
                        'documentName' => $docName,
                        'attachmentId' => $op->get('attachmentId'),
                        'attachmentName' => $op->get('attachmentName'),
                    ];
                }
            } elseif ($status === 'Failed') {
                $failedCount++;
            }
        }

        // If there are still pending operations, don't update article yet
        if ($pendingCount > 0) {
            return;
        }

        // All operations are complete (either succeeded or failed)
        if ($failedCount > 0 && $completedCount === 0) {
            // All failed
            $article->set('geminiIndexStatus', 'Failed');
            $article->set('geminiIndexError', "All {$failedCount} upload operation(s) failed");
        } elseif ($failedCount > 0) {
            // Some failed, some succeeded - partial success
            $article->set('geminiIndexStatus', 'Indexed');
            $article->set('geminiIndexError', "{$failedCount} of " . ($completedCount + $failedCount) . " operation(s) failed");
            $article->set('geminiDocumentName', $articleBodyDocName);
            $article->set('geminiAttachmentDocuments', $attachmentDocuments);
            $article->set('geminiIndexedAt', date('Y-m-d H:i:s'));
        } else {
            // All succeeded
            $article->set('geminiIndexStatus', 'Indexed');
            $article->set('geminiIndexError', null);
            $article->set('geminiDocumentName', $articleBodyDocName);
            $article->set('geminiAttachmentDocuments', $attachmentDocuments);
            $article->set('geminiIndexedAt', date('Y-m-d H:i:s'));
        }

        $article->set('geminiLastProcessedAt', date('Y-m-d H:i:s'));

        $this->entityManager->saveEntity($article, [
            'silent' => true,
            'skipGeminiIndexing' => true,
        ]);

        $this->log->info("GoogleGemini ProcessUploadOperations: Updated article {$articleId} status to {$article->get('geminiIndexStatus')}");
    }

    /**
     * Extract document name from completed operation response.
     */
    private function extractDocumentName(array $operationResult): ?string
    {
        // Primary path for UploadToFileSearchStoreResponse: response.documentName
        if (isset($operationResult['response']['documentName'])) {
            return $operationResult['response']['documentName'];
        }

        // Alternate path: response.name
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
}



