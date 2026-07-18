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

use Espo\Core\Exceptions\Error;

/**
 * Process env for the Meta WhatsApp OAuth token broker.
 *
 * Production: META_WHATSAPP_OAUTH_BROKER_TOKEN only
 *   (authenticates inbound S2S exchanges; direct Meta token URL stays).
 *
 * Local/dev: META_WHATSAPP_OAUTH_BROKER_URL + META_WHATSAPP_OAUTH_BROKER_TOKEN
 *   (routes the Coexistence authorization-code exchange to production).
 *
 * Never log these values.
 */
class MetaWhatsAppOAuthBrokerConfig
{
    private const ENV_URL = 'META_WHATSAPP_OAUTH_BROKER_URL';
    private const ENV_TOKEN = 'META_WHATSAPP_OAUTH_BROKER_TOKEN';

    public function getUrl(): ?string
    {
        $value = $this->read(self::ENV_URL);

        if ($value === null) {
            return null;
        }

        if (!str_starts_with($value, 'https://')) {
            throw new Error('Meta WhatsApp OAuth broker URL must use HTTPS.');
        }

        return $value;
    }

    public function getToken(): ?string
    {
        return $this->read(self::ENV_TOKEN);
    }

    public function isClientEnabled(): bool
    {
        return $this->getUrl() !== null;
    }

    /**
     * @throws Error when URL is set but token is missing
     */
    public function requireClientCredentials(): array
    {
        $url = $this->getUrl();

        if ($url === null) {
            throw new Error('Meta WhatsApp OAuth broker URL is not configured.');
        }

        $token = $this->getToken();

        if ($token === null) {
            throw new Error(
                'Meta WhatsApp OAuth broker URL is set but META_WHATSAPP_OAUTH_BROKER_TOKEN is missing.'
            );
        }

        return [$url, $token];
    }

    private function read(string $name): ?string
    {
        $raw = getenv($name);

        if ($raw === false) {
            return null;
        }

        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }
}
