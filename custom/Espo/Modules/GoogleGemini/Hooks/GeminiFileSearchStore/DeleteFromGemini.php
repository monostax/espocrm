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

namespace Espo\Modules\GoogleGemini\Hooks\GeminiFileSearchStore;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\ORM\Repository\Option\RemoveOption;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Entity;
use Espo\Core\Utils\Log;

/**
 * Hook to delete GeminiFileSearchStore from Gemini API when removed from EspoCRM.
 * 
 * This hook ensures that mass delete operations properly clean up stores from the Gemini API.
 * It uses force=true to delete the store even if it contains documents.
 */
class DeleteFromGemini implements AfterRemove
{
    public static int $order = 10;

    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private Log $log
    ) {}

    /**
     * After a store is removed from EspoCRM, delete it from Gemini API.
     * 
     * @param Entity $entity The GeminiFileSearchStore entity
     * @param RemoveOptions $options Remove options
     */
    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        // Skip if this is a silent remove
        if ($options->get(RemoveOption::SILENT)) {
            return;
        }

        $geminiStoreName = $entity->get('geminiStoreName');

        if (!$geminiStoreName) {
            $this->log->debug("GoogleGemini: Skipping Gemini deletion for store {$entity->getId()} - no geminiStoreName");
            return;
        }

        $this->log->info("GoogleGemini: Deleting store from Gemini API: {$geminiStoreName}");

        $success = $this->deleteFromGemini($geminiStoreName);

        if ($success) {
            $this->log->info("GoogleGemini: Successfully deleted store from Gemini: {$geminiStoreName}");
        } else {
            $this->log->warning("GoogleGemini: Failed to delete store from Gemini: {$geminiStoreName}");
        }
    }

    /**
     * Delete a File Search Store from Gemini API.
     */
    private function deleteFromGemini(string $storeName): bool
    {
        $apiKey = getenv('GOOGLE_GENERATIVE_AI_API_KEY') ?: null;

        if (!$apiKey) {
            $this->log->error('GoogleGemini: GOOGLE_GENERATIVE_AI_API_KEY environment variable not set');
            return false;
        }

        try {
            $url = self::API_BASE . '/' . $storeName . '?key=' . $apiKey . '&force=true';

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->log->warning("GoogleGemini: Failed to delete store from Gemini. HTTP {$httpCode}: {$response}");
                return false;
            }

            return true;

        } catch (\Exception $e) {
            $this->log->error('GoogleGemini: Exception deleting store from Gemini: ' . $e->getMessage());
            return false;
        }
    }
}
