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

use Espo\Core\Currency\ConfigDataProvider;
use Espo\Core\Currency\Converter;
use Espo\Core\Field\Currency;
use Throwable;

/**
 * Convert deal-currency billed amounts into the system default currency
 * using Espo's configured FX rates (Administration → Currency).
 */
final class BillingCurrency
{
    public function __construct(
        private Converter $converter,
        private ConfigDataProvider $configDataProvider,
    ) {}

    /**
     * ISO 4217 code used for summable report columns (`amount`).
     */
    public function defaultCode(): string
    {
        $code = strtoupper(trim((string) $this->configDataProvider->getDefaultCurrency()));

        if ($code === '' || strlen($code) !== 3) {
            return RateCard::DEFAULT_CURRENCY;
        }

        return $code;
    }

    /**
     * Convert $amount of $fromCode into the system default currency.
     * Same-currency short-circuit; unknown codes or FX failures fall back
     * to the original amount (fail-open — still better than dropping revenue).
     */
    public function toDefault(float $amount, string $fromCode): float
    {
        $amount = max(0.0, $amount);

        if ($amount === 0.0) {
            return 0.0;
        }

        $fromCode = strtoupper(trim($fromCode));
        $target = $this->defaultCode();

        if ($fromCode === '' || strlen($fromCode) !== 3) {
            $fromCode = $target;
        }

        if ($fromCode === $target) {
            return Pricing::roundMoney($amount);
        }

        if (
            !$this->configDataProvider->hasCurrency($fromCode)
            || !$this->configDataProvider->hasCurrency($target)
        ) {
            return Pricing::roundMoney($amount);
        }

        try {
            $converted = $this->converter->convert(new Currency($amount, $fromCode), $target);

            return Pricing::roundMoney((float) $converted->getAmount());
        } catch (Throwable) {
            return Pricing::roundMoney($amount);
        }
    }
}
