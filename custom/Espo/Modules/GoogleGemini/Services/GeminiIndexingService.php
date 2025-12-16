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

namespace Espo\Modules\GoogleGemini\Services;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Job\QueueName;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Job\JobDataBuilder;

/**
 * Service for managing Gemini File Search indexing operations.
 * Queues jobs to be processed asynchronously by the backend.
 */
class GeminiIndexingService
{
    public function __construct(
        private Config $config,
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
            $scheduler = $this->jobSchedulerFactory->create();
            
            $jobData = JobDataBuilder::create()
                ->setClassName('Espo\\Modules\\GoogleGemini\\Jobs\\IndexArticle')
                ->setData([
                    'articleId' => $articleId,
                    'operation' => $operation,
                ])
                ->build();

            $scheduler->schedule($jobData, QueueName::E0);

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

    /**
     * Trigger a full synchronization of all published articles.
     * This is useful for bulk operations or recovery.
     * 
     * @return void
     */
    public function queueFullSync(): void
    {
        try {
            $scheduler = $this->jobSchedulerFactory->create();
            
            $jobData = JobDataBuilder::create()
                ->setClassName('Espo\\Modules\\GoogleGemini\\Jobs\\FullSyncArticles')
                ->setData([
                    'statusFilter' => 'Published',
                ])
                ->build();

            $scheduler->schedule($jobData, QueueName::Q0);

            $this->log->info("GoogleGemini: Queued full synchronization job");
        } catch (\Exception $e) {
            $this->log->error(
                "GoogleGemini: Failed to queue full sync: " . $e->getMessage()
            );
        }
    }
}

