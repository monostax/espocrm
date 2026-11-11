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
 * The self-service plan in the current billing catalog (one plan since
 * 2026-11-11, gtm/07): a price per attendant, credits included per attendant,
 * and optional monthly credits in 1.000-credit steps. Each step is a monthly
 * seat offer of plan `monostax`: `seats.unitPrice` is the attendant price,
 * `seats.includedCredits` the credits each attendant brings, the offer price
 * the step's price and the product credits the step's credits (the zero step
 * costs nothing on top of the attendants). All in BRL for Brazil.
 */
class PlanOffers
{
    public const PLAN = 'monostax';

    /** Every attendant brings one WhatsApp and one Instagram. */
    public const WHATSAPP_PER_SEAT = 1;
    public const INSTAGRAM_PER_SEAT = 1;

    /** Measured in prod, Oct 2026: an AI conversation costs ~6.5 credits. */
    public const CREDITS_PER_CONVERSATION = 6.5;

    public function __construct(private Catalog $catalog) {}

    /**
     * @return array{catalogId: string, currency: string,
     *     plan: array{key: string, seatPrice: string, includedCredits: int, includedConversations: int,
     *         minSeats: int, maxSeats: int, whatsappPerSeat: int, instagramPerSeat: int},
     *     credits: list<array{key: string, credits: int, price: string, conversations: int}>}
     * @throws ServiceUnavailable
     */
    public function list(string $tenantId): array
    {
        $catalog = $this->catalog->current();
        $plan = null;
        $steps = [];

        foreach ($this->offers($tenantId) as $terms) {
            $included = (int) round((float) $terms->seats['includedCredits']);
            $plan ??= [
                'key' => self::PLAN,
                'seatPrice' => $terms->seats['unitPrice'],
                'includedCredits' => $included,
                'includedConversations' => (int) floor($included / self::CREDITS_PER_CONVERSATION),
                'minSeats' => $terms->seats['min'],
                'maxSeats' => $terms->seats['max'],
                'whatsappPerSeat' => self::WHATSAPP_PER_SEAT,
                'instagramPerSeat' => self::INSTAGRAM_PER_SEAT,
            ];

            $credits = (int) round((float) $terms->credits);
            $steps[(string) $credits] ??= [
                'key' => (string) $credits,
                'credits' => $credits,
                'price' => $terms->price,
                'conversations' => self::conversations($credits),
            ];
        }

        if ($plan === null || !isset($steps['0'])) {
            throw new ServiceUnavailable('The self-service plan is not in the billing catalog.');
        }

        ksort($steps, SORT_NUMERIC);

        return [
            'catalogId' => $catalog->id,
            'currency' => 'BRL',
            'plan' => $plan,
            'credits' => array_values($steps),
        ];
    }

    /** The catalog offer for a monthly-credits step ('0', '1000', …). */
    public function find(string $tenantId, string $credits): ?OfferTerms
    {
        foreach ($this->offers($tenantId) as $terms) {
            if ((string) (int) round((float) $terms->credits) === $credits) {
                return $terms;
            }
        }

        return null;
    }

    /** Rounded down to 25 so a label never promises more. */
    public static function conversations(int $credits): int
    {
        return (int) (floor($credits / self::CREDITS_PER_CONVERSATION / 25) * 25);
    }

    /**
     * @return list<OfferTerms>
     */
    private function offers(string $tenantId): array
    {
        $offers = [];

        foreach ($this->catalog->current()->offers as $terms) {
            if ($terms->seats !== null && $terms->seats['plan'] === self::PLAN && isset($terms->seats['includedCredits']) &&
                $terms->currency === 'BRL' && in_array('BR', $terms->countries, true) &&
                $terms->availableToTenant($tenantId)) {
                $offers[] = $terms;
            }
        }

        return $offers;
    }
}
