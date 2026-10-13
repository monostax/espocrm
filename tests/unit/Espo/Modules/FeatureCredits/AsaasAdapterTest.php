<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\Utils\Config;
use Espo\Modules\FeatureCredits\Billing\Asaas\{Adapter, Command, Connection, Connections, Json, Response, Transport};
use Espo\Modules\FeatureCredits\Billing\OfferTerms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AsaasFixtures.php';

final class AsaasAdapterTest extends TestCase
{
    private const REFERENCE = 'crm-credit-0123456789abcdef0';

    private function connection(bool $enabled = true): Connection { return new Connection('test-asaas', 'sandbox', 'secret-test-only', $enabled); }

    private function command(string $kind): Command
    {
        if ($kind === 'customer') return Command::customer(AsaasTestTransport::profile(), self::REFERENCE);
        $subscription = $kind === 'subscription';
        return Command::charge(new OfferTerms('offer', 'v1', 'product', 'v1', $subscription ? 'subscription' : 'purchase',
            ['BR'], 'BRL', '9999999999.99', '100', $subscription ? 'month' : null), 'cus_Payer1', self::REFERENCE, 'UNDEFINED', '2026-10-15');
    }

    public static function kinds(): iterable { yield ['customer']; yield ['payment']; yield ['subscription']; }

    #[DataProvider('kinds')]
    public function testCreateWireContractAndLookupOnlyRecovery(string $kind): void
    {
        $transport = new AsaasTestTransport(); $adapter = new Adapter($transport); $command = $this->command($kind);
        $created = $adapter->create($this->connection(), $command);
        $this->assertSame('bound', $created['state']);
        $this->assertSame($command->path(), $transport->calls[0][3]);
        $this->assertSame([], $transport->calls[0][5]);
        if ($kind !== 'customer') {
            $this->assertStringContainsString('"value":9999999999.99', $transport->calls[0][4]);
            $this->assertSame('9999999999.99', Json::decode($transport->calls[0][4])['value']);
            $this->assertSame('0', Json::decode($transport->calls[0][4])['interest']['value']);
            $this->assertArrayNotHasKey('creditCard', Json::decode($transport->calls[0][4]));
        }
        if ($kind === 'subscription') {
            $this->assertSame('MONTHLY', $command->body['cycle']);
            $this->assertSame('2026-10-15', $command->body['nextDueDate']);
            $transport->objects[self::REFERENCE]['nextDueDate'] = '2026-11-15';
        }
        $this->assertSame($created, $adapter->reconcile($this->connection(false), $command));
        $this->assertSame('GET', $transport->calls[1][2]); $this->assertNull($transport->calls[1][4]);
        $this->assertSame(self::REFERENCE, $transport->calls[1][5]['externalReference']);
        $this->assertSame(2, $transport->calls[1][5]['limit']);
        $this->assertSame(2, count($transport->calls));
    }

    public function testNumbersAreLosslessAndStringTokensAreNotRewritten(): void
    {
        $data = Json::decode('{"value":9999999999.99,"count":1,"fraction":0.00000000000000001,"text":"50.00 \\" 123", "deleted":false,"data":[]}');
        $this->assertSame('9999999999.99', $data['value']);
        $this->assertSame('1', $data['count']);
        $this->assertSame('0.00000000000000001', $data['fraction']);
        $this->assertSame('50.00 " 123', $data['text']);
        $this->assertFalse($data['deleted']); $this->assertSame([], $data['data']);
    }

    public static function failedResponses(): iterable
    {
        foreach ([0, 301, 400, 401, 403, 429, 500, 503] as $status) yield (string) $status => [new Response($status, ['errors' => [['code' => 'invalid_customer']]])];
        yield 'malformed' => [new Response(200, null)];
        yield 'missing object' => [new Response(200, [])];
    }

    #[DataProvider('failedResponses')]
    public function testUnverifiedResponsesRemainUnknown(Response $response): void
    {
        $transport = $this->createMock(Transport::class); $transport->expects($this->once())->method('send')->willReturn($response);
        $this->assertSame('unknown', (new Adapter($transport))->create($this->connection(), $this->command('payment'))['state']);
    }

