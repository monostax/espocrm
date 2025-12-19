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

        // Only index published articles
        $status = $entity->get('status');
        if ($status !== 'Published') {
            $this->log->debug("GoogleGemini: Skipping indexing for article {$entity->getId()} with status: {$status}");
            return;
        }

        // Determine operation type
        $operation = $entity->isNew() ? 'index' : 'update';

        // Check if content was actually modified (for updates)
        if (!$entity->isNew()) {
            $hasContentChange = $entity->isAttributeChanged('name') ||
                              $entity->isAttributeChanged('body') ||
                              $entity->isAttributeChanged('bodyPlain') ||
                              $entity->isAttributeChanged('description') ||
                              $entity->isAttributeChanged('language') ||
                              $entity->isAttributeChanged('status');

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

        // Only attempt deletion if the article had a Gemini document
        $geminiDocumentName = $entity->get('geminiDocumentName');
        if (!$geminiDocumentName) {
            $this->log->debug("GoogleGemini: Skipping deletion for article {$entity->getId()} - not indexed");
            return;
        }

        $this->log->info("GoogleGemini: Queueing deletion for article: {$entity->getId()}");
        
        $this->indexingService->queueArticleIndexing(
            $entity->getId(),
            'delete',
            $geminiDocumentName
        );
    }
}



