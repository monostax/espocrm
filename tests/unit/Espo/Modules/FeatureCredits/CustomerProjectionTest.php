<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Enrichment\CustomerProjection;
use Espo\Modules\FeatureCredits\Enrichment\Quotes;
use Espo\Modules\FeatureCredits\Billing\CustomerProjection as BillingProjection;
use PHPUnit\Framework\TestCase;

final class CustomerProjectionTest extends TestCase
{
    public function testBillingAcceptanceHistoryAndRecoveryExcludeBindingsAndPreserveExactPrices(): void
    {
        $acceptance = ['tenantId' => 'tenant', 'obligationId' => 'obligation', 'requestKey' => 'original-key',
            'provider' => 'private-supplier', 'integrationId' => 'private-integration',
            'terms' => ['termsId' => 'terms', 'price' => '99.90', 'credits' => '123.4567',
                'currency' => 'BRL', 'futureInternalField' => 'private-supplier']];
        $hash = Quotes::hash($acceptance);
        $context = BillingProjection::context(['tenantId' => 'tenant', 'effectiveProvider' => 'private-supplier',
            'offers' => [['terms' => $acceptance['terms'], 'eligibility' => ['eligible' => false,
                'integrationId' => 'private-integration', 'blockedReason' => 'private-supplier-unavailable']]],
            'checkouts' => [['state' => 'accepted', 'acceptance' => $acceptance]]]);
        self::assertStringNotContainsString('private-', json_encode($context));
        self::assertSame('99.90', $context['offers'][0]['terms']['price']);
        self::assertSame('123.4567', $context['checkouts'][0]['acceptance']['terms']['credits']);
        self::assertSame('original-key', $context['checkouts'][0]['acceptance']['requestKey']);
        self::assertSame($hash, Quotes::hash($acceptance));
        $creation = ['creationId' => 'creation', 'tenantId' => 'tenant', 'state' => 'bound',
            'provider' => 'private-supplier', 'integrationId' => 'private-integration', 'externalId' => 'private-reference'];
        self::assertSame(['creationId' => 'creation', 'tenantId' => 'tenant', 'state' => 'bound'], BillingProjection::creation($creation));
        $checkout = BillingProjection::checkout(['state' => 'pending', 'acceptance' => $acceptance,
            'customer' => $creation, 'charge' => $creation, 'subscription' => ['state' => 'active', 'providerStatus' => 'private-status']]);
        self::assertStringNotContainsString('private-', json_encode($checkout));
        $change = BillingProjection::change(['tenantId' => 'tenant', 'before' => ['effectiveProvider' => 'private-supplier'],
            'after' => ['tenantId' => 'tenant', 'revision' => 2, 'providerOverride' => 'private-supplier']]);
        self::assertSame(['tenantId' => 'tenant', 'revision' => 2], $change['after']);
        self::assertStringNotContainsString('private-', json_encode($change));
    }

    public function testQuoteProgressAndHistoricalPurchasesShareLocalSourcesWithoutMutatingEvidence(): void
    {
        $original = [
            'id' => 'quote-one', 'tenantId' => 'tenant-one', 'maximum' => '9.1234',
            'policyId' => 'supplier-policy', 'futureInternalField' => ['secret' => 'supplier'],
            'routes' => [
                'email' => [['provider' => 'supplier-a', 'version' => 'supplier-version', 'credits' => '1.1234'],
                    ['provider' => 'supplier-b', 'credits' => '8.0000']],
                'phone' => [['provider' => 'supplier-b', 'credits' => '8.0000']],
            ],
            'executionProducts' => ['email' => ['state' => 'purchased', 'diagnostics' => 'supplier',
                'attempts' => [['provider' => 'supplier-b', 'state' => 'reused', 'delivery' => 'cache',
                    'purchaseId' => 'original-purchase', 'rawResponse' => ['supplier']]]]],
            'discovery' => ['plannedProviders' => ['supplier-a'], 'unavailableProviders' => ['supplier-c' => 'unavailable'],
                'explicitApplicationRequired' => true],
        ];
        $hash = Quotes::hash($original);
        $public = CustomerProjection::quote($original);
        self::assertSame('9.1234', $public['maximum']);
        self::assertSame([['source' => 1, 'credits' => '1.1234'], ['source' => 2, 'credits' => '8.0000']], $public['routes']['email']);
        self::assertSame(2, $public['routes']['phone'][0]['source']);
        self::assertSame(['state' => 'reused', 'delivery' => 'cache', 'purchaseId' => 'original-purchase', 'source' => 2],
            $public['executionProducts']['email']['attempts'][0]);
        self::assertStringNotContainsString('supplier', json_encode($public));
        self::assertArrayNotHasKey('futureInternalField', $public);
        self::assertSame($hash, Quotes::hash($original));
        self::assertSame($public, CustomerProjection::quote($original)); // replay

        $receipt = ['id' => 'purchase', 'quoteId' => 'quote-one', 'provider' => 'supplier-b',
            'policyId' => 'supplier-policy', 'credits' => '8.0000', 'rawResponse' => ['supplier']];
        self::assertSame(['id' => 'purchase', 'quoteId' => 'quote-one', 'credits' => '8.0000', 'source' => 2],
            CustomerProjection::purchase($receipt, $original));
        $otherQuote = ['routes' => ['phone' => [['provider' => 'supplier-b', 'credits' => '8.0000']]]];
        self::assertSame(1, CustomerProjection::purchase($receipt, $otherQuote)['source']);
    }

    public function testUnboundSourceFailsClosedWithoutDisclosingItsName(): void
    {
        $this->expectException(Conflict::class);
        $this->expectExceptionMessage('Enrichment source is unavailable.');
        CustomerProjection::purchase(['provider' => 'private-supplier'], ['routes' => []]);
    }
}
