<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\GoogleGemini\Hooks\KnowledgeBaseArticle;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\ORM\Repository\Option\RemoveOption;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Entity;
use Espo\Modules\GoogleGemini\Services\GeminiIndexingService;
use Espo\Core\Utils\Log;

/**
 * Hook to automatically index KnowledgeBaseArticle in Google Gemini File Search.
 * 
 * This hook triggers indexing operations when articles are:
 * - Created: Indexes the new article
 * - Updated: Re-indexes the article if content changed
 * - Deleted: Removes the article from the search index
 */
class IndexInGemini implements AfterSave, AfterRemove
{
    public static int $order = 10;

    public function __construct(
        private GeminiIndexingService $indexingService,
        private Log $log
    ) {}

    /**
     * After an article is saved, queue it for indexing in Gemini.
     * 
     * @param Entity $entity The KnowledgeBaseArticle entity
     * @param SaveOptions $options Save options
     */
    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        // Skip if this is a silent save
        if ($options->get(SaveOption::SILENT)) {
            return;
        }

        // Skip if indexing should be skipped (custom flag)
        if ($options->get('skipGeminiIndexing')) {
            return;
        }

        // Only index published articles. Any other status must be removed
        // from the file search store if it was previously indexed.
        $status = $entity->get('status');
        if ($status !== 'Published') {
            $this->handleNotPublished($entity, $status);
            return;
        }

        // Determine operation type
        $operation = $entity->isNew() ? 'index' : 'update';

        // Check if content was actually modified (for updates)
        if (!$entity->isNew()) {
            $hasContentChange = $entity->isAttributeChanged('name') ||
                              $entity->isAttributeChanged('body') ||
                              $entity->isAttributeChanged('bodyPlain') ||
                              $entity->isAttributeChanged('bodyFormat') ||
                              $entity->isAttributeChanged('bodyEditorState') ||
                              $entity->isAttributeChanged('description') ||
                              $entity->isAttributeChanged('language') ||
                              $entity->isAttributeChanged('status') ||
                              $entity->isAttributeChanged('attachmentsIds');

            if (!$hasContentChange) {
                $this->log->debug("GoogleGemini: Skipping indexing for article {$entity->getId()} - no content changes");
                return;
            }
        }

        $this->log->info("GoogleGemini: Queueing {$operation} for article: {$entity->getId()}");
        
        $this->indexingService->queueArticleIndexing(
            $entity->getId(),
            $operation
        );
    }

    /**
     * Handle an article that is saved with a status other than Published.
     * If the article has (or may have) content in the Gemini file search store,
     * queue a delete operation to remove it.
     */
    private function handleNotPublished(Entity $entity, ?string $status): void
    {
        if (!$this->hasGeminiPresence($entity)) {
            $this->log->debug(
                "GoogleGemini: Skipping indexing for article {$entity->getId()} with status: {$status}"
            );

            return;
        }

        $this->log->info(
            "GoogleGemini: Article {$entity->getId()} status changed to '{$status}', " .
            "queueing removal from file search store"
        );

        $this->indexingService->queueArticleIndexing(
            $entity->getId(),
            'delete'
        );
    }

    /**
     * Whether the article has (or may still get) documents in the Gemini file search store.
     * Covers indexed documents as well as in-flight (Pending) upload operations.
     */
    private function hasGeminiPresence(Entity $entity): bool
    {
        if ($entity->get('geminiDocumentName')) {
            return true;
        }

        $attachmentDocuments = $entity->get('geminiAttachmentDocuments');
        if (!empty($attachmentDocuments)) {
            return true;
        }

        // Pending means uploads are in flight; documents may appear after the
        // operations complete, so a delete still has to be queued.
        return in_array($entity->get('geminiIndexStatus'), ['Pending', 'Indexed'], true);
    }

    /**
     * After an article is removed, queue it for deletion from Gemini.
     * 
     * @param Entity $entity The KnowledgeBaseArticle entity
     * @param RemoveOptions $options Remove options
     */
    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        // Skip if this is a silent remove
        if ($options->get(RemoveOption::SILENT)) {
            return;
        }

        // Only attempt deletion if the article had (or may still get) Gemini documents
        if (!$this->hasGeminiPresence($entity)) {
            $this->log->debug("GoogleGemini: Skipping deletion for article {$entity->getId()} - not indexed");
            return;
        }

        $geminiDocumentName = $entity->get('geminiDocumentName');
        $geminiAttachmentDocuments = $entity->get('geminiAttachmentDocuments');

        $this->log->info("GoogleGemini: Queueing deletion for article: {$entity->getId()}");

        $this->indexingService->queueArticleIndexing(
            $entity->getId(),
            'delete',
            $geminiDocumentName,
            $geminiAttachmentDocuments ?? []
        );
    }
}



