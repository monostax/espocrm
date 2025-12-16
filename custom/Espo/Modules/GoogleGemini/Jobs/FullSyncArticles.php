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

/**
 * Job to trigger a full synchronization of all KnowledgeBaseArticles.
 * This calls the backend Hatchet workflow for bulk processing.
 */
class FullSyncArticles implements JobDataLess
{
    private const BACKEND_API_ENDPOINT = '/api/gemini/full-sync';

    public function __construct(
        private Config $config,
        private Log $log
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function run(array $data): void
    {
        $statusFilter = $data['statusFilter'] ?? 'Published';

        $this->log->info("GoogleGemini Job: Starting full synchronization (status: {$statusFilter})");

        try {
            // Call backend API to trigger full sync workflow
            $this->callBackendFullSync($statusFilter);

            $this->log->info("GoogleGemini Job: Successfully triggered full synchronization");

        } catch (\Exception $e) {
            $this->log->error(
                "GoogleGemini Job: Failed to trigger full sync: " . 
                $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Call the backend Node.js API to trigger full sync workflow.
     * 
     * @param string $statusFilter
     */
    private function callBackendFullSync(string $statusFilter): void
    {
        // Get backend URL from config
        $backendUrl = $this->config->get('backendApiUrl') ?? 'http://localhost:3000';
        $backendUrl = rtrim($backendUrl, '/');
        
        $endpoint = $backendUrl . self::BACKEND_API_ENDPOINT;

        // Prepare request payload
        $payload = [
            'statusFilter' => $statusFilter,
        ];

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
            CURLOPT_TIMEOUT => 300, // 5 minutes timeout for full sync
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

