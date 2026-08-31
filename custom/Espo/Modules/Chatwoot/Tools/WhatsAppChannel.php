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

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools;

/**
 * Capability matrix for WhatsApp channel types on outbound send paths.
 *
 * Meta Cloud API / Coexistence inboxes are template-capable and carry a Meta
 * identity (Credential or OAuth account + WABA). WAHA QR inboxes have no Meta
 * identity at all: they can only send free text, and they need far slower
 * pacing because the underlying number is an ordinary WhatsApp account that
 * gets banned for bulk-rate behaviour.
 *
 * Single source of truth for WhatsAppCampaign (entity hooks, launch service,
 * send job) and the journey outbound actions.
 */
final class WhatsAppChannel
{
    public const QRCODE = 'whatsappQrcode';
    public const CLOUD_API = 'whatsappCloudApi';
    public const COEXISTENCE = 'whatsappCoexistence';

    public const MODE_TEMPLATE = 'Template';
    public const MODE_FREE_TEXT = 'FreeText';

    /**
     * Every channel type a campaign or journey action may send through.
     *
     * @var list<string>
     */
    public const SENDABLE = [
        self::QRCODE,
        self::CLOUD_API,
        self::COEXISTENCE,
    ];

    /**
     * Channels that accept Meta message templates (and therefore require
     * Meta auth + a WABA to list and validate those templates).
     *
     * @var list<string>
     */
    public const TEMPLATE_CAPABLE = [
        self::CLOUD_API,
        self::COEXISTENCE,
    ];

    /**
     * Channels that accept free-text outbound. Cloud/Coexistence only accept
     * it inside the Meta 24h customer service window; QR has no such limit.
     *
     * @var list<string>
     */
    public const FREE_TEXT_CAPABLE = [
        self::QRCODE,
        self::CLOUD_API,
        self::COEXISTENCE,
    ];

    /** @var list<string> */
    public const MODES = [
        self::MODE_TEMPLATE,
        self::MODE_FREE_TEXT,
    ];

    /**
     * Channels backed by a live WAHA session that must be torn down when the
     * channel is deleted, otherwise the WhatsApp number stays paired to a
     * running session nobody owns any more.
     *
     * Both store the session name in `wahaSessionName`, but they are provisioned
     * under different naming schemes:
     *   - QR code:    `channel_<integrationId>` (or `qr_inbox_<hash>` when the
     *                 inbox was created in Chatwoot first and later adopted)
     *   - Coexistence: `coexistence_<integrationId>` send-only companion,
     *                 created by ChatwootInboxIntegration::provisionWahaSendOnlySession()
     *
     * Cloud API is the only sendable channel with no WAHA session at all.
     *
     * @var list<string>
     */
    public const WAHA_BACKED = [
        self::QRCODE,
        self::COEXISTENCE,
    ];

    public static function isSendable(?string $channelType): bool
    {
        return $channelType !== null && in_array($channelType, self::SENDABLE, true);
    }

    public static function isQrcode(?string $channelType): bool
    {
        return $channelType === self::QRCODE;
    }

    /**
     * Whether deleting this channel must also tear down a WAHA session.
     * Single source of truth for external WAHA cleanup decisions.
     */
    public static function usesWahaSession(?string $channelType): bool
    {
        return $channelType !== null && in_array($channelType, self::WAHA_BACKED, true);
    }

    public static function supportsTemplates(?string $channelType): bool
    {
        return $channelType !== null && in_array($channelType, self::TEMPLATE_CAPABLE, true);
    }

    public static function supportsFreeText(?string $channelType): bool
    {
        return $channelType !== null && in_array($channelType, self::FREE_TEXT_CAPABLE, true);
    }

    /**
     * Whether the channel needs a Credential / OAuth account and a WABA id.
     * Only template-capable channels talk to the Meta Graph API.
     */
    public static function requiresMetaAuth(?string $channelType): bool
    {
        return self::supportsTemplates($channelType);
    }

    /**
     * Coerce a stored/user-supplied mode to a known value.
     *
     * Legacy rows predate the field and are Meta template campaigns, so a
     * null/blank mode means Template.
     */
    public static function normalizeMode(?string $mode): string
    {
        return in_array($mode, self::MODES, true) ? $mode : self::MODE_TEMPLATE;
    }

    /**
     * The only mode a channel can use, or its natural default when it
     * supports both.
     */
    public static function defaultModeFor(?string $channelType): string
    {
        return self::supportsTemplates($channelType)
            ? self::MODE_TEMPLATE
            : self::MODE_FREE_TEXT;
    }

    public static function supportsMode(?string $channelType, ?string $mode): bool
    {
        return self::normalizeMode($mode) === self::MODE_TEMPLATE
            ? self::supportsTemplates($channelType)
            : self::supportsFreeText($channelType);
    }

    /**
     * Delay window between consecutive sends, in milliseconds.
     *
     * Cloud API is a metered business API, so a flat 1.5s keeps overlapping
     * campaigns inside Meta's throughput. A QR session impersonates a human
     * handset: sends are jittered across tens of seconds because bulk-rate
     * traffic on a QR number is the fastest way to get it banned.
     *
     * @return array{0: int, 1: int} [minMs, maxMs]
     */
    public static function sendDelayWindowMs(?string $channelType): array
    {
        return self::isQrcode($channelType)
            ? [20000, 45000]
            : [1500, 1500];
    }

    /**
     * Recipients per async chunk job.
     *
     * chunkSize × max delay bounds how long a single job occupies a worker,
     * so slower channels get proportionally smaller chunks.
     */
    public static function chunkSize(?string $channelType): int
    {
        return self::isQrcode($channelType) ? 10 : 50;
    }

    /**
     * Human-readable channel name for validation messages.
     */
    public static function label(?string $channelType): string
    {
        return match ($channelType) {
            self::QRCODE => 'WhatsApp QR Code (WAHA)',
            self::CLOUD_API => 'Meta Cloud API',
            self::COEXISTENCE => 'Meta Coexistence',
            null, '' => 'unknown',
            default => $channelType,
        };
    }
}
