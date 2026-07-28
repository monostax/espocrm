<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

/**
 * Stable Message-ID tokens for journey outbound email ↔ reply/bounce correlation.
 *
 * Clients do NOT echo custom X-headers on reply. Correlation is:
 *   outbound Message-ID → inbound In-Reply-To → Email.repliedId → stored journeyToken (reply)
 *   outbound Message-ID → DSN Original-Message-ID / nested Message-ID → Email.journeyRecordId (bounce)
 *
 * Message-ID shape aims to look like a normal MTA id (not a vendor prefix / fake TLD):
 *   <{32-hex}.{8-hex}@{from-domain}>
 * Token = first 32 hex; always persisted on Email.journeyToken for the hot path.
 */
class JourneyEmailToken
{
    /** Debug-only; not used for reply matching (clients drop these). */
    public const HEADER_TOKEN = 'X-Monostax-Journey-Token';
    public const HEADER_RECORD = 'X-Monostax-Journey-Record';
    public const HEADER_JOURNEY = 'X-Monostax-Journey-Id';

    public const CODE_REPLIED = 'email_replied';
    public const CODE_BOUNCED = 'email_bounced';

    /**
     * @param string|null $fromAddress Outbound From used as Message-ID domain (preferred).
     * @return array{token: string, messageId: string}
     */
    public static function mint(?string $fromAddress = null): array
    {
        $token = bin2hex(random_bytes(16));
        $rand = bin2hex(random_bytes(4));
        $domain = self::messageIdDomain($fromAddress);
        // Opaque local-part — no vendor markers (reduces spam heuristics).
        $messageId = '<' . $token . '.' . $rand . '@' . $domain . '>';

        return [
            'token' => $token,
            'messageId' => $messageId,
        ];
    }

    /**
     * Best-effort parse when Email.journeyToken column is missing/unloaded.
     * Expects Message-ID shaped by mint(): <{32hex}.{8hex}@domain>
     */
    public static function extractTokenFromMessageId(?string $messageId): ?string
    {
        if ($messageId === null || $messageId === '') {
            return null;
        }

        $raw = trim($messageId);
        if ($raw !== '' && $raw[0] === '<') {
            $raw = substr($raw, 1);
        }
        if ($raw !== '' && str_ends_with($raw, '>')) {
            $raw = substr($raw, 0, -1);
        }

        // Optional legacy format: jrn.{token}.{rand}@journey.monostax
        if (preg_match('/^jrn\.([a-f0-9]{32})\.[a-f0-9]+@/i', $raw, $m)) {
            return strtolower($m[1]);
        }

        if (preg_match('/^([a-f0-9]{32})\.[a-f0-9]{8}@/i', $raw, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    public static function isValidToken(?string $token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    /**
     * Domain for Message-ID: prefer From host so MTA id aligns with envelope identity.
     * Never use invented hosts (e.g. journey.monostax) — those look less legitimate.
     */
    public static function messageIdDomain(?string $fromAddress): string
    {
        $addr = strtolower(trim((string) $fromAddress));
        if ($addr !== '' && str_contains($addr, '@')) {
            $host = trim(substr($addr, (int) strrpos($addr, '@') + 1));
            $host = rtrim($host, '>');
            // Basic hostname check (no spaces, has a dot or known local).
            if (
                $host !== '' &&
                !str_contains($host, ' ') &&
                preg_match('/^[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}$/i', $host)
            ) {
                return $host;
            }
        }

        // Last resort: neutral localhost-style id component used by many libraries.
        return 'localhost';
    }
}
