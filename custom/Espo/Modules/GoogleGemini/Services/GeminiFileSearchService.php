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

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Exception;

/**
 * Service to handle Google Gemini File Search Store operations.
 */
class GeminiFileSearchService
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';
    private const UPLOAD_API_BASE = 'https://generativelanguage.googleapis.com/upload/v1beta';

    public function __construct(
        private Config $config,
        private Log $log
    ) {}

    /**
     * Upload content directly to a File Search Store.
     *
     * @param string $content The content to upload
     * @param string $displayName Display name for the document
     * @param array<string, mixed> $customMetadata Optional metadata
     * @return array<string, mixed>|null Operation response or null on failure
     */
    public function uploadToFileSearchStore(
        string $content,
        string $displayName,
        array $customMetadata = []
    ): ?array {
        $apiKey = $this->config->get('googleGeminiApiKey');
        $fileSearchStoreName = $this->config->get('googleGeminiFileSearchStoreName');

        if (!$apiKey) {
            $this->log->error('Google Gemini API key not configured');
            return null;
        }

        if (!$fileSearchStoreName) {
            $this->log->error('Google Gemini File Search Store name not configured');
            return null;
        }

        try {
            // Create temporary file with content
            $tmpFile = tmpfile();
            $tmpPath = stream_get_meta_data($tmpFile)['uri'];
            fwrite($tmpFile, $content);
            fseek($tmpFile, 0);

            // Prepare metadata
            $metadata = [
                'displayName' => $displayName,
            ];

            if (!empty($customMetadata)) {
                $metadata['customMetadata'] = $customMetadata;
            }

            // Upload using multipart/related
            $boundary = 'espo_' . uniqid();
            $url = self::UPLOAD_API_BASE . '/' . $fileSearchStoreName . ':uploadToFileSearchStore?key=' . $apiKey;

            // Build multipart body
            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
            $body .= json_encode($metadata) . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain\r\n\r\n";
            $body .= $content . "\r\n";
            $body .= "--{$boundary}--\r\n";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: multipart/related; boundary=' . $boundary,
                'X-Goog-Upload-Protocol: multipart',
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            fclose($tmpFile);

            if ($httpCode !== 200) {
                $this->log->error('Failed to upload to File Search Store. HTTP ' . $httpCode . ': ' . $response);
                return null;
            }

            $result = json_decode($response, true);
            
            if (!$result) {
                $this->log->error('Invalid JSON response from Gemini API');
                return null;
            }

            return $result;

        } catch (Exception $e) {
            $this->log->error('Exception uploading to File Search Store: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a document from the File Search Store.
     *
     * @param string $documentName Full document name (e.g., "fileSearchStores/xxx/documents/yyy")
     * @return bool Success status
     */
    public function deleteDocument(string $documentName): bool
    {
        $apiKey = $this->config->get('googleGeminiApiKey');

        if (!$apiKey) {
            $this->log->error('Google Gemini API key not configured');
            return false;
        }

        try {
            $url = self::API_BASE . '/' . $documentName . '?key=' . $apiKey . '&force=true';

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->log->warning('Failed to delete document from File Search Store. HTTP ' . $httpCode);
                return false;
            }

            return true;

        } catch (Exception $e) {
            $this->log->error('Exception deleting document from File Search Store: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Wait for an operation to complete.
     *
     * @param string $operationName Operation name from upload response
     * @param int $maxWaitSeconds Maximum seconds to wait
     * @return bool True if operation completed successfully
     */
    public function waitForOperation(string $operationName, int $maxWaitSeconds = 60): bool
    {
        $apiKey = $this->config->get('googleGeminiApiKey');

        if (!$apiKey) {
            return false;
        }

        $startTime = time();

        while (time() - $startTime < $maxWaitSeconds) {
            try {
                $url = self::API_BASE . '/' . $operationName . '?key=' . $apiKey;

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    return false;
                }

                $result = json_decode($response, true);

                if (isset($result['done']) && $result['done'] === true) {
                    if (isset($result['error'])) {
                        $this->log->error('Operation failed: ' . json_encode($result['error']));
                        return false;
                    }
                    return true;
                }

                sleep(2);

            } catch (Exception $e) {
                $this->log->error('Exception checking operation status: ' . $e->getMessage());
                return false;
            }
        }

        $this->log->warning('Operation timed out after ' . $maxWaitSeconds . ' seconds');
        return false;
    }
}