    public static function mismatches(): iterable
    {
        foreach (['customer', 'payment', 'subscription'] as $kind) {
            yield "$kind reference" => [$kind, ['externalReference' => 'some-other-operation']];
            yield "$kind id" => [$kind, ['id' => 'wrong_prefix']];
            yield "$kind deleted" => [$kind, ['deleted' => true]];
        }
        yield 'payer document' => ['customer', ['cpfCnpj' => '11111111111']];
        yield 'payer email shape' => ['customer', ['email' => []]];
        yield 'payer name' => ['customer', ['name' => 'Another Payer']];
        yield 'payment customer' => ['payment', ['customer' => 'cus_other']];
        yield 'payment amount' => ['payment', ['value' => '9999999999.98']];
        yield 'float money' => ['payment', ['value' => 9999999999.99]];
        yield 'payment date' => ['payment', ['dueDate' => '2026-10-16']];
        yield 'unrelated subscription payment' => ['payment', ['subscription' => 'sub_other']];
        yield 'unrelated installments' => ['payment', ['installment' => 'installment_other']];
        yield 'subscription cycle' => ['subscription', ['cycle' => 'YEARLY']];
    }

    #[DataProvider('mismatches')]
    public function testCreationResponseMustMatchOriginalCommercialIdentity(string $kind, array $changes): void
    {
        $command = $this->command($kind);
        $id = match ($kind) { 'customer' => 'cus_1', 'payment' => 'pay_1', 'subscription' => 'sub_1' };
        $data = array_replace($command->body + ['id' => $id, 'deleted' => false], $changes);
        $transport = $this->createStub(Transport::class); $transport->method('send')->willReturn(new Response(200, $data));
        $this->assertSame('unknown', (new Adapter($transport))->create($this->connection(), $command)['state']);
    }

    public function testEmptyPartialDuplicateOrMismatchedLookupNeverAuthorizesRetry(): void
    {
        $command = $this->command('payment');
        $object = $command->body + ['id' => 'pay_1', 'deleted' => false];
        $valid = ['offset' => '0', 'hasMore' => false, 'totalCount' => '1', 'data' => [$object]];
        foreach ([['data' => [], 'totalCount' => '0'], ['hasMore' => true], ['offset' => '1'], ['totalCount' => '2', 'data' => [$object, $object]],
            ['data' => [array_replace($object, ['customer' => 'cus_other'])]]] as $change) {
            $transport = $this->createMock(Transport::class); $transport->expects($this->once())->method('send')
                ->with($this->anything(), 'GET', '/payments', null, $this->anything())->willReturn(new Response(200, array_replace($valid, $change)));
            $this->assertSame('unknown', (new Adapter($transport))->reconcile($this->connection(false), $command)['state']);
        }
    }

    public function testConnectionSecurityAndCreationGate(): void
    {
        $connection = $this->connection(false);
        $this->assertSame('https://api-sandbox.asaas.com/v3', $connection->baseUrl());
        $this->assertContains('User-Agent: Monostax-CRM-Billing/1', $connection->headers());
        $this->assertContains('access_token: secret-test-only', $connection->headers());
        $this->assertStringNotContainsString('secret-test-only', json_encode($connection, JSON_THROW_ON_ERROR));
        $transport = $this->createMock(Transport::class); $transport->expects($this->never())->method('send');
        $this->expectException(\LogicException::class);
        (new Adapter($transport))->create($connection, $this->command('customer'));
    }

    public function testUnsafeTransportRequestsAreRejectedBeforeNetwork(): void
    {
        foreach ([['GET', '//evil.test', null], ['GET', '/payments?x=1', null], ['GET', '/payments', '{}'], ['POST', '/payments', '{}']] as [$method, $path, $body]) {
            try { (new Transport())->send($this->connection(false), $method, $path, $body); $this->fail('Unsafe request accepted.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function testServerConfigurationDoesNotEnableCreationByDefault(): void
    {
        $env = 'FEATURE_CREDITS_ASAAS_UNIT_TEST_KEY'; $before = getenv($env); putenv($env . '=dummy-unit-test');
        try {
            $config = $this->createStub(Config::class); $config->method('get')->with('featureCreditsAsaasConnections')->willReturn([
                'test' => ['environment' => 'sandbox', 'apiKeyEnv' => $env],
            ]);
            $connections = new Connections($config);
            $this->assertFalse($connections->get('test')->creationEnabled);
            $this->expectException(ServiceUnavailable::class); $connections->get('missing');
        } finally { putenv($before === false ? $env : $env . '=' . $before); }
    }
}
