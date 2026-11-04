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

namespace Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Modules\FeatureCredits\Billing\Catalog;
use Espo\Modules\FeatureCredits\Billing\OfferTerms;

/**
 * The self-service plans in the current billing catalog. A plan offer is a
 * monthly subscription with seat pricing: `seats.plan` names the plan,
 * `seats.unitPrice` is the price per attendant, the offer price is its AI pack
 * and the product credits are the pack's monthly credits. One offer per
 * plan × pack, all in BRL for Brazil.
 */
class PlanOffers
{
    /** What each plan includes beyond its price; every extra attendant adds one of each. */
    public const CHANNELS = [
        'basic' => ['whatsappIncluded' => 1, 'instagramIncluded' => 1],
        'advanced' => ['whatsappIncluded' => 3, 'instagramIncluded' => 3],
    ];

    /** Measured in prod, Oct 2026: an AI conversation costs ~6.5 credits. */
    private const CREDITS_PER_CONVERSATION = 6.5;

    public function __construct(private Catalog $catalog) {}

    /**
     * @return array{catalogId: string, currency: string,
     *     plans: list<array{key: string, seatPrice: string, minSeats: int, maxSeats: int, whatsappIncluded: int, instagramIncluded: int}>,
     *     packs: list<array{key: string, credits: int, price: string, conversations: int}>}
     * @throws ServiceUnavailable
     */
    public function list(string $tenantId): array
    {
        $catalog = $this->catalog->current();
        $plans = [];
        $packs = [];

        foreach ($this->offers($tenantId) as $terms) {
            $key = $terms->seats['plan'];
            $plans[$key] ??= [
                'key' => $key,
                'seatPrice' => $terms->seats['unitPrice'],
                'minSeats' => $terms->seats['min'],
                'maxSeats' => $terms->seats['max'],
            ] + self::CHANNELS[$key];

            $credits = (int) round((float) $terms->credits);
            $packs[(string) $credits] ??= [
                'key' => (string) $credits,
                'credits' => $credits,
                'price' => $terms->price,
                // Rounded down to 25 so the label never promises more.
                'conversations' => (int) (floor($credits / self::CREDITS_PER_CONVERSATION / 25) * 25),
            ];
        }

        if (!isset($plans['basic'], $plans['advanced']) || !$packs) {
            throw new ServiceUnavailable('Self-service plans are not in the billing catalog.');
        }

        ksort($packs, SORT_NUMERIC);

        return [
            'catalogId' => $catalog->id,
            'currency' => 'BRL',
            'plans' => [$plans['basic'], $plans['advanced']],
            'packs' => array_values($packs),
        ];
    }

    /** The catalog offer for a plan and pack. */
    public function find(string $tenantId, string $plan, string $pack): ?OfferTerms
    {
        foreach ($this->offers($tenantId) as $terms) {
            if ($terms->seats['plan'] === $plan && (string) (int) round((float) $terms->credits) === $pack) {
                return $terms;
            }
        }

        return null;
    }

    /**
     * @return list<OfferTerms>
     */
    private function offers(string $tenantId): array
    {
        $offers = [];

        foreach ($this->catalog->current()->offers as $terms) {
            if ($terms->seats !== null && isset(self::CHANNELS[$terms->seats['plan']]) &&
                $terms->currency === 'BRL' && in_array('BR', $terms->countries, true) &&
                $terms->availableToTenant($tenantId)) {
                $offers[] = $terms;
            }
        }

        return $offers;
    }
}
