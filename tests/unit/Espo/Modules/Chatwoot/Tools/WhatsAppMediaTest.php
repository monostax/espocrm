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

namespace tests\unit\Espo\Modules\Chatwoot\Tools;

use Espo\Modules\Chatwoot\Tools\WhatsAppMedia;
use PHPUnit\Framework\TestCase;

/**
 * These assertions encode Chatwoot's FileTypeHelper behaviour
 * (app/helpers/file_type_helper.rb). If Chatwoot changes that mapping, the
 * campaign's promise about how media is rendered breaks, and these tests are
 * the tripwire.
 */
class WhatsAppMediaTest extends TestCase
{
    /**
     * @dataProvider kindCases
     */
    public function testKindForMimeType(string $mime, string $expected): void
    {
        $this->assertSame($expected, WhatsAppMedia::kindForMimeType($mime));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function kindCases(): array
    {
        return [
            // Any audio/* prefix => voice bubble.
            ['audio/ogg', WhatsAppMedia::KIND_AUDIO],
            ['audio/ogg; codecs=opus', WhatsAppMedia::KIND_AUDIO],
            ['audio/mpeg', WhatsAppMedia::KIND_AUDIO],
            ['audio/mp4', WhatsAppMedia::KIND_AUDIO],
            ['audio/wav', WhatsAppMedia::KIND_AUDIO],

            // Chatwoot uses an allow-list for images, not a prefix test.
            ['image/jpeg', WhatsAppMedia::KIND_IMAGE],
            ['image/png', WhatsAppMedia::KIND_IMAGE],
            ['image/webp', WhatsAppMedia::KIND_IMAGE],
            // Not in Chatwoot's list => document, despite the image/ prefix.
            ['image/tiff', WhatsAppMedia::KIND_DOCUMENT],
            ['image/svg+xml', WhatsAppMedia::KIND_DOCUMENT],

            ['video/mp4', WhatsAppMedia::KIND_VIDEO],
            ['video/quicktime', WhatsAppMedia::KIND_VIDEO],
            ['video/x-msvideo', WhatsAppMedia::KIND_DOCUMENT],

            ['application/pdf', WhatsAppMedia::KIND_DOCUMENT],
            ['text/plain', WhatsAppMedia::KIND_DOCUMENT],
            ['', WhatsAppMedia::KIND_DOCUMENT],
        ];
    }

    public function testMimeTypeMatchingIsCaseInsensitiveAndTrimmed(): void
    {
        $this->assertSame(WhatsAppMedia::KIND_AUDIO, WhatsAppMedia::kindForMimeType('  AUDIO/OGG  '));
        $this->assertSame(WhatsAppMedia::KIND_IMAGE, WhatsAppMedia::kindForMimeType('IMAGE/PNG'));
    }

    /**
     * The whole point of the sendAudioAsVoice flag: the MIME type we advertise
     * to Chatwoot is the only lever over voice-vs-document.
     */
    public function testAudioIsDowngradedToDocumentWhenVoiceDisabled(): void
    {
        $this->assertSame(
            WhatsAppMedia::DOCUMENT_MIME,
            WhatsAppMedia::effectiveMimeType('audio/ogg', false)
        );

        $this->assertSame(
            WhatsAppMedia::KIND_DOCUMENT,
            WhatsAppMedia::kindForMimeType(WhatsAppMedia::effectiveMimeType('audio/ogg', false))
        );
    }

    public function testAudioIsPreservedWhenVoiceEnabled(): void
    {
        $this->assertSame('audio/ogg', WhatsAppMedia::effectiveMimeType('audio/ogg', true));
        $this->assertSame(
            WhatsAppMedia::KIND_AUDIO,
            WhatsAppMedia::kindForMimeType(WhatsAppMedia::effectiveMimeType('audio/ogg', true))
        );
    }

    /**
     * The flag must not silently reclassify images or documents.
     */
    public function testFlagOnlyAffectsAudio(): void
    {
        foreach (['image/png', 'video/mp4', 'application/pdf'] as $mime) {
            $this->assertSame($mime, WhatsAppMedia::effectiveMimeType($mime, true), $mime);
            $this->assertSame($mime, WhatsAppMedia::effectiveMimeType($mime, false), $mime);
        }
    }

    public function testEmptyMimeTypeFallsBackToDocument(): void
    {
        $this->assertSame(WhatsAppMedia::DOCUMENT_MIME, WhatsAppMedia::effectiveMimeType(null, true));
        $this->assertSame(WhatsAppMedia::DOCUMENT_MIME, WhatsAppMedia::effectiveMimeType('   ', true));
    }

    /**
     * @dataProvider describeCases
     */
    public function testDescribe(?string $mime, bool $asVoice, string $expected): void
    {
        $this->assertSame($expected, WhatsAppMedia::describe($mime, $asVoice));
    }

    /**
     * @return list<array{?string, bool, string}>
     */
    public static function describeCases(): array
    {
        return [
            ['audio/ogg', true, 'voice message'],
            ['audio/ogg', false, 'document'],
            ['image/jpeg', true, 'image'],
            ['video/mp4', true, 'video'],
            ['application/pdf', true, 'document'],
            [null, true, 'document'],
        ];
    }

    public function testMaxBytesIsWhatsAppMediaLimit(): void
    {
        $this->assertSame(16 * 1024 * 1024, WhatsAppMedia::MAX_BYTES);
    }
}
