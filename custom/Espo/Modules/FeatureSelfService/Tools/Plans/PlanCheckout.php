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

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Modules\FeatureCredits\Billing\Accounts;
use Espo\Modules\FeatureCredits\Billing\Checkout;
use Espo\Modules\FeatureCredits\Billing\Creation;
use Espo\Modules\FeatureCredits\Billing\Subscriptions;
use InvalidArgumentException;

/**
 * Starts the self-service plan: one monthly subscription for attendants × the
 * attendant price plus the chosen monthly credits, on the billing engine's
 * hosted checkout (accept → payer → subscription → first invoice). The
 * subscription is charged to a card, so it renews by itself; manual PIX and
 * boleto churn and stay with sales-led annual plans (decided 2026-11-11). Each
 * step replays safely, so a retry continues an open checkout with the same
 * terms. One with other terms is replaced while nothing was charged for it.
 */
class PlanCheckout
{
    private const OPEN = ['accepted', 'creating', 'unknown', 'pending', 'active'];

    /** Asaas billing type of the plan subscription. */
    private const BILLING_TYPE = 'CREDIT_CARD';

    public function __construct(
        private Accounts $accounts,
        private Checkout $checkout,
        private Creation $creation,
        private Subscriptions $subscriptions,
        private PlanOffers $offers,
    ) {}

    /**
     * @param array{name: string, cpfCnpj: string, email: string} $payer
     * @return array{state: string, paymentUrl: ?string, obligationId: string}
     * @throws Conflict ALREADY_SUBSCRIBED, CHECKOUT_IN_PROGRESS, PAYER_CHANGED
     * @throws InvalidArgumentException invalid monthly credits, seats or payer
     * @throws ServiceUnavailable the payment provider didn't confirm a step; retry
     */
    public function start(string $tenantId, string $actorId, int $seats, string $credits, array $payer): array
    {
        $terms = $this->offers->find($tenantId, $credits)
            ?? throw new InvalidArgumentException('Unknown monthly credits.');

        $context = $this->accounts->context($tenantId);

        if ($context['billingCountry'] !== 'BR') {
            $this->accounts->changeCountry($tenantId, self::key('country'), (int) $context['revision'], 'BR', $actorId);
            $context = $this->accounts->context($tenantId);
        }

        $obligationId = $this->openCheckout($tenantId, $actorId, $terms->offerId, $seats);

        if ($obligationId === null) {
            // Closing a replaced checkout moves the billing revision.
            $context = $this->accounts->context($tenantId);
            $receipt = $this->accounts->accept($tenantId, self::key('plan'), (int) $context['revision'],
                (string) $context['catalogId'], $terms->offerId, $terms->termsId, $actorId, $seats);
            $obligationId = $receipt['obligationId'];
        }

        $view = $this->checkout->read($tenantId, $obligationId);
        $customer = $view['customer'];

        if (!$customer || $customer['state'] !== 'bound') {
            try {
                $customer = $this->creation->customer($tenantId, $obligationId, [
                    'name' => $payer['name'],
                    'cpfCnpj' => $payer['cpfCnpj'],
                    'email' => $payer['email'] !== '' ? $payer['email'] : null,
                    'notificationDisabled' => false,
                ]);
            } catch (Conflict) {
                // One payer per workspace: the first checkout fixed it.
                throw new Conflict('PAYER_CHANGED');
            }
        }

        if ($customer['state'] !== 'bound') {
            throw new ServiceUnavailable('The payer was not confirmed yet.');
        }

        if (!$view['charge'] || $view['charge']['state'] !== 'bound') {
            $charge = $this->creation->charge($tenantId, $obligationId, $customer['creationId'], self::BILLING_TYPE,
                (string) $view['earliestDueDate']);

            if ($charge['state'] !== 'bound') {
                throw new ServiceUnavailable('The subscription was not confirmed yet.');
            }
        }

        $payment = $this->checkout->payment($tenantId, $obligationId);

        if ($payment['state'] === 'paid') {
            return ['state' => 'paid', 'paymentUrl' => null, 'obligationId' => $obligationId];
        }

        if (!$payment['invoiceUrl']) {
            throw new ServiceUnavailable('The payment page is not available yet.');
        }

        return ['state' => 'pending', 'paymentUrl' => $payment['invoiceUrl'], 'obligationId' => $obligationId];
    }

    /**
     * The open plan checkout to continue, after closing any with other terms.
     */
    private function openCheckout(string $tenantId, string $actorId, string $offerId, int $seats): ?string
    {
        foreach ($this->checkout->context($tenantId)['checkouts'] as $entry) {
            $terms = $entry['acceptance']['terms'];

            if (!isset($terms['seats']) || !in_array($entry['state'], self::OPEN, true)) {
                continue;
            }

            $obligationId = $entry['acceptance']['obligationId'];

            if ($entry['state'] === 'active') {
                throw new Conflict('ALREADY_SUBSCRIBED');
            }

            if ($terms['offerId'] === $offerId && $terms['seats']['quantity'] === $seats) {
                return $obligationId;
            }

            if ($entry['state'] === 'accepted') {
                $this->accounts->cancel($tenantId, self::key('replace'), $obligationId, $actorId);

                continue;
            }

            if ($entry['state'] === 'pending') {
                // Unpaid subscription for other terms: stop it before starting the new one.
                $this->subscriptions->cancel($tenantId, $obligationId, self::key('replace'), $actorId);

                if ($this->checkout->read($tenantId, $obligationId)['state'] === 'cancelled') {
                    continue;
                }
            }

            throw new Conflict('CHECKOUT_IN_PROGRESS');
        }

        return null;
    }

    private static function key(string $purpose): string
    {
        return "self-service/{$purpose}/" . bin2hex(random_bytes(8));
    }
}
