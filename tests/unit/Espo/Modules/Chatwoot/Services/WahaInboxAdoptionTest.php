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

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Modules\Chatwoot\Services\WahaInboxAdoption;
use PHPUnit\Framework\TestCase;

/**
 * Covers the phone-number comparison that guards against adopting a second
 * live channel for a number that already has one (which would let a campaign
 * message the same contact twice).
 */
class WahaInboxAdoptionTest extends TestCase
{
    public function testWahaMsisdnMatchesStoredE164(): void
    {
        // WAHA reports `5511933253711@c.us`; we store `+5511933253711`.
        $this->assertTrue(
            WahaInboxAdoption::isSamePhoneNumber('+5511933253711', '5511933253711')
        );
    }

    public function testFormattingIsIgnored(): void
    {
        $this->assertTrue(
            WahaInboxAdoption::isSamePhoneNumber('+55 11 93325-3711', '+5511933253711')
        );
        $this->assertTrue(
            WahaInboxAdoption::isSamePhoneNumber('(11) 93325-3711', '11933253711')
        );
    }

    public function testDifferentNumbersDoNotMatch(): void
    {
        $this->assertFalse(
            WahaInboxAdoption::isSamePhoneNumber('+5511933253711', '+5511963561157')
        );
    }

    /**
     * A suffix-based comparison would treat these as equal and wrongly block a
     * legitimate adoption, so the match must be exact on digits.
     */
    public function testDifferentCountryCodesDoNotMatch(): void
    {
        $this->assertFalse(
            WahaInboxAdoption::isSamePhoneNumber('+1555' . '11933253711', '+5511933253711')
        );
    }

    public function testMissingOrUnusableNumbersNeverMatch(): void
    {
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber(null, '+5511933253711'));
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber('+5511933253711', null));
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber(null, null));
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber('', '+5511933253711'));
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber('+++', '+5511933253711'));
    }

    /**
     * Two numbers that are both unusable must not be considered equal, or every
     * channel with a blank number would block adoption of every other.
     */
    public function testTwoBlankNumbersAreNotEqual(): void
    {
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber('', ''));
        $this->assertFalse(WahaInboxAdoption::isSamePhoneNumber('-', '+'));
    }

    public function testNormalizeStripsNonDigits(): void
    {
        $this->assertSame('5511933253711', WahaInboxAdoption::normalizePhoneNumber('+55 (11) 93325-3711'));
    }

    public function testNormalizeReturnsNullWhenNothingToCompare(): void
    {
        $this->assertNull(WahaInboxAdoption::normalizePhoneNumber(null));
        $this->assertNull(WahaInboxAdoption::normalizePhoneNumber(''));
        $this->assertNull(WahaInboxAdoption::normalizePhoneNumber('n/a'));
    }
}
