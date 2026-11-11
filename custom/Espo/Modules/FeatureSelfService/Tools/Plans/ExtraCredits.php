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
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\Accounts;
use Espo\Modules\FeatureCredits\Billing\Catalog;
use Espo\Modules\FeatureCredits\Billing\Checkout;
use Espo\Modules\FeatureCredits\Billing\Creation;
use Espo\Modules\FeatureCredits\Billing\OfferTerms;
use Espo\ORM\EntityManager;
use InvalidArgumentException;

/**
 * Extra AI credits bought from Chatwoot (gtm/07: R$599 per 1.000, they never
 * expire and are spent after the month's credits): the catalog's BRL credit
 * packages, sold as one-off Asaas charges on the billing engine's hosted
 * checkout (accept → payer → charge → invoice). Only workspaces on the credit
 * wallet buy them, since others don't spend wallet credits. The payer is the
 * workspace's Asaas customer, created by its first checkout; the invoice lets
 * the payer choose Pix, card or boleto. A retry, or buying the same package
 * while its invoice is open, continues that checkout.
 */
class ExtraCredits
{
    private const OPEN = ['accepted', 'creating', 'unknown', 'pending'];

    /** One-off: no churn risk, so the payer picks the method on the invoice. */
    private const BILLING_TYPE = 'UNDEFINED';

    public function __construct(
        private Accounts $accounts,
        private Catalog $catalog,
        private Checkout $checkout,
        private Creation $creation,
        private EntityManager $entityManager,
        private Clock $clock,
    ) {}

    /**
     * @return array{currency: string, wallet: bool,
     *     packages: list<array{key: string, credits: int, price: string, conversations: int}>,
     *     lastPurchase: ?array{state: string, credits: int}}
     */
    public function offers(string $tenantId): array
    {
        $packages = [];

        foreach ($this->packages($tenantId) as $terms) {
            $credits = (int) round((float) $terms->credits);
            $packages[(string) $credits] ??= [
                'key' => (string) $credits,
                'credits' => $credits,
                'price' => $terms->price,
                'conversations' => PlanOffers::conversations($credits),
            ];
        }

        ksort($packages, SORT_NUMERIC);

        return ['currency' => 'BRL', 'wallet' => $this->onWallet($tenantId), 'packages' => array_values($packages),
            'lastPurchase' => $this->lastPurchase($tenantId)];
    }

    /**
     * The newest credit purchase: `completed` once paid and granted, so the
     * page coming back from the payment page knows when the credits are in.
     *
     * @return ?array{state: string, credits: int}
     */
    private function lastPurchase(string $tenantId): ?array
    {
        foreach ($this->checkout->context($tenantId)['checkouts'] as $entry) {
            $terms = $entry['acceptance']['terms'];

            if (!isset($terms['seats']) && $terms['operation'] === 'purchase') {
                return ['state' => (string) $entry['state'], 'credits' => (int) round((float) $terms['credits'])];
            }
        }

        return null;
    }

