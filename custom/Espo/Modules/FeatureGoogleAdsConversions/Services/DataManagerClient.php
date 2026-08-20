<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Services;

use Espo\Tools\OAuth\Exceptions\AccountNotFound;
use Espo\Tools\OAuth\Exceptions\NoToken;
use Espo\Tools\OAuth\Exceptions\ProviderNotAvailable;
use Espo\Tools\OAuth\Exceptions\TokenObtainingFailure;
use Espo\Tools\OAuth\TokensProvider;
use JsonException;
use Throwable;

/**
 * Minimal REST client for Data Manager v1 events:ingest.
 *
 * Authentication is OAuth-only. Ingestion requests deliberately contain no
 * Google Ads developer token or account headers; account routing belongs in
 * the immutable Destination object in the request body.
 */
class DataManagerClient
{
    private const ENDPOINT = 'https://datamanager.googleapis.com/v1/events:ingest';
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TIMEOUT_SECONDS = 30;

    /** @var list<string> */
    private const RETRYABLE_CODES = [
        'UNAVAILABLE',
        'INTERNAL',
        'DEADLINE_EXCEEDED',
        'UNKNOWN',
        'ABORTED',
        'RESOURCE_EXHAUSTED',
    ];

    public function __construct(private TokensProvider $tokensProvider) {}

    /**
     * @param array<string, mixed> $request
     */
    public function ingest(string $oAuthAccountId, array $request): DataManagerResult
    {
        try {
            $accessToken = $this->tokensProvider->get($oAuthAccountId)->getAccessToken();
        } catch (AccountNotFound) {
            return $this->localFailure('OAUTH_ACCOUNT_NOT_FOUND', 'OAuth account not found.');
        } catch (ProviderNotAvailable) {
            return $this->localFailure('OAUTH_PROVIDER_UNAVAILABLE', 'OAuth provider is inactive.');
        } catch (NoToken) {
            return $this->localFailure('OAUTH_TOKEN_MISSING', 'OAuth account has no access token.');
        } catch (TokenObtainingFailure) {
            return $this->localFailure(
                'OAUTH_TOKEN_REFRESH_FAILED',
                'OAuth access token refresh failed.',
                true,
            );
        } catch (Throwable) {
            return $this->localFailure('OAUTH_FAILURE', 'OAuth token acquisition failed.', true);
        }

        try {
            $json = json_encode(
                $request,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            return $this->localFailure('REQUEST_ENCODING_FAILED', 'Data Manager request encoding failed.');
        }

        $handle = curl_init(self::ENDPOINT);

        if ($handle === false) {
            return $this->localFailure('TRANSPORT_INIT_FAILED', 'Data Manager transport initialization failed.', true);
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $rawResponse = curl_exec($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlErrno = curl_errno($handle);
        curl_close($handle);

        if ($rawResponse === false || $curlErrno !== 0) {
            return new DataManagerResult(
                success: false,
                retryable: true,
                httpStatus: $httpStatus,
                errorCode: 'TRANSPORT_' . $curlErrno,
                errorMessage: 'Data Manager transport request failed.',
            );
        }

        try {
            $decoded = json_decode((string) $rawResponse, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new DataManagerResult(
                success: false,
                retryable: $httpStatus === 0 || $httpStatus >= 500,
                httpStatus: $httpStatus,
                errorCode: 'INVALID_RESPONSE',
                errorMessage: 'Data Manager returned an invalid JSON response.',
            );
        }

        if (!is_array($decoded)) {
            return new DataManagerResult(
                success: false,
                retryable: $httpStatus === 0 || $httpStatus >= 500,
                httpStatus: $httpStatus,
                errorCode: 'INVALID_RESPONSE',
                errorMessage: 'Data Manager returned an invalid response object.',
            );
        }

        if ($httpStatus >= 200 && $httpStatus < 300) {
            return new DataManagerResult(
                success: true,
                retryable: false,
                httpStatus: $httpStatus,
                requestId: $this->string($decoded['requestId'] ?? null, 128),
                fieldWarnings: $this->warnings($decoded['fieldWarnings'] ?? null),
            );
        }

        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $errorCode = $this->string($error['status'] ?? null, 128)
            ?? 'HTTP_' . ($httpStatus ?: 'UNKNOWN');
        $fieldErrors = $this->fieldErrors($error['details'] ?? null);

        return new DataManagerResult(
            success: false,
            retryable: in_array($errorCode, self::RETRYABLE_CODES, true)
                || $httpStatus === 408
                || $httpStatus === 429
                || $httpStatus >= 500,
            httpStatus: $httpStatus,
            requestId: $this->requestId($error['details'] ?? null),
            fieldWarnings: $fieldErrors,
            errorCode: $errorCode,
            errorMessage: $this->errorMessage($error, $fieldErrors, $httpStatus),
        );
    }

    private function localFailure(string $code, string $message, bool $retryable = false): DataManagerResult
    {
        return new DataManagerResult(
            success: false,
            retryable: $retryable,
            httpStatus: 0,
            errorCode: $code,
            errorMessage: $message,
        );
    }

    /**
     * @return list<array{field?: string, description?: string, reason?: string}>
     */
    private function warnings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $warnings = [];

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $warning = [];

            foreach (['field', 'description', 'reason'] as $key) {
                $normalized = $this->string($row[$key] ?? null, $key === 'description' ? 1000 : 255);

                if ($normalized !== null) {
                    $warning[$key] = $normalized;
                }
            }

            if ($warning !== []) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * Parse google.rpc.BadRequest field violations from the API's fast-fail
     * response without retaining the original request or response payload.
     *
     * @return list<array{field?: string, description?: string, reason?: string}>
     */
    private function fieldErrors(mixed $details): array
    {
        if (!is_array($details)) {
            return [];
        }

        $rows = [];

        foreach ($details as $detail) {
            if (!is_array($detail) || !is_array($detail['fieldViolations'] ?? null)) {
                continue;
            }

            $rows = [...$rows, ...$this->warnings($detail['fieldViolations'])];
        }

        return $rows;
    }

    private function requestId(mixed $details): ?string
    {
        if (!is_array($details)) {
            return null;
        }

        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $requestId = $this->string($detail['requestId'] ?? null, 128)
                ?? $this->string($detail['metadata']['requestId'] ?? null, 128);

            if ($requestId !== null) {
                return $requestId;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $error
     * @param list<array{field?: string, description?: string, reason?: string}> $fieldErrors
     */
    private function errorMessage(array $error, array $fieldErrors, int $httpStatus): string
    {
        $message = $this->string($error['message'] ?? null, 1500) ?? 'HTTP ' . $httpStatus;
        $paths = [];

        foreach ($fieldErrors as $fieldError) {
            $field = $fieldError['field'] ?? null;
            $reason = $fieldError['reason'] ?? null;

            if ($field !== null) {
                $paths[] = $reason !== null ? $field . ' (' . $reason . ')' : $field;
            }
        }

        if ($paths !== []) {
            $message .= ' Fields: ' . implode(', ', array_slice($paths, 0, 10)) . '.';
        }

        return mb_substr($message, 0, 2000);
    }

    private function string(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $maxLength);
    }
}
