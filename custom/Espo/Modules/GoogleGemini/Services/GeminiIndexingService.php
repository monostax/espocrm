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

namespace Espo\Modules\GoogleGemini\Services;

use Espo\Core\Utils\Log;
use Espo\Core\Job\QueueName;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Modules\GoogleGemini\Jobs\IndexArticle;

/**
 * Service for managing Gemini File Search indexing operations.
 * Queues jobs to be processed asynchronously by the backend.
 */
class GeminiIndexingService
{
    public function __construct(
        private Log $log,
        private JobSchedulerFactory $jobSchedulerFactory
    ) {}

    /**
     * Queue an article for indexing in Gemini File Search.
     * 
     * @param string $articleId The ID of the KnowledgeBaseArticle
     * @param string $operation Operation type: 'index', 'update', 'delete'
     * @return void
     */
    public function queueArticleIndexing(string $articleId, string $operation = 'index'): void
    {
        try {
            $this->jobSchedulerFactory->create()
                ->setClassName(IndexArticle::class)
                ->setQueue(QueueName::E0)
                ->setData([
                    'articleId' => $articleId,
                    'operation' => $operation,
                ])
                ->schedule();

            $this->log->debug("GoogleGemini: Queued article {$operation} for ID: {$articleId}");
        } catch (\Exception $e) {
            $this->log->error(
                "GoogleGemini: Failed to queue article indexing for {$articleId}: " . 
                $e->getMessage()
            );
        }
    }

    /**
     * Queue multiple articles for indexing.
     * 
     * @param array<string> $articleIds Array of article IDs
     * @param string $operation Operation type
     * @return void
     */
    public function queueMultipleArticles(array $articleIds, string $operation = 'index'): void
    {
        foreach ($articleIds as $articleId) {
            $this->queueArticleIndexing($articleId, $operation);
        }
    }
}



