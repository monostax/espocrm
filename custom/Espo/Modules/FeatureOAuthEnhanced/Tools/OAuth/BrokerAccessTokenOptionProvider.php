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

namespace Espo\Modules\FeatureOAuthEnhanced\Tools\OAuth;

use League\OAuth2\Client\OptionProvider\OptionProviderInterface;
use League\OAuth2\Client\Provider\AbstractProvider;
use RuntimeException;

/**
 * Shapes local → production broker token requests.
 *
 * Strips client_id / client_secret / redirect_uri so the Meta App secret
 * never leaves production, and attaches Authorization: Bearer <broker-token>.
 */
class BrokerAccessTokenOptionProvider implements OptionProviderInterface
{
    public function __construct(
        private string $brokerToken,
    ) {}

    /**
     * @param string $method
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getAccessTokenOptions($method, array $params)
    {
        if ($method !== AbstractProvider::METHOD_POST) {
            throw new RuntimeException('Meta WhatsApp OAuth broker only supports POST.');
        }

        $grantType = $params['grant_type'] ?? null;
        $code = $params['code'] ?? null;

        if ($grantType !== 'authorization_code' || !is_string($code) || $code === '') {
            throw new RuntimeException(
                'Meta WhatsApp OAuth broker only accepts authorization_code grants with a code.'
            );
        }

        return [
            'headers' => [
                'content-type' => 'application/x-www-form-urlencoded',
                'accept' => 'application/json',
                'authorization' => 'Bearer ' . $this->brokerToken,
            ],
            'body' => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
            ], '', '&', PHP_QUERY_RFC3986),
        ];
    }
}
