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

namespace Espo\Modules\FeatureOAuthEnhanced\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureOAuthEnhanced\Services\MetaWhatsAppOAuthBrokerService;
use Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth\MetaWhatsAppOAuthBrokerConfig;

/**
 * Production S2S endpoint for local CRM Embedded Signup code exchange.
 *
 * POST /api/v1/MetaWhatsAppOAuthBroker/exchange
 * Authorization: Bearer <META_WHATSAPP_OAUTH_BROKER_TOKEN>
 * Content-Type: application/x-www-form-urlencoded
 * Body: grant_type=authorization_code&code=...
 *
 * Always exchanges against msx_wa_coex_01. Never accepts provider IDs or
 * client secrets from the caller. Never logs codes/tokens.
 */
class MetaWhatsAppOAuthBroker
{
    private const MAX_CODE_LENGTH = 4096;

    public function __construct(
        private MetaWhatsAppOAuthBrokerService $service,
        private MetaWhatsAppOAuthBrokerConfig $config,
        private Log $log,
    ) {}

    public function postActionExchange(Request $request, Response $response): void
    {
        $this->applyNoStoreHeaders($response);

        if (!$this->authenticate($request, $response)) {
            return;
        }

        $parsed = $this->parseBody($request, $response);

        if ($parsed === null) {
            return;
        }

        try {
            $payload = $this->service->exchangeAuthorizationCode($parsed);
        } catch (Error $e) {
            $message = $e->getMessage();

            if ($message === 'invalid_grant') {
                $this->writeError($response, 400, 'invalid_grant', 'Authorization code rejected.');

                return;
            }

            if ($message === 'temporarily_unavailable') {
                $this->writeError(
                    $response,
                    503,
                    'temporarily_unavailable',
                    'Token exchange temporarily unavailable.'
                );

                return;
            }

            $this->log->error('MetaWhatsAppOAuthBroker: configuration error during exchange.');
            $this->writeError($response, 503, 'temporarily_unavailable', 'Token exchange unavailable.');

            return;
        }

        $response->setStatus(200);
        $response->setHeader('Content-Type', 'application/json');
        $response->writeBody(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function authenticate(Request $request, Response $response): bool
    {
        $expected = $this->config->getToken();

        if ($expected === null) {
            $this->log->warning('MetaWhatsAppOAuthBroker: broker token is not configured.');
            $this->writeUnauthorized($response);

            return false;
        }

        $header = trim((string) ($request->getHeader('Authorization') ?? ''));

        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            $this->writeUnauthorized($response);

            return false;
        }

        $provided = $matches[1];

        if (!hash_equals($expected, $provided)) {
            $this->writeUnauthorized($response);

            return false;
        }

        return true;
    }

    private function parseBody(Request $request, Response $response): ?string
    {
        $contentType = strtolower((string) ($request->getHeader('Content-Type') ?? ''));

        if (!str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $this->writeError(
                $response,
                400,
                'invalid_request',
                'Content-Type must be application/x-www-form-urlencoded.'
            );

            return null;
        }

        $raw = $request->getBodyContents() ?? '';
        $params = [];
        parse_str($raw, $params);

        if (!is_array($params)) {
            $this->writeError($response, 400, 'invalid_request', 'Malformed body.');

            return null;
        }

        $allowed = ['grant_type', 'code'];

        foreach (array_keys($params) as $key) {
            if (!in_array($key, $allowed, true)) {
                $this->writeError($response, 400, 'invalid_request', 'Unexpected field.');

                return null;
            }
        }

        $grantType = $params['grant_type'] ?? null;
        $code = $params['code'] ?? null;

        if ($grantType !== 'authorization_code') {
            $this->writeError(
                $response,
                400,
                'invalid_request',
                'grant_type must be authorization_code.'
            );

            return null;
        }

        if (!is_string($code) || $code === '' || strlen($code) > self::MAX_CODE_LENGTH) {
            $this->writeError($response, 400, 'invalid_request', 'code is required.');

            return null;
        }

        return $code;
    }

    private function writeUnauthorized(Response $response): void
    {
        $response->setHeader('WWW-Authenticate', 'Bearer');
        $this->writeError($response, 401, 'invalid_client', 'Unauthorized.');
    }

    private function writeError(
        Response $response,
        int $status,
        string $error,
        string $description,
    ): void {
        $response->setStatus($status);
        $response->setHeader('Content-Type', 'application/json');
        $response->writeBody(json_encode([
            'error' => $error,
            'error_description' => $description,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function applyNoStoreHeaders(Response $response): void
    {
        $response->setHeader('Cache-Control', 'no-store, private');
        $response->setHeader('Pragma', 'no-cache');
    }
}
