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

        // Check if max attempts exceeded - do a final poll so a document
        // created server-side is not silently orphaned.
        if ($attempts >= self::MAX_ATTEMPTS) {
            return $this->finalizeExpiredOperation($operation);
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
                $this->handleCompletedOperation($operation, $result, $documentName);
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
     * Finalize an operation that exceeded the polling attempt limit.
     *
     * A final status check is performed: if the operation actually completed
     * server-side, the resulting document is either recorded (normal flow) or
     * deleted (discard flag), so it does not end up orphaned in the store.
     *
     * @return bool Always true (the operation is finalized either way)
     */
    private function finalizeExpiredOperation(Entity $operation): bool
    {
        $operationId = $operation->getId();
        $operationName = $operation->get('operationName');

        $result = $operationName ? $this->geminiService->getOperationStatus($operationName) : null;

        if ($result !== null && ($result['done'] ?? false)) {
            if (isset($result['error'])) {
                $this->markOperationFailed($operation, json_encode($result['error']));
                return true;
            }

            $documentName = $this->extractDocumentName($result);
            $this->handleCompletedOperation($operation, $result, $documentName);

            $this->log->info(
                "GoogleGemini ProcessUploadOperations: Operation {$operationId} resolved on final poll" .
                ($documentName ? " with document {$documentName}" : "")
            );

            return true;
        }

        // The upload may still complete server-side later and create a
        // document; the reconciliation job removes it if left unreferenced.
        $this->markOperationFailed($operation, 'Max polling attempts exceeded');
        $this->log->warning("GoogleGemini ProcessUploadOperations: Operation {$operationId} failed: max polling attempts exceeded");

        return true;
    }

    /**
     * Handle a successfully completed upload operation.
     *
     * If the operation was flagged for discard (superseded by a re-index),
     * the resulting document is deleted instead of being recorded.
     */
    private function handleCompletedOperation(Entity $operation, array $response, ?string $documentName): void
    {
        if (!$documentName) {
            $this->markOperationFailed($operation, 'Upload completed without a document name; previous index retained');
            return;
        }
        if ($operation->get('discardDocument')) {
            if ($documentName && !$this->geminiService->deleteDocument($documentName)) {
                $this->log->warning(
                    "GoogleGemini ProcessUploadOperations: Failed to delete discarded document {$documentName} " .
                    "for operation {$operation->getId()} (reconciliation job will retry)"
                );
            }

            $operation->set('status', 'Failed');
            $operation->set('response', $response);
            $operation->set('geminiDocumentName', $documentName);
            $operation->set('errorMessage', 'Cancelled: superseded by re-index, document discarded');
            $operation->set('completedAt', date('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($operation, ['silent' => true]);

            return;
        }

        $this->markOperationCompleted($operation, $response, $documentName);
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

        // Get all operations for this article, oldest first, so the most
        // recent completed upload deterministically wins for the body doc.
        $operations = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStoreUploadOperation')
            ->where(['knowledgeBaseArticleId' => $articleId])
            ->order('createdAt')
            ->find();

        $pendingCount = 0;
        $completedCount = 0;
        $failedCount = 0;
        $articleBodyDocName = null;
        $attachmentDocuments = [];
        $newDocumentNames = [];
        $retainedOperations = [];
        $oldDocumentNames = array_filter([$article->get('geminiDocumentName')]);
        foreach ((array) ($article->get('geminiAttachmentDocuments') ?? []) as $old) {
            $old = (array) $old;
            if (!empty($old['documentName'])) {
                $oldDocumentNames[] = $old['documentName'];
            }
        }

        foreach ($operations as $op) {
            $status = $op->get('status');
            // Superseded in-flight uploads are cleanup, not this publication.
            if ($op->get('discardDocument')) {
                continue;
            }
            if ($op->get('errorMessage') === 'Superseded: retained until replacement') {
                $retainedOperations[] = $op;
                if ($op->get('geminiDocumentName')) {
                    $oldDocumentNames[] = $op->get('geminiDocumentName');
                }
            }
            
            if ($status === 'Pending' || $status === 'Processing') {
                $pendingCount++;
            } elseif ($status === 'Completed') {
                $completedCount++;
                
                $docType = $op->get('documentType');
                $docName = $op->get('geminiDocumentName');
                if ($docName) {
                    $newDocumentNames[] = $docName;
                }
                
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
                // Cancelled/superseded operations (re-index housekeeping) are
                // not real failures and must not affect the article status.
                $errorMessage = (string) $op->get('errorMessage');

                if (!str_starts_with($errorMessage, 'Cancelled:') && !str_starts_with($errorMessage, 'Superseded:')) {
                    $failedCount++;
                }
            }
        }

        // If there are still pending operations, don't update article yet
        if ($pendingCount > 0) {
            return;
        }

        // Nothing meaningful to report (e.g. all operations were cancelled)
        if ($completedCount === 0 && $failedCount === 0) {
            return;
        }

        // All operations are complete (either succeeded or failed)
        if ($failedCount > 0 || !$articleBodyDocName) {
            // Do not replace a usable index with an incomplete generation.
            $article->set('geminiIndexStatus', 'Failed');
            $article->set('geminiIndexError', "Replacement incomplete ({$failedCount} failed uploads); previous index retained");
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

        if ($failedCount === 0 && $articleBodyDocName) {
            // Publish references before retiring the old generation. Failed
            // deletions remain eligible for the reconciliation safety net.
            foreach ($retainedOperations as $op) {
                $op->set('errorMessage', 'Superseded: replacement published');
                $this->entityManager->saveEntity($op, ['silent' => true]);
            }
            foreach (array_diff(array_unique($oldDocumentNames), $newDocumentNames) as $oldName) {
                if (!$this->geminiService->deleteDocument($oldName)) {
                    $this->log->warning("GoogleGemini: Replacement published; old document cleanup pending: {$oldName}");
                }
            }
        }

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



