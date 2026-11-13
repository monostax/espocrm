<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Espo\Core\Utils\Config;

/**
 * Config `whatsAppNotifications` (an object in data/config.php). Everything is off until `enabled` is true.
 *
 *   enabled              master switch (default false)
 *   inboxId              the CRM ChatwootInbox of "Monostax - Avisos" (default: inbox 74 in account 6)
 *   number               that inbox's number, E.164
 *   cardTemplate         Meta template for a card outside the 24h window (monostax_catchup_card)
 *   cardImage            the card template has an IMAGE header: the rendered card goes there and the body takes
 *                        {{1}} who, {{2}} how long, {{3}} the card link (monostax_catchup_card_link); default false
 *   digestTemplate       Meta template for the morning digest; null sends the first card instead
 *   templateLanguage     pt_BR
 *   hotLeadMinutes       minimum wait before a hot-lead alert (60)
 *   hotLeadDailyCap      hot-lead alerts per person per day (2)
 *   signupVerification   self-service signup verifies the WhatsApp number (default: same as enabled)
 *   rates                Meta's price per delivered message by pricing category, in BRL
 *   brlPerCredit         what one credit is worth in BRL, to turn Meta's price into credits
 */
class Settings
{
    public const DEFAULT_INBOX_ID = '6a67d0d1a74fd5632';
    public const DEFAULT_NUMBER = '+559140403376';
    /** Meta's Brazil rates from 2026-10-01, per delivered message (the user, 2026-11-12). */
    public const DEFAULT_RATES = ['marketing' => '0.3217', 'utility' => '0.035', 'authentication' => '0.035', 'service' => '0.035'];
    /** R$ 499 per 1.000 credits on the plans page (pricing redesign 2026-11-11). */
    public const DEFAULT_BRL_PER_CREDIT = '0.499';

    public function __construct(private Config $config) {}

    public function enabled(): bool
    {
        return (bool) ($this->all()['enabled'] ?? false);
    }

    public function signupVerification(): bool
    {
        return (bool) ($this->all()['signupVerification'] ?? $this->enabled());
    }

    public function inboxId(): string
    {
        return (string) ($this->all()['inboxId'] ?? self::DEFAULT_INBOX_ID);
    }

    public function number(): string
    {
        return (string) ($this->all()['number'] ?? self::DEFAULT_NUMBER);
    }

    /** "+55 91 4040-3376" for people to read. */
    public function displayNumber(): string
    {
        $digits = preg_replace('/\D/', '', $this->number());
        if (preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', (string) $digits, $m)) return "+55 {$m[1]} {$m[2]}-{$m[3]}";
        return $this->number();
    }

    public function cardTemplate(): string
    {
        return (string) ($this->all()['cardTemplate'] ?? 'monostax_catchup_card');
    }

    public function cardImage(): bool
    {
        return ($this->all()['cardImage'] ?? false) === true;
    }

    public function digestTemplate(): ?string
    {
        $name = $this->all()['digestTemplate'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    public function templateLanguage(): string
    {
        return (string) ($this->all()['templateLanguage'] ?? 'pt_BR');
    }

    public function hotLeadMinutes(): int
    {
        return max(1, (int) ($this->all()['hotLeadMinutes'] ?? 60));
    }

    public function hotLeadDailyCap(): int
    {
        return max(0, (int) ($this->all()['hotLeadDailyCap'] ?? 2));
    }

    /** @return array<string, string> pricing category => BRL */
    public function rates(): array
    {
        $rates = $this->all()['rates'] ?? null;
        return is_array($rates) ? array_map('strval', $rates) : self::DEFAULT_RATES;
    }

    public function brlPerCredit(): string
    {
        return (string) ($this->all()['brlPerCredit'] ?? self::DEFAULT_BRL_PER_CREDIT);
    }

    /** Key for signed button payloads, derived from the instance's crypt key. */
    public function secret(): string
    {
        $key = (string) $this->config->get('cryptKey');
        if ($key === '') throw new \RuntimeException('cryptKey is not set.');
        return hash_hmac('sha256', 'whatsapp-notifications-v1', $key);
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        $value = $this->config->get('whatsAppNotifications');
        if (is_object($value)) $value = json_decode((string) json_encode($value), true);
        return is_array($value) ? $value : [];
    }
}
