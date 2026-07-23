<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace tests\unit\Espo\Modules\FeatureEmailCampaign\Tools;

use Espo\Modules\FeatureEmailCampaign\Tools\EmailDomainMxValidator;
use PHPUnit\Framework\TestCase;

class EmailDomainMxValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        EmailDomainMxValidator::resetCache();
        EmailDomainMxValidator::setDnsLookup(null);
    }

    protected function tearDown(): void
    {
        EmailDomainMxValidator::resetCache();
        EmailDomainMxValidator::setDnsLookup(null);
    }

    public function testExtractDomain(): void
    {
        $this->assertSame('example.com', EmailDomainMxValidator::extractDomain('User@Example.COM'));
        $this->assertSame('example.com', EmailDomainMxValidator::extractDomain('a.b@example.com'));
        $this->assertNull(EmailDomainMxValidator::extractDomain('not-an-email'));
        $this->assertNull(EmailDomainMxValidator::extractDomain(''));
    }

    public function testAcceptsDomainWithMxRecord(): void
    {
        EmailDomainMxValidator::setDnsLookup(function (string $domain, int $type) {
            if ($domain === 'mail.example' && $type === DNS_MX) {
                return [['target' => 'mx.mail.example', 'pri' => 10]];
            }

            return [];
        });

        $this->assertTrue(EmailDomainMxValidator::emailDomainHasValidMx('user@mail.example'));
        $this->assertTrue(EmailDomainMxValidator::domainHasValidMx('mail.example'));
    }

    public function testAcceptsDomainWithARecordFallback(): void
    {
        EmailDomainMxValidator::setDnsLookup(function (string $domain, int $type) {
            if ($domain !== 'aonly.example') {
                return [];
            }

            if ($type === DNS_MX) {
                return [];
            }

            if ($type === DNS_A + DNS_AAAA) {
                return [['ip' => '203.0.113.10']];
            }

            return [];
        });

        $this->assertTrue(EmailDomainMxValidator::emailDomainHasValidMx('user@aonly.example'));
    }

    public function testRejectsDomainWithoutMxOrA(): void
    {
        EmailDomainMxValidator::setDnsLookup(static fn () => []);

        $this->assertFalse(EmailDomainMxValidator::emailDomainHasValidMx('user@no-mail.example'));
        $this->assertFalse(EmailDomainMxValidator::domainHasValidMx('no-mail.example'));
    }

    public function testCachesDomainLookup(): void
    {
        $calls = 0;

        EmailDomainMxValidator::setDnsLookup(function () use (&$calls) {
            $calls++;

            return [['target' => 'mx.cached.example', 'pri' => 10]];
        });

        $this->assertTrue(EmailDomainMxValidator::emailDomainHasValidMx('a@cached.example'));
        $this->assertTrue(EmailDomainMxValidator::emailDomainHasValidMx('b@cached.example'));
        // One MX lookup (may call A as well only if MX empty) — MX succeeds first call.
        $this->assertSame(1, $calls);
    }

    public function testRejectsInvalidEmailShape(): void
    {
        $this->assertFalse(EmailDomainMxValidator::emailDomainHasValidMx('no-at-sign'));
        $this->assertFalse(EmailDomainMxValidator::domainHasValidMx(''));
        $this->assertFalse(EmailDomainMxValidator::domainHasValidMx('nodot'));
    }
}
