<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Avisos;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\CardImages;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Charges;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Copy;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Inbound;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\PhoneCodes;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Pushes;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Taps;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Tokens;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Workspaces;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class ToolsTest extends TestCase
{
    private function settings(array $values = []): Settings
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(fn (string $name) => match ($name) {
            'cryptKey' => 'test-crypt-key',
            'whatsAppNotifications' => $values,
            default => null,
        });
        return new Settings($config);
    }

    public function testPayloadsRoundTripAndFitAListRow(): void
    {
        $tokens = new Tokens($this->settings());
        $payload = $tokens->card('6a67d0d1a74fd5632', 's');
        $this->assertLessThanOrEqual(200, strlen($payload));
        $this->assertSame(['kind' => 'c', 'id' => '6a67d0d1a74fd5632', 'action' => 's'], $tokens->parse($payload));
        $this->assertSame(['kind' => 's', 'id' => 'abc', 'action' => 'q'], $tokens->parse($tokens->subscription('abc', 'q')));
    }

    public function testTheFunnelIconReachesTheRendererAsTheCrmStoresIt(): void
    {
        $this->assertSame(['type' => 'icon', 'value' => 'ti ti-stethoscope'], CardImages::funnelIcon('{"type":"icon","value":"ti ti-stethoscope"}'));
        $this->assertSame(['type' => 'emoji', 'value' => '🎯'], CardImages::funnelIcon((object) ['type' => 'emoji', 'value' => '🎯']));
        $this->assertNull(CardImages::funnelIcon(null));
        $this->assertNull(CardImages::funnelIcon('{"type":"image","value":"x"}'));
        $this->assertNull(CardImages::funnelIcon('not json'));
    }

    public function testALinkGoesIntoATemplateAsItIs(): void
    {
        $this->assertSame('utmsource', Avisos::parameter('utm_source'));
        $this->assertSame(1, preg_match('#^https://\\S+$#', 'https://chat.app.monostax.ai/app/accounts/6/catch-up?card=service:x&utm_source=whatsapp'));
    }

    public function testCardImageLinksAreSignedPerCardAndExpire(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(fn (string $name) => match ($name) {
            'cryptKey' => 'test-crypt-key', 'siteUrl' => 'https://app.monostax.ai/', 'whatsAppNotifications' => [],
            default => null,
        });
        $images = new CardImages($this->createMock(EntityManager::class), new Settings($config), $config,
            $this->createMock(Workspaces::class), $this->createMock(Log::class));
        $card = $this->createMock(Entity::class);
        $card->method('getId')->willReturn('6af6edb9e363654f1');
        $now = 1794600000;
        $url = $images->url($card, $now);
        $this->assertStringStartsWith('https://app.monostax.ai/api/v1/WhatsAppNotifications/cardImage/6af6edb9e363654f1.', $url);
        $token = basename($url);
        $this->assertSame('6af6edb9e363654f1', $images->cardId($token, $now));
        $this->assertSame('6af6edb9e363654f1', $images->cardId($token, $now + CardImages::TTL));
        // Expired, another card, a later expiry we never signed, or no extension.
        $this->assertNull($images->cardId($token, $now + CardImages::TTL + 1));
        $this->assertNull($images->cardId(str_replace('6af6edb9e363654f1', '6af6edb9e363654f2', $token), $now));
        $this->assertNull($images->cardId((string) preg_replace('/\.\d{10}\./', '.' . ($now + 10 * CardImages::TTL) . '.', $token), $now));
        $this->assertNull($images->cardId(substr($token, 0, -4), $now));
        $this->assertNull($images->cardId('../../etc/passwd', $now));
    }

    public function testCardImageAmountsReadAsEmDiaShowsThem(): void
    {
        $this->assertSame('R$ 0', CardImages::amount(0, 'BRL', 'pt_BR'));
        $this->assertSame('R$ 1.200', CardImages::amount('1200.00', 'BRL', 'pt_BR'));
        $this->assertSame('R$ 1.234,50', CardImages::amount(1234.5, '', 'pt_BR'));
        $this->assertSame('US$ 1,234.50', CardImages::amount(1234.5, 'USD', 'en_US'));
        $this->assertNull(CardImages::amount(null, 'BRL', 'pt_BR'));
    }

    public function testCardImageFollowsConfig(): void
    {
        $this->assertFalse($this->settings()->cardImage());
        $this->assertTrue($this->settings(['cardImage' => true])->cardImage());
    }

    public function testATamperedOrForeignPayloadNeverActs(): void
    {
        $tokens = new Tokens($this->settings());
        $payload = $tokens->card('card1', 'r');
        // Another card, another action, another instance's key, or a payload we never signed.
        $this->assertNull($tokens->parse(str_replace('card1', 'card2', $payload)));
        $this->assertNull($tokens->parse(str_replace('.r.', '.s.', $payload)));
        $other = new Tokens(new Settings(tap($this->createMock(Config::class), fn ($c) => $c->method('get')->willReturn('other-key'))));
        $this->assertNull($other->parse($payload));
        $this->assertNull($tokens->parse('Já resolvi'));
        $this->assertNull($tokens->parse(null));
        // An action a subscription doesn't have.
        $this->assertNull($tokens->parse(str_replace('ed1.c.', 'ed1.s.', $payload)));
    }

    public function testCodesAreFoundOnlyInTheirOwnShape(): void
    {
        $this->assertSame('7KQ2-M9XP', PhoneCodes::extract('Ativar Em dia 7KQ2-M9XP'));
        $this->assertSame('7KQ2-M9XP', PhoneCodes::extract('confirmar whatsapp 7kq2-m9xp'));
        // Look-alike characters (0, 1, I, O) are not in the alphabet, and a code needs its hyphen.
        $this->assertNull(PhoneCodes::extract('Ativar 7KQ2M9XP'));
        $this->assertNull(PhoneCodes::extract('Ativar 70Q2-M9XP'));
        $this->assertNull(PhoneCodes::extract('oi, tudo bem?'));
        $this->assertNull(PhoneCodes::extract('STRENGTH'));
        $this->assertTrue(PhoneCodes::meant('Ativar Em dia 7KQ2-M9XP'));
        $this->assertFalse(PhoneCodes::meant('em dia'));
    }

    public function testWaitReadsLikeAPerson(): void
    {
        $this->assertSame('1 minutos', Copy::wait('pt_BR', 10));
        $this->assertSame('40 minutos', Copy::wait('pt_BR', 40 * 60));
        $this->assertSame('1 hora', Copy::wait('pt_BR', 90 * 60));
        $this->assertSame('3 horas', Copy::wait('pt_BR', 3 * 3600 + 59));
        $this->assertSame('1 dia', Copy::wait('pt_BR', 30 * 3600));
        $this->assertSame('6 dias', Copy::wait('pt_BR', 6 * 86400 + 5));
        $this->assertSame('6 days', Copy::wait('en_US', 6 * 86400));
    }

    public function testTemplateParametersFollowMetasRules(): void
    {
        // No line breaks, tabs or runs of spaces; no formatting characters; never empty.
        $this->assertSame('Gelsa aguarda horários da nutri', Avisos::parameter("*Gelsa*  aguarda\n\thorários   da _nutri_"));
        $this->assertSame('—', Avisos::parameter("  \n "));
        $this->assertSame(10, mb_strlen(Avisos::parameter(str_repeat('á', 50), 10)));
        $this->assertStringEndsWith('…', Avisos::cut(str_repeat('x', 30), 10));
    }

    public function testRemindTimesAreInTheWorkspaceZone(): void
    {
        $now = new DateTimeImmutable('2026-11-12 15:20', new DateTimeZone('America/Sao_Paulo'));
        $this->assertSame('2026-11-12 16:20', Taps::until($now, 'h')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-13 08:00', Taps::until($now, 't')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-16 08:00', Taps::until($now, 'm')->format('Y-m-d H:i'));
        // On a Monday, "segunda" is next week's.
        $monday = new DateTimeImmutable('2026-11-16 07:00', new DateTimeZone('America/Sao_Paulo'));
        $this->assertSame('2026-11-23 08:00', Taps::until($monday, 'm')->format('Y-m-d H:i'));
    }

    public function testAlertHoursMaySpanMidnight(): void
    {
        $this->assertTrue(Pushes::inside('08:00', '08:00', '20:00'));
        $this->assertFalse(Pushes::inside('20:00', '08:00', '20:00'));
        $this->assertFalse(Pushes::inside('07:59', '08:00', '20:00'));
        $this->assertTrue(Pushes::inside('23:30', '22:00', '06:00'));
        $this->assertTrue(Pushes::inside('05:00', '22:00', '06:00'));
        $this->assertFalse(Pushes::inside('12:00', '22:00', '06:00'));
    }

    public function testPhonesComeBackAsE164(): void
    {
        $this->assertSame('+5591999990000', Inbound::phone('+55 (91) 99999-0000'));
        $this->assertSame('+559140403376', Inbound::phone('559140403376'));
        $this->assertNull(Inbound::phone('12345'));
        $this->assertNull(Inbound::phone(''));
    }

    public function testMetasRateBecomesCreditsRoundedUp(): void
    {
        $rates = fn (?string $category) => Charges::creditsFor(Settings::DEFAULT_RATES, Settings::DEFAULT_BRL_PER_CREDIT, $category);
        // R$ 0.035 / R$ 0.499 per credit = 0.07014… → 0.0702; marketing R$ 0.3217 → 0.64469… → 0.6447.
        $this->assertSame('0.0702', $rates('utility'));
        $this->assertSame('0.0702', $rates('service'));
        $this->assertSame('0.6447', $rates('marketing'));
        $this->assertNull($rates('unknown'));
        $this->assertNull($rates(null));
    }

    public function testConfigDefaultsKeepItOff(): void
    {
        $settings = $this->settings();
        $this->assertFalse($settings->enabled());
        $this->assertFalse($settings->signupVerification());
        $this->assertSame('+55 91 4040-3376', $settings->displayNumber());
        $this->assertSame('monostax_catchup_card', $settings->cardTemplate());
        $this->assertNull($settings->digestTemplate());
        $on = $this->settings(['enabled' => true, 'digestTemplate' => 'monostax_resumo_diario']);
        $this->assertTrue($on->enabled());
        $this->assertTrue($on->signupVerification());
        $this->assertSame('monostax_resumo_diario', $on->digestTemplate());
        $this->assertFalse($this->settings(['enabled' => true, 'signupVerification' => false])->signupVerification());
    }
}

function tap(mixed $value, callable $callback): mixed
{
    $callback($value);
    return $value;
}
