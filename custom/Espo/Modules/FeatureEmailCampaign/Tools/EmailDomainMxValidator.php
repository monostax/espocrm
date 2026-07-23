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

namespace Espo\Modules\FeatureEmailCampaign\Tools;

/**
 * Checks whether an email domain can receive mail (MX, or A/AAAA fallback per RFC 5321).
 *
 * Used before EmailCampaign enrollment/send to skip undeliverable domains and reduce bounce rate.
 */
class EmailDomainMxValidator
{
    /** @var array<string, bool> */
    private static array $cache = [];

    /**
     * Optional override for tests: fn(string $domain, int $type): array|false
     *
     * @var (callable(string, int): (array<int, array<string, mixed>>|false))|null
     */
    private static $dnsLookup = null;

    public static function resetCache(): void
    {
        self::$cache = [];
    }

    /**
     * @param (callable(string, int): (array<int, array<string, mixed>>|false))|null $dnsLookup
     */
    public static function setDnsLookup(?callable $dnsLookup): void
    {
        self::$dnsLookup = $dnsLookup;
    }

    public static function emailDomainHasValidMx(string $email): bool
    {
        $domain = self::extractDomain($email);

        if ($domain === null) {
            return false;
        }

        return self::domainHasValidMx($domain);
    }

    public static function domainHasValidMx(string $domain): bool
    {
        $normalized = self::normalizeDomain($domain);

        if ($normalized === null) {
            return false;
        }

        if (array_key_exists($normalized, self::$cache)) {
            return self::$cache[$normalized];
        }

        $result = self::lookup($normalized);
        self::$cache[$normalized] = $result;

        return $result;
    }

    public static function extractDomain(string $email): ?string
    {
        $email = strtolower(trim($email));

        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }

        $domain = substr($email, (int) strrpos($email, '@') + 1);
        $domain = trim($domain, " \t\n\r\0\x0B.<>");

        return self::normalizeDomain($domain);
    }

    private static function normalizeDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $domain = rtrim($domain, '.');

        if ($domain === '' || str_contains($domain, ' ') || !str_contains($domain, '.')) {
            return null;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($ascii !== false) {
                $domain = strtolower($ascii);
            }
        }

        if (
            filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false &&
            filter_var($domain, FILTER_VALIDATE_IP) === false
        ) {
            return null;
        }

        return $domain;
    }

    private static function lookup(string $domain): bool
    {
        // MX preferred; RFC 5321 §5.1 falls back to A/AAAA as implicit MX.
        $mx = self::dnsGetRecord($domain, DNS_MX);

        if (is_array($mx) && $mx !== []) {
            return true;
        }

        $a = self::dnsGetRecord($domain, DNS_A + DNS_AAAA);

        if (is_array($a) && $a !== []) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>|false
     */
    private static function dnsGetRecord(string $domain, int $type): array|false
    {
        if (self::$dnsLookup !== null) {
            return (self::$dnsLookup)($domain, $type);
        }

        return @dns_get_record($domain, $type);
    }
}
