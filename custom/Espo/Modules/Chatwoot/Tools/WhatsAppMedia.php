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
 * How a WhatsApp media attachment should be presented to the recipient.
 *
 * WhatsApp renders media by kind, not by file extension, and Chatwoot derives
 * that kind purely from the MIME type it is given (FileTypeHelper). This class
 * owns the mapping so the campaign send path, validation and UI agree on:
 *
 *   audio/*  -> voice bubble  (WAHA /api/sendVoice)
 *   image/*  -> image         (WAHA /api/sendImage)
 *   video/*  -> video         (WAHA /api/sendVideo)
 *   other    -> document      (WAHA /api/sendFile)
 */
final class WhatsAppMedia
{
    public const KIND_AUDIO = 'audio';
    public const KIND_IMAGE = 'image';
    public const KIND_VIDEO = 'video';
    public const KIND_DOCUMENT = 'document';

    /**
     * MIME type that forces Chatwoot's file_type to `file`, so an audio
     * attachment is delivered as a document instead of a voice note.
     */
    public const DOCUMENT_MIME = 'application/octet-stream';

    /**
     * WhatsApp caps media at 16 MB for most types. Chatwoot/ActiveStorage will
     * accept more, so the send path rejects oversized media up front rather
     * than after uploading it.
     */
    public const MAX_BYTES = 16 * 1024 * 1024;

    /**
     * Mirrors Chatwoot's FileTypeHelper#image_file? / #video_file?, which use
     * an allow-list rather than a `image/*` prefix test. A MIME type outside
     * those lists silently becomes a document, so the campaign UI must warn
     * with the same rules Chatwoot applies.
     *
     * @var list<string>
     */
    private const CHATWOOT_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/bmp',
        'image/webp',
        'image',
    ];

    /** @var list<string> */
    private const CHATWOOT_VIDEO_MIMES = [
        'video/ogg',
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'video',
    ];

    /**
     * Resolve how Chatwoot will classify a MIME type.
     *
     * Deliberately replicates Chatwoot's own precedence (image, then video,
     * then any `audio/` prefix, else file).
     */
    public static function kindForMimeType(?string $mimeType): string
    {
        $mime = strtolower(trim((string) $mimeType));

        if (in_array($mime, self::CHATWOOT_IMAGE_MIMES, true)) {
            return self::KIND_IMAGE;
        }

        if (in_array($mime, self::CHATWOOT_VIDEO_MIMES, true)) {
            return self::KIND_VIDEO;
        }

        if (str_starts_with($mime, 'audio/')) {
            return self::KIND_AUDIO;
        }

        return self::KIND_DOCUMENT;
    }

    public static function isAudio(?string $mimeType): bool
    {
        return self::kindForMimeType($mimeType) === self::KIND_AUDIO;
    }

    /**
     * The MIME type to advertise to Chatwoot for a given attachment.
     *
     * Audio is downgraded to a document MIME when the campaign opted out of
     * voice notes; every other kind passes through unchanged.
     */
    public static function effectiveMimeType(?string $mimeType, bool $sendAudioAsVoice): string
    {
        $mime = trim((string) $mimeType);

        if ($mime === '') {
            return self::DOCUMENT_MIME;
        }

        if (!$sendAudioAsVoice && self::isAudio($mime)) {
            return self::DOCUMENT_MIME;
        }

        return $mime;
    }

    /**
     * Human-readable description of what the recipient will receive, used in
     * validation errors and the campaign UI.
     */
    public static function describe(?string $mimeType, bool $sendAudioAsVoice): string
    {
        return match (self::kindForMimeType(self::effectiveMimeType($mimeType, $sendAudioAsVoice))) {
            self::KIND_AUDIO => 'voice message',
            self::KIND_IMAGE => 'image',
            self::KIND_VIDEO => 'video',
            default => 'document',
        };
    }
}