    /**
     * @param ?array{name: string, cpfCnpj: string, email: string} $payer Only needed before the workspace's first payment.
     * @return array{state: string, paymentUrl: ?string, obligationId: string}
     * @throws Conflict WALLET_REQUIRED, PAYER_CHANGED
     * @throws InvalidArgumentException unknown package, PAYER_REQUIRED, invalid payer
     * @throws ServiceUnavailable the payment provider didn't confirm a step; retry
     */
    public function start(string $tenantId, string $actorId, string $package, ?array $payer, ?string $returnUrl = null): array
    {
        if (!$this->onWallet($tenantId)) {
            throw new Conflict('WALLET_REQUIRED');
        }

        $terms = $this->find($tenantId, $package) ?? throw new InvalidArgumentException('Unknown credit package.');
        $context = $this->accounts->context($tenantId);

        if ($context['billingCountry'] !== 'BR') {
            $this->accounts->changeCountry($tenantId, self::key('country'), (int) $context['revision'], 'BR', $actorId);
            $context = $this->accounts->context($tenantId);
        }

        $obligationId = $this->openCheckout($tenantId, $terms->offerId);

        if ($obligationId === null) {
            $receipt = $this->accounts->accept($tenantId, self::key('extra-credits'), (int) $context['revision'],
                (string) $context['catalogId'], $terms->offerId, $terms->termsId, $actorId);
            $obligationId = $receipt['obligationId'];
        }

        $view = $this->checkout->read($tenantId, $obligationId);
        $customer = $view['customer'];

        if (!$customer || $customer['state'] !== 'bound') {
            if ($payer === null) {
                throw new InvalidArgumentException('PAYER_REQUIRED');
            }

            try {
                $customer = $this->creation->customer($tenantId, $obligationId, [
                    'name' => $payer['name'],
                    'cpfCnpj' => $payer['cpfCnpj'],
                    'email' => $payer['email'] !== '' ? $payer['email'] : null,
                    'notificationDisabled' => false,
                ]);
            } catch (Conflict) {
                // One payer per workspace: its first checkout fixed it.
                throw new Conflict('PAYER_CHANGED');
            }
        }

        if ($customer['state'] !== 'bound') {
            throw new ServiceUnavailable('The payer was not confirmed yet.');
        }

        if (!$view['charge'] || $view['charge']['state'] !== 'bound') {
            $charge = $this->creation->charge($tenantId, $obligationId, $customer['creationId'], self::BILLING_TYPE,
                (string) $view['earliestDueDate'],
                ['description' => self::description($terms)] + ($returnUrl !== null ? ['successUrl' => $returnUrl] : []));

            if ($charge['state'] !== 'bound') {
                throw new ServiceUnavailable('The charge was not confirmed yet.');
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

    /** Whether the workspace has a payer for its sales already (otherwise the first purchase asks for one). */
    public function hasPayer(string $tenantId, ?string $integrationId): bool
    {
        if ($integrationId === null) {
            return false;
        }

        $query = $this->entityManager->getPDO()->prepare(
            "SELECT 1 FROM credit_billing_creation WHERE tenant_id = ? AND provider = 'asaas' AND integration_id = ?
             AND kind = 'customer' AND source_key = 'customer' AND state = 'bound'"
        );
        $query->execute([$tenantId, $integrationId]);

        return $query->fetchColumn() !== false;
    }

    /** e.g. "Créditos extras Monostax: 1.000 créditos de IA, sem validade". */
    public static function description(OfferTerms $terms): string
    {
        return sprintf('Créditos extras Monostax: %s créditos de IA, sem validade',
            number_format((int) round((float) $terms->credits), 0, ',', '.'));
    }

    /** On the credit wallet: its cutover has taken effect. */
    public function onWallet(string $tenantId): bool
    {
        $query = $this->entityManager->getPDO()->prepare(
            'SELECT cutover_at FROM tenant_credit_cutover WHERE tenant_id = ? AND deleted = FALSE'
        );
        $query->execute([$tenantId]);
        $cutoverAt = $query->fetchColumn();

        return is_string($cutoverAt) && $cutoverAt <= $this->clock->now();
    }

    private function find(string $tenantId, string $package): ?OfferTerms
    {
        foreach ($this->packages($tenantId) as $terms) {
            if ((string) (int) round((float) $terms->credits) === $package) {
                return $terms;
            }
        }

        return null;
    }

    /**
     * @return list<OfferTerms>
     */
    private function packages(string $tenantId): array
    {
        $packages = [];

        foreach ($this->catalog->current()->offers as $terms) {
            if ($terms->operation === 'purchase' && $terms->seats === null && $terms->currency === 'BRL' &&
                in_array('BR', $terms->countries, true) && $terms->availableToTenant($tenantId)) {
                $packages[] = $terms;
            }
        }

        return $packages;
    }

    /** The open checkout for the same package, to continue its invoice. */
    private function openCheckout(string $tenantId, string $offerId): ?string
    {
        foreach ($this->checkout->context($tenantId)['checkouts'] as $entry) {
            $terms = $entry['acceptance']['terms'];

            if (!isset($terms['seats']) && $terms['operation'] === 'purchase' && $terms['offerId'] === $offerId &&
                in_array($entry['state'], self::OPEN, true)) {
                return $entry['acceptance']['obligationId'];
            }
        }

        return null;
    }

    private static function key(string $purpose): string
    {
        return "self-service/{$purpose}/" . bin2hex(random_bytes(8));
    }
}
