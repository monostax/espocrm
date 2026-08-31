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

use Espo\Modules\Chatwoot\Tools\WhatsAppChannel;
use PHPUnit\Framework\TestCase;

class WhatsAppChannelTest extends TestCase
{
    public function testQrcodeCannotSendTemplates(): void
    {
        $this->assertFalse(WhatsAppChannel::supportsTemplates(WhatsAppChannel::QRCODE));
        $this->assertTrue(WhatsAppChannel::supportsFreeText(WhatsAppChannel::QRCODE));
    }

    public function testMetaChannelsSendBoth(): void
    {
        foreach ([WhatsAppChannel::CLOUD_API, WhatsAppChannel::COEXISTENCE] as $channel) {
            $this->assertTrue(WhatsAppChannel::supportsTemplates($channel), $channel);
            $this->assertTrue(WhatsAppChannel::supportsFreeText($channel), $channel);
        }
    }

    public function testOnlyMetaChannelsRequireMetaAuth(): void
    {
        $this->assertTrue(WhatsAppChannel::requiresMetaAuth(WhatsAppChannel::CLOUD_API));
        $this->assertTrue(WhatsAppChannel::requiresMetaAuth(WhatsAppChannel::COEXISTENCE));
        $this->assertFalse(WhatsAppChannel::requiresMetaAuth(WhatsAppChannel::QRCODE));
    }

    public function testNonWhatsAppChannelsAreNotSendable(): void
    {
        foreach (['instagram', 'email', 'web_widget', '', null] as $channel) {
            $this->assertFalse(WhatsAppChannel::isSendable($channel), var_export($channel, true));
        }
    }

    /**
     * Legacy rows predate messageMode and are all Meta template campaigns;
     * a blank mode must never be read as free text.
     */
    public function testNormalizeModeDefaultsToTemplate(): void
    {
        foreach ([null, '', 'nonsense', 'freetext'] as $raw) {
            $this->assertSame(
                WhatsAppChannel::MODE_TEMPLATE,
                WhatsAppChannel::normalizeMode($raw),
                var_export($raw, true)
            );
        }

        $this->assertSame(
            WhatsAppChannel::MODE_FREE_TEXT,
            WhatsAppChannel::normalizeMode(WhatsAppChannel::MODE_FREE_TEXT)
        );
    }

    public function testDefaultModeForChannel(): void
    {
        $this->assertSame(
            WhatsAppChannel::MODE_TEMPLATE,
            WhatsAppChannel::defaultModeFor(WhatsAppChannel::CLOUD_API)
        );

        $this->assertSame(
            WhatsAppChannel::MODE_FREE_TEXT,
            WhatsAppChannel::defaultModeFor(WhatsAppChannel::QRCODE)
        );
    }

    /**
     * @dataProvider modeSupportCases
     */
    public function testSupportsMode(?string $channel, ?string $mode, bool $expected): void
    {
        $this->assertSame($expected, WhatsAppChannel::supportsMode($channel, $mode));
    }

    /**
     * @return list<array{?string, ?string, bool}>
     */
    public static function modeSupportCases(): array
    {
        return [
            [WhatsAppChannel::QRCODE, WhatsAppChannel::MODE_FREE_TEXT, true],
            [WhatsAppChannel::QRCODE, WhatsAppChannel::MODE_TEMPLATE, false],
            [WhatsAppChannel::CLOUD_API, WhatsAppChannel::MODE_TEMPLATE, true],
            [WhatsAppChannel::CLOUD_API, WhatsAppChannel::MODE_FREE_TEXT, true],
            [WhatsAppChannel::COEXISTENCE, WhatsAppChannel::MODE_TEMPLATE, true],
            // Blank mode normalizes to Template, so QR must still reject it.
            [WhatsAppChannel::QRCODE, null, false],
            [WhatsAppChannel::CLOUD_API, null, true],
        ];
    }

    /**
     * QR numbers are ordinary handsets: bulk-rate pacing gets them banned, so
     * the window must be both far slower than Cloud API and randomized.
     */
    public function testQrcodePacingIsSlowAndJittered(): void
    {
        [$qrMin, $qrMax] = WhatsAppChannel::sendDelayWindowMs(WhatsAppChannel::QRCODE);
        [$cloudMin, $cloudMax] = WhatsAppChannel::sendDelayWindowMs(WhatsAppChannel::CLOUD_API);

        $this->assertGreaterThan($cloudMax, $qrMin);
        $this->assertGreaterThan($qrMin, $qrMax, 'QR delay must be a range, not a constant.');

        $this->assertSame($cloudMin, $cloudMax, 'Cloud API pacing is intentionally flat.');
    }

    /**
     * chunkSize x maxDelay bounds how long one job holds a worker.
     */
    public function testChunkSizeKeepsJobDurationBounded(): void
    {
        foreach (WhatsAppChannel::SENDABLE as $channel) {
            [, $maxMs] = WhatsAppChannel::sendDelayWindowMs($channel);

            $worstCaseSeconds = WhatsAppChannel::chunkSize($channel) * $maxMs / 1000;

            $this->assertLessThanOrEqual(
                600,
                $worstCaseSeconds,
                "Chunk for {$channel} may run up to {$worstCaseSeconds}s"
            );
        }
    }

    public function testQrcodeChunksAreSmallerThanCloud(): void
    {
        $this->assertLessThan(
            WhatsAppChannel::chunkSize(WhatsAppChannel::CLOUD_API),
            WhatsAppChannel::chunkSize(WhatsAppChannel::QRCODE)
        );
    }

    public function testTemplateCapableIsSubsetOfSendable(): void
    {
        foreach (WhatsAppChannel::TEMPLATE_CAPABLE as $channel) {
            $this->assertContains($channel, WhatsAppChannel::SENDABLE);
        }

        $this->assertNotContains(WhatsAppChannel::QRCODE, WhatsAppChannel::TEMPLATE_CAPABLE);
    }

    /**
     * Coexistence provisions a send-only WAHA companion session
     * (`coexistence_<id>`) stored in the same `wahaSessionName` field as a QR
     * channel, so deleting one must tear the session down too. Missing this
     * used to leave the WhatsApp number paired to a running session.
     */
    public function testCoexistenceIsWahaBackedLikeQrcode(): void
    {
        $this->assertTrue(WhatsAppChannel::usesWahaSession(WhatsAppChannel::QRCODE));
        $this->assertTrue(WhatsAppChannel::usesWahaSession(WhatsAppChannel::COEXISTENCE));
    }

    public function testCloudApiHasNoWahaSession(): void
    {
        $this->assertFalse(WhatsAppChannel::usesWahaSession(WhatsAppChannel::CLOUD_API));
    }

    public function testNonWhatsAppAndNullChannelsAreNotWahaBacked(): void
    {
        $this->assertFalse(WhatsAppChannel::usesWahaSession(null));
        $this->assertFalse(WhatsAppChannel::usesWahaSession(''));
        $this->assertFalse(WhatsAppChannel::usesWahaSession('email'));
        $this->assertFalse(WhatsAppChannel::usesWahaSession('webWidget'));
        $this->assertFalse(WhatsAppChannel::usesWahaSession('instagram'));
    }

    public function testWahaBackedChannelsAreAllSendable(): void
    {
        foreach (WhatsAppChannel::WAHA_BACKED as $channel) {
            $this->assertContains($channel, WhatsAppChannel::SENDABLE, $channel);
        }
    }
}
