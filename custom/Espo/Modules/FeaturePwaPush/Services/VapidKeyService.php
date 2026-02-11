<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeaturePwaPush\Services;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Minishlink\WebPush\VAPID;

/**
 * Service for managing VAPID keys for Web Push authentication.
 */
class VapidKeyService
{
    private const CONFIG_KEY = 'pwaPush';
    private const VAPID_KEYS_KEY = 'vapidKeys';
    private const SUBJECT_KEY = 'subject';

    public function __construct(
        private Config $config,
        private Log $log
    ) {}

    /**
     * Get the VAPID public key.
     */
    public function getPublicKey(): ?string
    {
        $keys = $this->getKeys();
        return $keys['publicKey'] ?? null;
    }

    /**
     * Get the VAPID private key.
     */
    public function getPrivateKey(): ?string
    {
        $keys = $this->getKeys();
        return $keys['privateKey'] ?? null;
    }

    /**
     * Get the VAPID subject (mailto: or https:// URL).
     */
    public function getSubject(): string
    {
        $config = $this->config->get(self::CONFIG_KEY);
        return $config[self::SUBJECT_KEY] ?? 'mailto:admin@example.com';
    }

    /**
     * Check if VAPID keys exist.
     */
    public function hasKeys(): bool
    {
        $keys = $this->getKeys();
        return !empty($keys['publicKey']) && !empty($keys['privateKey']);
    }

    /**
     * Generate and store new VAPID keys.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public function generateKeys(): array
    {
        try {
            // VAPID::createVapidKeys() already returns base64url-encoded strings.
            // Store them as-is — do NOT re-encode.
            $keys = VAPID::createVapidKeys();

            $this->storeKeys($keys['publicKey'], $keys['privateKey']);

            $this->log->info("VAPID keys generated successfully");

            return [
                'publicKey' => $keys['publicKey'],
                'privateKey' => $keys['privateKey'],
            ];
        } catch (\Throwable $e) {
            $this->log->error("Failed to generate VAPID keys: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Ensure VAPID keys exist, generate if not.
     */
    public function ensureKeys(): void
    {
        if (!$this->hasKeys()) {
            $this->generateKeys();
        }
    }

    /**
     * Get stored VAPID keys from config.
     */
    private function getKeys(): array
    {
        $config = $this->config->get(self::CONFIG_KEY);
        return $config[self::VAPID_KEYS_KEY] ?? [];
    }

    /**
     * Store VAPID keys in config.
     */
    private function storeKeys(string $publicKey, string $privateKey): void
    {
        $config = $this->config->get(self::CONFIG_KEY) ?? [];
        $config[self::VAPID_KEYS_KEY] = [
            'publicKey' => $publicKey,
            'privateKey' => $privateKey,
        ];
        $this->config->set(self::CONFIG_KEY, $config);
        $this->config->save();
    }

}
