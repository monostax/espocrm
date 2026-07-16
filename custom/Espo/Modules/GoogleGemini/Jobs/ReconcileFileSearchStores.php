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
use Espo\Modules\GoogleGemini\Services\GeminiFileSearchService;

/**
 * Scheduled job to reconcile Gemini File Search Stores with CRM references.
 *
 * Lists every document in each GeminiFileSearchStore and deletes documents
 * that are not referenced by any KnowledgeBaseArticle (via geminiDocumentName
 * or geminiAttachmentDocuments). This is a safety net for orphans created by
 * crashed jobs, lost operation results or races between re-indexes.
 *
 * The decision is based solely on the document resource name - custom
 * metadata (e.g. articleId) on the Gemini documents is intentionally ignored.
 *
 * Safety measures to avoid racing in-flight indexing:
 * - Documents created within the grace period are never deleted.
 * - Documents tracked by Pending/Processing operations, or by operations
 *   created within the grace period, are treated as referenced.
 */
class ReconcileFileSearchStores implements Job
{
    /** Documents/operations newer than this many hours are left untouched. */
    private const GRACE_PERIOD_HOURS = 24;

    public function __construct(
        private EntityManager $entityManager,
        private GeminiFileSearchService $geminiService,
        private Log $log
    ) {}

    public function run(Data $data): void
    {
        $stores = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStore')
            ->where(['geminiStoreName!=' => null])
            ->find();

        if (count($stores) === 0) {
            $this->log->debug('GoogleGemini ReconcileFileSearchStores: No stores to reconcile');
            return;
        }

        $referenced = $this->collectReferencedDocumentNames();

        $totalDeleted = 0;
        $totalFailed = 0;

        foreach ($stores as $store) {
            $storeName = $store->get('geminiStoreName');

            if (!$storeName) {
                continue;
            }

            try {
                [$deleted, $failed] = $this->reconcileStore($storeName, $referenced);

                $totalDeleted += $deleted;
                $totalFailed += $failed;
            } catch (\Exception $e) {
                $this->log->error(
                    "GoogleGemini ReconcileFileSearchStores: Error reconciling store {$storeName}: " .
                    $e->getMessage()
                );
            }
        }

        if ($totalDeleted > 0 || $totalFailed > 0) {
            $this->log->info(
                "GoogleGemini ReconcileFileSearchStores: Deleted {$totalDeleted} orphaned document(s)" .
                ($totalFailed > 0 ? ", {$totalFailed} deletion(s) failed (will retry on next run)" : "")
            );
        } else {
            $this->log->debug('GoogleGemini ReconcileFileSearchStores: No orphaned documents found');
        }
    }

    /**
     * Reconcile a single store: delete unreferenced documents older than the
     * grace period.
     *
     * @param array<string, bool> $referenced Set of referenced document names
     * @return array{0: int, 1: int} [deletedCount, failedCount]
     */
    private function reconcileStore(string $storeName, array $referenced): array
    {
        $cutoff = time() - self::GRACE_PERIOD_HOURS * 3600;

        $deleted = 0;
        $failed = 0;
        $pageToken = null;

        do {
            $response = $this->geminiService->listDocuments(20, $pageToken, $storeName);

            if ($response === null) {
                $this->log->warning("GoogleGemini ReconcileFileSearchStores: Failed to list documents for store {$storeName}");
                break;
            }

            foreach ($response['documents'] ?? [] as $document) {
                $documentName = $document['name'] ?? null;

                if (!$documentName || isset($referenced[$documentName])) {
                    continue;
                }

                // Never touch recently created documents - their article
                // references may not have been recorded yet.
                $createTime = isset($document['createTime'])
                    ? strtotime($document['createTime'])
                    : false;

                if ($createTime === false || $createTime > $cutoff) {
                    continue;
                }

                if ($this->geminiService->deleteDocument($documentName)) {
                    $deleted++;
                    $this->log->info(
                        "GoogleGemini ReconcileFileSearchStores: Deleted orphaned document {$documentName}" .
                        (isset($document['displayName']) ? " ({$document['displayName']})" : "")
                    );
                } else {
                    $failed++;
                }
            }

            $pageToken = $response['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        return [$deleted, $failed];
    }

    /**
     * Collect all document names referenced in the CRM.
     *
     * Includes:
     * - geminiDocumentName and geminiAttachmentDocuments of all non-deleted articles
     * - documents tracked by Pending/Processing upload operations
     * - documents tracked by operations created within the grace period
     *   (their article references may lag behind)
     *
     * @return array<string, bool> Set of document names
     */
    private function collectReferencedDocumentNames(): array
    {
        $referenced = [];

        $articles = $this->entityManager
            ->getRDBRepository('KnowledgeBaseArticle')
            ->select(['id', 'geminiDocumentName', 'geminiAttachmentDocuments'])
            ->find();

        foreach ($articles as $article) {
            $documentName = $article->get('geminiDocumentName');

            if ($documentName) {
                $referenced[$documentName] = true;
            }

            foreach ((array) ($article->get('geminiAttachmentDocuments') ?? []) as $doc) {
                if (is_object($doc)) {
                    $doc = (array) $doc;
                }

                if (is_array($doc) && !empty($doc['documentName'])) {
                    $referenced[$doc['documentName']] = true;
                }
            }
        }

        $graceCutoff = date('Y-m-d H:i:s', time() - self::GRACE_PERIOD_HOURS * 3600);

        $operations = $this->entityManager
            ->getRDBRepository('GeminiFileSearchStoreUploadOperation')
            ->select(['id', 'geminiDocumentName'])
            ->where([
                'geminiDocumentName!=' => null,
                'OR' => [
                    ['status' => ['Pending', 'Processing']],
                    ['createdAt>=' => $graceCutoff],
                ],
            ])
            ->find();

        foreach ($operations as $operation) {
            $documentName = $operation->get('geminiDocumentName');

            if ($documentName) {
                $referenced[$documentName] = true;
            }
        }

        return $referenced;
    }
}
