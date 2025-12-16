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

namespace Espo\Modules\GoogleGemini\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Job to index a single KnowledgeBaseArticle in Gemini File Search.
 * This job is queued by the IndexInGemini hook and executed asynchronously.
 */
class IndexArticle implements JobDataLess
{
    private const BACKEND_API_ENDPOINT = '/api/gemini/index-article';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function run(array $data): void
    {
        $articleId = $data['articleId'] ?? null;
        $operation = $data['operation'] ?? 'index';

        if (!$articleId) {
            $this->log->error("GoogleGemini Job: No article ID provided");
            return;
        }

        $this->log->info("GoogleGemini Job: Processing {$operation} for article: {$articleId}");

        try {
            // Get the article from database
            $article = $this->entityManager->getEntityById('KnowledgeBaseArticle', $articleId);

            if (!$article && $operation !== 'delete') {
                $this->log->error("GoogleGemini Job: Article not found: {$articleId}");
                return;
            }

            // Call backend API to trigger indexing
            $this->callBackendIndexing($articleId, $operation, $article);

            $this->log->info("GoogleGemini Job: Successfully processed {$operation} for article: {$articleId}");

        } catch (\Exception $e) {
            $this->log->error(
                "GoogleGemini Job: Failed to process article {$articleId}: " . 
                $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Call the backend Node.js API to trigger Gemini indexing.
     * 
     * @param string $articleId
     * @param string $operation
     * @param \Espo\ORM\Entity|null $article
     */
    private function callBackendIndexing(string $articleId, string $operation, $article): void
    {
        // Get backend URL from config
        $backendUrl = $this->config->get('backendApiUrl') ?? 'http://localhost:3000';
        $backendUrl = rtrim($backendUrl, '/');
        
        $endpoint = $backendUrl . self::BACKEND_API_ENDPOINT;

        // Prepare request payload
        $payload = [
            'articleId' => $articleId,
            'operation' => $operation,
        ];

        // For index/update operations, include article data
        if ($article && in_array($operation, ['index', 'update'])) {
            $payload['article'] = [
                'id' => $article->getId(),
                'name' => $article->get('name'),
                'status' => $article->get('status'),
                'language' => $article->get('language'),
                'type' => $article->get('type'),
                'description' => $article->get('description'),
                'body' => $article->get('body'),
                'bodyPlain' => $article->get('bodyPlain'),
                'publishDate' => $article->get('publishDate')?->format('Y-m-d'),
            ];
        }

        // Make HTTP request to backend
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 120, // 2 minutes timeout
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Backend API request failed: {$error}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception(
                "Backend API returned error code {$httpCode}: {$response}"
            );
        }

        $this->log->debug("GoogleGemini Job: Backend API response: {$response}");
    }
}

