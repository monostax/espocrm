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
     * Format custom metadata to the Gemini API expected structure.
     *
     * @param array<string, mixed> $metadata Key-value pairs
     * @return array<int, array<string, mixed>> Formatted metadata array
     */
    private function formatCustomMetadata(array $metadata): array
    {
        $formatted = [];
        foreach ($metadata as $key => $value) {
            $item = ['key' => $key];
            if (is_array($value)) {
                $item['stringListValue'] = ['values' => $value];
            } elseif (is_numeric($value) && !is_string($value)) {
                $item['numericValue'] = $value;
            } else {
                $item['stringValue'] = (string) $value;
            }
            $formatted[] = $item;
        }
        return $formatted;
    }

    /**
     * Upload content directly to a File Search Store.
     *
     * @param string $content The content to upload
     * @param string $displayName Display name for the document
     * @param array<string, mixed> $customMetadata Optional metadata (key-value pairs)
     * @param string|null $mimeType Optional MIME type (defaults to text/plain)
     * @param array<string, mixed>|null $chunkingConfig Optional chunking configuration
     * @param string|null $storeName Optional store name (uses config if not provided)
     * @return array<string, mixed>|null Operation response or null on failure
     */
    public function uploadToFileSearchStore(
        string $content,
        string $displayName,
        array $customMetadata = [],
        ?string $mimeType = null,
        ?array $chunkingConfig = null,
        ?string $storeName = null
    ): ?array {
        $apiKey = getenv('GOOGLE_GENERATIVE_AI_API_KEY');
        $fileSearchStoreName = $storeName ?? $this->config->get('googleGeminiFileSearchStoreName');

        if (!$apiKey) {
            $this->log->error('GOOGLE_GENERATIVE_AI_API_KEY environment variable not set');
            return null;
        }

        if (!$fileSearchStoreName) {
            $this->log->error('Google Gemini File Search Store name not configured');
            return null;
        }

        try {
            // Prepare metadata
            $metadata = [
                'displayName' => $displayName,
            ];

            if (!empty($customMetadata)) {
                $metadata['customMetadata'] = $this->formatCustomMetadata($customMetadata);
            }

            if ($mimeType !== null) {
                $metadata['mimeType'] = $mimeType;
            }

            if ($chunkingConfig !== null) {
                $metadata['chunkingConfig'] = $chunkingConfig;
            }

            // Upload using multipart/related
            $boundary = 'espo_' . uniqid();
            $url = self::UPLOAD_API_BASE . '/' . $fileSearchStoreName . ':uploadToFileSearchStore?key=' . $apiKey;

            // Determine content type for the file part
            $contentMimeType = $mimeType ?? 'text/plain';

            // Build multipart body
            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
            $body .= json_encode($metadata) . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$contentMimeType}\r\n\r\n";
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
     * Upload binary content directly to a File Search Store.
     * Used for file attachments (PDFs, images, documents, etc.)
     *
     * @param string $binaryContent The raw binary file content
     * @param string $displayName Display name for the document
     * @param string $mimeType MIME type of the file
     * @param array<string, mixed> $customMetadata Optional metadata (key-value pairs)
     * @param string|null $storeName Optional store name (uses config if not provided)
     * @return array<string, mixed>|null Operation response or null on failure
     */
    public function uploadBinaryToFileSearchStore(
        string $binaryContent,
        string $displayName,
        string $mimeType,
        array $customMetadata = [],
        ?string $storeName = null
    ): ?array {
        $apiKey = getenv('GOOGLE_GENERATIVE_AI_API_KEY');
        $fileSearchStoreName = $storeName ?? $this->config->get('googleGeminiFileSearchStoreName');

        if (!$apiKey) {
            $this->log->error('GOOGLE_GENERATIVE_AI_API_KEY environment variable not set');
            return null;
        }

        if (!$fileSearchStoreName) {
            $this->log->error('Google Gemini File Search Store name not configured');
            return null;
        }

        try {
            // Prepare metadata
            $metadata = [
                'displayName' => $displayName,
                'mimeType' => $mimeType,
            ];

            if (!empty($customMetadata)) {
                $metadata['customMetadata'] = $this->formatCustomMetadata($customMetadata);
            }

            // Upload using multipart/related
            $boundary = 'espo_' . uniqid();
            $url = self::UPLOAD_API_BASE . '/' . $fileSearchStoreName . ':uploadToFileSearchStore?key=' . $apiKey;

            // Build multipart body
            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
            $body .= json_encode($metadata) . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$mimeType}\r\n";
            $body .= "Content-Transfer-Encoding: binary\r\n\r\n";
            $body .= $binaryContent . "\r\n";
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

            if ($httpCode !== 200) {
                $this->log->error('Failed to upload binary to File Search Store. HTTP ' . $httpCode . ': ' . $response);
                return null;
            }

            $result = json_decode($response, true);
            
            if (!$result) {
                $this->log->error('Invalid JSON response from Gemini API for binary upload');
                return null;
            }

            return $result;

        } catch (Exception $e) {
            $this->log->error('Exception uploading binary to File Search Store: ' . $e->getMessage());
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
        $apiKey = getenv('GOOGLE_GENERATIVE_AI_API_KEY');

        if (!$apiKey) {
            $this->log->error('GOOGLE_GENERATIVE_AI_API_KEY environment variable not set');
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
     * @return array<string, mixed>|null Operation result with response data, or null on failure
     */
    public function waitForOperation(string $operationName, int $maxWaitSeconds = 60): ?array
    {
        $apiKey = getenv('GOOGLE_GENERATIVE_AI_API_KEY');

        if (!$apiKey) {
            return null;
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
                    return null;
                }

                $result = json_decode($response, true);

                if (isset($result['done']) && $result['done'] === true) {
                    if (isset($result['error'])) {
                        $this->log->error('Operation failed: ' . json_encode($result['error']));
                        return null;
                    }
                    return $result;
                }

                sleep(2);

            } catch (Exception $e) {
                $this->log->error('Exception checking operation status: ' . $e->getMessage());
                return null;
            }
        }

        $this->log->warning('Operation timed out after ' . $maxWaitSeconds . ' seconds');
        return null;
    }

    /**
     * Get the current status of an operation (single non-blocking call).
     * Use this for polling operations from a scheduled job.
     *
     * @param string $operationName Operation name from upload response
     * @return array<string, mixed>|null Operation status with 'done' boolean, or null on API error
     */
    public function getOperationStatus(string $operationName): ?array
    {
        $apiKey = getenv('GOOGLE_GENERATIVE_AI_API_KEY');

        if (!$apiKey) {
            $this->log->error('GOOGLE_GENERATIVE_AI_API_KEY environment variable not set');
            return null;
        }

        try {
            $url = self::API_BASE . '/' . $operationName . '?key=' . $apiKey;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->log->warning('Failed to get operation status. HTTP ' . $httpCode . ': ' . $response);
                return null;
            }

            $result = json_decode($response, true);

            if (!$result) {
                $this->log->error('Invalid JSON response when getting operation status');
                return null;
            }

            return $result;

        } catch (Exception $e) {
            $this->log->error('Exception getting operation status: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get information about a specific document.
     *
     * @param string $documentName Full document name (e.g., "fileSearchStores/xxx/documents/yyy")
     * @return array<string, mixed>|null Document data or null on failure
     */
    public function getDocument(string $documentName): ?array
    {
        $apiKey = getenv('GOOGLE_GENERATIVE_AI_API_KEY');

        if (!$apiKey) {
            $this->log->error('GOOGLE_GENERATIVE_AI_API_KEY environment variable not set');
            return null;
        }

        try {
            $url = self::API_BASE . '/' . $documentName . '?key=' . $apiKey;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->log->warning('Failed to get document. HTTP ' . $httpCode . ': ' . $response);
                return null;
            }

            return json_decode($response, true);

        } catch (Exception $e) {
            $this->log->error('Exception getting document: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * List all documents in a File Search Store.
     *
     * @param int $pageSize Number of documents per page (max 20)
     * @param string|null $pageToken Token for pagination
     * @param string|null $storeName Optional store name (uses config if not provided)
     * @return array<string, mixed>|null Response with documents array and nextPageToken, or null on failure
     */
    public function listDocuments(int $pageSize = 20, ?string $pageToken = null, ?string $storeName = null): ?array
    {
        $apiKey = getenv('GOOGLE_GENERATIVE_AI_API_KEY');
        $fileSearchStoreName = $storeName ?? $this->config->get('googleGeminiFileSearchStoreName');

        if (!$apiKey) {
            $this->log->error('GOOGLE_GENERATIVE_AI_API_KEY environment variable not set');
            return null;
        }

        if (!$fileSearchStoreName) {
            $this->log->error('Google Gemini File Search Store name not configured');
            return null;
        }

        try {
            $url = self::API_BASE . '/' . $fileSearchStoreName . '/documents?key=' . $apiKey;
            $url .= '&pageSize=' . min($pageSize, 20);

            if ($pageToken !== null) {
                $url .= '&pageToken=' . urlencode($pageToken);
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->log->warning('Failed to list documents. HTTP ' . $httpCode . ': ' . $response);
                return null;
            }

            return json_decode($response, true);

        } catch (Exception $e) {
            $this->log->error('Exception listing documents: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get information about a File Search Store.
     *
     * @param string|null $storeName Optional store name (uses config if not provided)
     * @return array<string, mixed>|null Store data including document counts, or null on failure
     */
    public function getFileSearchStore(?string $storeName = null): ?array
    {
        $apiKey = getenv('GOOGLE_GENERATIVE_AI_API_KEY');
        $fileSearchStoreName = $storeName ?? $this->config->get('googleGeminiFileSearchStoreName');

        if (!$apiKey) {
            $this->log->error('GOOGLE_GENERATIVE_AI_API_KEY environment variable not set');
            return null;
        }

        if (!$fileSearchStoreName) {
            $this->log->error('Google Gemini File Search Store name not configured');
            return null;
        }

        try {
            $url = self::API_BASE . '/' . $fileSearchStoreName . '?key=' . $apiKey;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->log->warning('Failed to get File Search Store. HTTP ' . $httpCode . ': ' . $response);
                return null;
            }

            return json_decode($response, true);

        } catch (Exception $e) {
            $this->log->error('Exception getting File Search Store: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a document is in active state (ready for queries).
     *
     * @param string $documentName Full document name
     * @return bool True if document is active
     */
    public function isDocumentActive(string $documentName): bool
    {
        $document = $this->getDocument($documentName);

        if ($document === null) {
            return false;
        }

        return isset($document['state']) && $document['state'] === 'STATE_ACTIVE';
    }
}



