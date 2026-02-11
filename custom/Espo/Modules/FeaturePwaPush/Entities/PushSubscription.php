<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\FeaturePwaPush\Entities;

use Espo\Core\ORM\Entity;

/**
 * PushSubscription entity stores browser push subscription data for each user device.
 */
class PushSubscription extends Entity
{
    public const ENTITY_TYPE = 'PushSubscription';

    /**
     * Get the user ID associated with this subscription.
     */
    public function getUserId(): ?string
    {
        return $this->get('userId');
    }

    /**
     * Get the push endpoint URL.
     */
    public function getEndpoint(): string
    {
        return $this->get('endpoint');
    }

    /**
     * Get the P256DH public key.
     */
    public function getP256dhKey(): string
    {
        return $this->get('p256dhKey');
    }

    /**
     * Get the auth secret key.
     */
    public function getAuthKey(): string
    {
        return $this->get('authKey');
    }

    /**
     * Get the user agent string.
     */
    public function getUserAgent(): ?string
    {
        return $this->get('userAgent');
    }

    /**
     * Get the device name.
     */
    public function getDeviceName(): ?string
    {
        return $this->get('deviceName');
    }

    /**
     * Check if the subscription is active.
     */
    public function isActive(): bool
    {
        return (bool) $this->get('isActive');
    }

    /**
     * Get the last used timestamp.
     */
    public function getLastUsedAt(): ?string
    {
        return $this->get('lastUsedAt');
    }

    /**
     * Update the last used timestamp to now.
     */
    public function updateLastUsed(): void
    {
        $this->set('lastUsedAt', date('Y-m-d H:i:s'));
    }

    /**
     * Deactivate the subscription.
     */
    public function deactivate(): void
    {
        $this->set('isActive', false);
    }
}
