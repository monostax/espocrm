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

namespace Espo\Modules\Chatwoot\Entities;

use Espo\Core\ORM\Entity;

/**
 * ChatwootInboxIntegration entity.
 * Represents a unified WhatsApp channel connected to Chatwoot.
 */
class ChatwootInboxIntegration extends Entity
{
    public const ENTITY_TYPE = 'ChatwootInboxIntegration';

    // Status constants
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_CREATING = 'CREATING';
    public const STATUS_PENDING_QR = 'PENDING_QR';
    public const STATUS_CONNECTING = 'CONNECTING';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_DISCONNECTED = 'DISCONNECTED';
    public const STATUS_FAILED = 'FAILED';

    // Channel type constants
    public const CHANNEL_TYPE_WHATSAPP = 'whatsapp';
    public const CHANNEL_TYPE_WHATSAPP_QRCODE = 'whatsappQrcode';
    public const CHANNEL_TYPE_WHATSAPP_CLOUD_API = 'whatsappCloudApi';
    public const CHANNEL_TYPE_INSTAGRAM = 'instagram';

    public function getName(): ?string
    {
        return $this->get('name');
    }

    public function getStatus(): ?string
    {
        return $this->get('status');
    }

    public function getChannelType(): ?string
    {
        return $this->get('channelType');
    }

    public function getWahaSessionName(): ?string
    {
        return $this->get('wahaSessionName');
    }

    public function getWahaAppId(): ?string
    {
        return $this->get('wahaAppId');
    }

    public function getChatwootInboxId(): ?int
    {
        return $this->get('chatwootInboxId');
    }

    public function getChatwootInboxIdentifier(): ?string
    {
        return $this->get('chatwootInboxIdentifier');
    }

    public function getWhatsappId(): ?string
    {
        return $this->get('whatsappId');
    }

    public function getWhatsappName(): ?string
    {
        return $this->get('whatsappName');
    }

    public function isActive(): bool
    {
        return $this->getStatus() === self::STATUS_ACTIVE;
    }

    public function isPendingQr(): bool
    {
        return $this->getStatus() === self::STATUS_PENDING_QR;
    }

    public function isDisconnected(): bool
    {
        return $this->getStatus() === self::STATUS_DISCONNECTED;
    }

    public function isFailed(): bool
    {
        return $this->getStatus() === self::STATUS_FAILED;
    }

    public function isWhatsappQrcode(): bool
    {
        return $this->getChannelType() === self::CHANNEL_TYPE_WHATSAPP_QRCODE;
    }

    public function isWhatsappCloudApi(): bool
    {
        return $this->getChannelType() === self::CHANNEL_TYPE_WHATSAPP_CLOUD_API;
    }

    public function isInstagram(): bool
    {
        return $this->getChannelType() === self::CHANNEL_TYPE_INSTAGRAM;
    }

    public function getInstagramId(): ?string
    {
        return $this->get('instagramId');
    }

    public function getInstagramUsername(): ?string
    {
        return $this->get('instagramUsername');
    }
}
