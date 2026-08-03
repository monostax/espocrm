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

namespace Espo\Modules\Chatwoot\Tools\Billing;

/**
 * Commercial rates for one Tenant (or platform defaults).
 *
 * Unit prices are expressed in {@see $currency} (ISO 4217, e.g. BRL / USD).
 * Empty Tenant fields fall back to the platform defaults so existing rows
 * keep the historical 1.99 / 0.99 / 0.49 economics (default BRL).
 *
 * {@see $planIncludedUsage} is the monthly franchise of billable units
 * (packs for pack model, bases for extra) — see Pricing.html plans
 * (200 / 600 / 1000 AI conversations/month). 0 = no franchise (all usage billed).
 */
final class RateCard
{
    public const DEFAULT_PACK_UNIT_PRICE = 1.99;
    public const DEFAULT_EXTRA_BASE_PRICE = 0.99;
    public const DEFAULT_EXTRA_UNIT_PRICE = 0.49;
    public const DEFAULT_PACK_SIZE = 4;
    public const DEFAULT_EXTRA_INCLUDED_CUSTOMER_TURNS = 4;
    public const DEFAULT_PLAN_INCLUDED_USAGE = 0;
    /** Product default when no system defaultCurrency is configured. */
    public const DEFAULT_CURRENCY = 'BRL';

    public function __construct(
        public readonly float $packUnitPrice = self::DEFAULT_PACK_UNIT_PRICE,
        public readonly float $extraBasePrice = self::DEFAULT_EXTRA_BASE_PRICE,
        public readonly float $extraUnitPrice = self::DEFAULT_EXTRA_UNIT_PRICE,
        public readonly int $packSize = self::DEFAULT_PACK_SIZE,
        public readonly int $extraIncludedCustomerTurns = self::DEFAULT_EXTRA_INCLUDED_CUSTOMER_TURNS,
        public readonly int $planIncludedUsage = self::DEFAULT_PLAN_INCLUDED_USAGE,
        public readonly string $currency = self::DEFAULT_CURRENCY,
    ) {}

    public static function defaults(?string $currency = null): self
    {
        return new self(currency: self::normalizeCurrency($currency) ?? self::DEFAULT_CURRENCY);
    }

    /**
     * Build from optional Tenant / period column values.
     *
     * Prices and included-turn counts: null / negative → platform default;
     * 0 is valid (free rate / no included turns). Pack size still requires
     * a positive integer (0/null → default). Plan included usage: null → 0
     * (pay-as-you-go).
     *
     * @param string|null $fallbackCurrency Used when $currency is empty
     *                                      (usually system defaultCurrency).
     */
    public static function fromNullable(
        ?float $packUnitPrice,
        ?float $extraBasePrice,
        ?float $extraUnitPrice,
        ?int $packSize = null,
        ?int $extraIncludedCustomerTurns = null,
        ?string $currency = null,
        ?string $fallbackCurrency = null,
        ?int $planIncludedUsage = null,
    ): self {
        $resolvedCurrency = self::normalizeCurrency($currency)
            ?? self::normalizeCurrency($fallbackCurrency)
            ?? self::DEFAULT_CURRENCY;

        return new self(
            self::nonNegativeOr($packUnitPrice, self::DEFAULT_PACK_UNIT_PRICE),
            self::nonNegativeOr($extraBasePrice, self::DEFAULT_EXTRA_BASE_PRICE),
            self::nonNegativeOr($extraUnitPrice, self::DEFAULT_EXTRA_UNIT_PRICE),
            self::positiveIntOr($packSize, self::DEFAULT_PACK_SIZE),
            self::nonNegativeIntOr(
                $extraIncludedCustomerTurns,
                self::DEFAULT_EXTRA_INCLUDED_CUSTOMER_TURNS
            ),
            self::nonNegativeIntOr($planIncludedUsage, self::DEFAULT_PLAN_INCLUDED_USAGE),
            $resolvedCurrency,
        );
    }

    private static function normalizeCurrency(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = strtoupper(trim($code));

        if ($code === '' || strlen($code) !== 3) {
            return null;
        }

        return $code;
    }

    private static function nonNegativeOr(?float $value, float $default): float
    {
        if ($value === null || $value < 0.0) {
            return $default;
        }

        return $value;
    }

    private static function positiveIntOr(?int $value, int $default): int
    {
        if ($value === null || $value <= 0) {
            return $default;
        }

        return $value;
    }

    private static function nonNegativeIntOr(?int $value, int $default): int
    {
        if ($value === null || $value < 0) {
            return $default;
        }

        return $value;
    }
}
