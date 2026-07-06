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

namespace Espo\Modules\Chatwoot\Tools;

/**
 * Shared utility for normalizing phone numbers to E.164 format.
 *
 * Extracted from WhatsAppCampaignService::normalizePhone() to avoid
 * duplication across multiple consumers (campaign service, sync jobs,
 * conversation initiation controller).
 */
class PhoneNormalizer
{
    /**
     * Normalize a raw phone number string to E.164 format.
     *
     * Handles Brazilian E.164 conversion, old 8-digit mobile format,
     * and country code detection.
     *
     * @param string|null $phone Raw phone number input
     * @return string|null Normalized E.164 phone number, or null if invalid
     */
    public static function normalize(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        // WhatsApp LID identifiers (e.g. "272628082307301@lid") are privacy
        // identifiers, NOT phone numbers. Stripping non-digits would mint a
        // plausible-looking but bogus E.164 number.
        if (str_contains($phone, '@lid')) {
            return null;
        }

        // An explicit "+" prefix means the number is already in
        // international format — never guess/prepend a country code.
        $hasPlus = str_starts_with(ltrim($phone), '+');

        // Remove all non-digit characters
        $digits = preg_replace('/\D/', '', $phone);

        if (!$digits || strlen($digits) < 10) {
            return null;
        }

        // Brazilian E.164: 55 + 2-digit DDD + 9-digit mobile (13 digits total)
        // Old format with 8-digit mobile (12 digits) needs the "9" prefix added.
        if (str_starts_with($digits, '55') && strlen($digits) >= 12 && strlen($digits) <= 13) {
            if (strlen($digits) === 12) {
                $ddd = substr($digits, 2, 2);
                $local = substr($digits, 4);
                // Local starts with 6-9 = mobile in old format, add the "9" prefix
                if (preg_match('/^[6-9]/', $local)) {
                    $digits = '55' . $ddd . '9' . $local;
                }
            }
            return '+' . $digits;
        }

        // Explicitly international input ("+…"): trust it as-is. Prevents
        // doubling the country code (e.g. "+55459858828" must NOT become
        // "+5555459858828" via the Brazilian-local heuristic below).
        if ($hasPlus) {
            return '+' . $digits;
        }

        // If 10-11 digits, assume Brazilian local number (DDD + local)
        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            if (strlen($digits) === 10) {
                $ddd = substr($digits, 0, 2);
                $local = substr($digits, 2);
                if (preg_match('/^[6-9]/', $local)) {
                    $digits = $ddd . '9' . $local;
                }
            }
            return '+55' . $digits;
        }

        // Already has country code (other countries)
        if (strlen($digits) > 11) {
            return '+' . $digits;
        }

        return null;
    }
}
