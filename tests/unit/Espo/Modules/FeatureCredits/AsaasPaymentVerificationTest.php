<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\{Conflict, Forbidden};
use Espo\Core\Utils\Config;
use Espo\Modules\FeatureCredits\Billing\Asaas\{Command, Connections, Json, PaymentVerification};
use Espo\Modules\FeatureCredits\Billing\{MonthlyPeriod, OfferTerms};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AsaasPaymentVerificationTest extends TestCase
{
    private function command(string $method = 'PIX'): Command
    {
        return Command::charge(new OfferTerms('offer', 'v1', 'pack', 'v1', 'purchase', ['BR'], 'BRL', '25', '50', null),
            'cus_original', 'crm-credit-00000000000000000', $method, '2026-10-15');
    }

    private function payment(string $method = 'PIX', string $status = 'RECEIVED'): array
    {
        return Json::decode('{"object":"payment","id":"pay_original","customer":"cus_original", "value":25.00,
            "originalDueDate":"2026-10-15","dueDate":"2026-10-15","deleted":false,"externalReference":"crm-credit-00000000000000000"}') +
            ['billingType' => $method, 'status' => $status];
    }

    public static function statuses(): iterable
    {
        foreach (['PIX', 'BOLETO', 'CREDIT_CARD'] as $method) foreach ([
            'PENDING', 'AWAITING_RISK_ANALYSIS', 'AUTHORIZED', 'CONFIRMED', 'RECEIVED', 'OVERDUE', 'REFUNDED', 'REFUND_REQUESTED',
            'REFUND_IN_PROGRESS', 'RECEIVED_IN_CASH', 'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'AWAITING_CHARGEBACK_REVERSAL', 'DUNNING_RECEIVED', 'FUTURE_STATUS',
        ] as $status) yield "$method $status" => [$method, $status, $status === 'RECEIVED' || ($status === 'CONFIRMED' && $method !== 'PIX')];
    }

    #[DataProvider('statuses')]
    public function testMethodSpecificPaymentAuthority(string $method, string $status, bool $paid): void
    {
        $result = PaymentVerification::verify($this->payment($method, $status), 'pay_original', $this->command($method), 'pay_original');
        $this->assertSame($paid, $result['paid']);
        if ($method === 'PIX' && $status === 'CONFIRMED') $this->assertTrue($result['review']);
    }

    public static function mismatches(): iterable
    {
        foreach (['id' => 'pay_other', 'object' => 'subscription', 'customer' => 'cus_other', 'value' => '25.01', 'originalValue' => '24.99',
            'externalReference' => 'wrong', 'subscription' => 'sub_other', 'installment' => 'installment', 'originalDueDate' => '2026-10-16',
            'billingType' => 'UNDEFINED', 'deleted' => null, 'status' => null] as $key => $value) yield $key => [$key, $value];
    }

    public function testFullRefundRequiresFinalUnadjustedOriginalAmount(): void
    {
        $payment = $this->payment(status: 'REFUNDED');
        $verify = fn (array $data) => PaymentVerification::verify($data, 'pay_original', $this->command(), 'pay_original');
        $this->assertSame('refund', $verify($payment)['fullReversal']['kind']);
        $done = ['refunds' => [['status' => 'DONE', 'value' => '10.00'], ['status' => 'DONE', 'value' => '15.00']]];
        $this->assertSame('25.00', $verify($payment + $done)['fullReversal']['value']);
        foreach ([['status' => 'REFUND_IN_PROGRESS'], ['status' => 'RECEIVED'], ['deleted' => true],
            ['refunds' => [['status' => 'PENDING', 'value' => '25.00']]], ['refunds' => [['status' => 'DONE', 'value' => '24.99']]],
            ['refunds' => [['status' => 'DONE', 'value' => '25.01']]], ['refunds' => [['status' => 'DONE', 'value' => '25', 'refundedSplits' => [['done' => true]]]]],
            ['refunds' => 'bad'], ['split' => [['value' => '1']]], ['fine' => ['value' => '1']]] as $change) {
            $this->assertNull($verify(array_replace($payment, $change))['fullReversal']);
        }
    }

    public function testFinalChargebackRequiresExactOwnerAmountAndLostOrDoneStatus(): void
    {
        $payment = $this->payment('CREDIT_CARD', 'REFUNDED');
        $chargeback = ['id' => 'cb_original', 'payment' => 'pay_original', 'customerAccount' => 'cus_original', 'value' => '25.00', 'status' => 'DONE'];
        $verify = fn (array $c) => PaymentVerification::verify($payment + ['chargeback' => $c], 'pay_original', $this->command('CREDIT_CARD'), 'pay_original');
        foreach (['DONE', 'DISPUTE_LOST'] as $status) $this->assertSame('chargeback', $verify(array_replace($chargeback, ['status' => $status]))['fullReversal']['kind']);
        foreach ([['status' => 'REQUESTED'], ['status' => 'IN_DISPUTE'], ['status' => 'REVERSED'], ['value' => '1'],
            ['payment' => 'pay_other'], ['customerAccount' => 'cus_other'], ['id' => null], ['installment' => 'installment']] as $change) {
            $this->assertNull($verify(array_replace($chargeback, $change))['fullReversal']);
        }
    }

    #[DataProvider('mismatches')]
    public function testCorrelationAndIncompletePaymentRejection(string $key, mixed $value): void
    {
        $payment = $this->payment(); $payment[$key] = $value;
        $this->expectException(Conflict::class);
        PaymentVerification::verify($payment, 'pay_original', $this->command(), 'pay_original');
    }

    public static function reviews(): iterable
    {
        foreach (['deleted' => true, 'refunds' => [['value' => '1']], 'chargeback' => ['status' => 'REQUESTED'], 'split' => [['value' => '2']],
            'discount' => ['value' => '1'], 'interest' => ['value' => '1'], 'fine' => ['value' => '1'], 'interestValue' => '1'] as $key => $value) yield $key => [$key, $value];
    }

    #[DataProvider('reviews')]
    public function testReceivedWithReversalOrAlteredPriceRequiresReview(string $key, mixed $value): void
    {
        $payment = $this->payment(); $payment[$key] = $value;
        $result = PaymentVerification::verify($payment, 'pay_original', $this->command(), 'pay_original');
        $this->assertFalse($result['paid']); $this->assertTrue($result['review']);
    }

    public function testBoletoPaidByPixRequiresReceivedAndNetValueDoesNotReprice(): void
    {
        $payment = $this->payment(); $payment['netValue'] = '23.01';
        $this->assertTrue(PaymentVerification::verify($payment, 'pay_original', $this->command('BOLETO'), 'pay_original')['paid']);
        $payment['status'] = 'CONFIRMED';
        $this->assertFalse(PaymentVerification::verify($payment, 'pay_original', $this->command('BOLETO'), 'pay_original')['paid']);
        $this->assertTrue(PaymentVerification::verify($this->payment('CREDIT_CARD', 'CONFIRMED'), 'pay_original', $this->command('UNDEFINED'), 'pay_original')['paid']);
    }

    public function testMonthlyCalendarUsesOriginalAnchorWithLeapYearAndNoEndOfMonthDrift(): void
    {
        $this->assertSame(['start' => '2028-02-29 03:00:00', 'end' => '2028-03-31 03:00:00'], MonthlyPeriod::forDueDate('2028-01-31', '2028-02-29'));
        $this->assertSame(['start' => '2027-02-28 03:00:00', 'end' => '2027-03-31 03:00:00'], MonthlyPeriod::forDueDate('2026-12-31', '2027-02-28'));
        $this->expectException(\InvalidArgumentException::class);
        MonthlyPeriod::forDueDate('2026-10-15', '2026-11-16');
    }

    public function testWebhookAuthenticationIsIntegrationScopedAndUsesDistinctSecretWithSalesDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn(['sandbox' => ['environment' => 'sandbox', 'creationEnabled' => false,
            'apiKeyEnv' => 'CREDIT_PAYMENT_TEST_API', 'webhookTokenEnv' => 'CREDIT_PAYMENT_TEST_WEBHOOK']]);
        $previousApi = getenv('CREDIT_PAYMENT_TEST_API'); $previousWebhook = getenv('CREDIT_PAYMENT_TEST_WEBHOOK');
        try {
            putenv('CREDIT_PAYMENT_TEST_API=fixture-api-key'); putenv('CREDIT_PAYMENT_TEST_WEBHOOK=' . str_repeat('w', 40));
            $connections = new Connections($config);
            $this->assertSame('sandbox', $connections->authenticate('sandbox', str_repeat('w', 40)));
            foreach ([['sandbox', ''], ['sandbox', 'fixture-api-key'], ['sandbox', str_repeat('x', 40)], ['other', str_repeat('w', 40)]] as [$id, $token]) {
                try { $connections->authenticate($id, $token); $this->fail('Invalid webhook authentication allowed.'); } catch (Forbidden) { $this->addToAssertionCount(1); }
            }
            foreach ([31, 32, 255, 256] as $length) {
                $token = str_repeat('w', $length); putenv('CREDIT_PAYMENT_TEST_WEBHOOK=' . $token);
                try {
                    $this->assertSame('sandbox', $connections->authenticate('sandbox', $token));
                    $this->assertContains($length, [32, 255]);
                } catch (Forbidden) { $this->assertContains($length, [31, 256]); }
            }
            putenv('CREDIT_PAYMENT_TEST_WEBHOOK=' . str_repeat('w', 40));
            putenv('CREDIT_PAYMENT_TEST_API=' . str_repeat('w', 40));
            $this->expectException(Forbidden::class); $connections->authenticate('sandbox', str_repeat('w', 40));
        } finally {
            putenv($previousApi === false ? 'CREDIT_PAYMENT_TEST_API' : 'CREDIT_PAYMENT_TEST_API=' . $previousApi);
            putenv($previousWebhook === false ? 'CREDIT_PAYMENT_TEST_WEBHOOK' : 'CREDIT_PAYMENT_TEST_WEBHOOK=' . $previousWebhook);
        }
    }
}
