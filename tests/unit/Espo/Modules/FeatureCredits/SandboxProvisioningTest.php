<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Billing\Asaas\{Connection, Response};
use Monostax\CreditBillingSandbox\{Http, Journal, Webhook};
use PHPUnit\Framework\TestCase;

require_once 'scripts/lib/credit-billing-sandbox-tools.php';

final class SandboxHttpFixture extends Http
{
    public array $calls = [];
    public function __construct(public array $responses) {}
    public function send(Connection $connection, string $method, string $path, ?array $body = null, array $query = []): Response
    {
        self::validate($connection, $method, $path, $body, $query);
        $this->calls[] = [$method, $path];
        return array_shift($this->responses) ?? throw new \RuntimeException('unexpected_test_io');
    }
}

final class SandboxProvisioningTest extends TestCase
{
    private array $files = [];
    protected function tearDown(): void { foreach ($this->files as $file) unlink($file); }
    private function journal(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sandbox-journal-'); $this->files[] = $path;
        return [$path, new Journal($path, 'account-hash')];
    }
    private function connection(bool $enabled = true, string $environment = 'sandbox'): Connection
    { return new Connection('asaas-sandbox', $environment, 'fixture-key', $enabled); }
    private function listing(array $rows = []): Response
    { return new Response(200, ['object' => 'list', 'data' => $rows, 'totalCount' => (string) count($rows), 'offset' => '0', 'hasMore' => false]); }
    private function webhook(): array
    {
        return ['id' => 'webhook-original', 'name' => Webhook::NAME, 'url' => Webhook::URL, 'email' => Webhook::EMAIL,
            'enabled' => true, 'interrupted' => false, 'apiVersion' => '3', 'hasAuthToken' => true,
            'sendType' => 'SEQUENTIALLY', 'events' => Webhook::EVENTS];
    }

    public function testSandboxTransportRejectsProductionAndUnapprovedOperations(): void
    {
        foreach ([[$this->connection(environment: 'production'), 'GET', '/wallets/', null, []],
            [$this->connection(false), 'POST', '/webhooks', [], []],
            [$this->connection(), 'GET', '/webhooks', [], []],
            [$this->connection(), 'DELETE', '/webhooks/id', null, []],
            [$this->connection(), 'POST', '/payments', [], []],
            [$this->connection(), 'GET', '//evil.example', null, []],
            [$this->connection(), 'GET', '/webhooks', null, ['redirect' => 'bad']]] as $args) {
            try { Http::validate(...$args); $this->fail('Unsafe operator request allowed.'); }
            catch (\RuntimeException) { $this->addToAssertionCount(1); }
        }
        Http::validate($this->connection(), 'POST', '/sandbox/payment/pay_owned/confirm', [], []);
        $this->addToAssertionCount(1);
    }

    public function testWebhookIsCreatedOnceAndReplayedFromExactOriginalIdentity(): void
    {
        [, $journal] = $this->journal();
        $http = new SandboxHttpFixture([$this->listing(), new Response(200, $this->webhook()), $this->listing([$this->webhook()])]);
        $first = Webhook::ensure($http, $this->connection(), $journal, str_repeat('w', 64));
        $second = Webhook::ensure($http, $this->connection(), $journal, str_repeat('w', 64));
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame([['GET', '/webhooks'], ['POST', '/webhooks'], ['GET', '/webhooks']], $http->calls);
        $this->assertArrayNotHasKey('authToken', $journal->state['steps']['webhook']['receipt']);
    }

    public function testAmbiguousPostSurvivesProcessRestartAndNeverReposts(): void
    {
        [$path, $journal] = $this->journal();
        $http = new SandboxHttpFixture([$this->listing(), new Response(0, null)]);
        try { Webhook::ensure($http, $this->connection(), $journal, str_repeat('w', 64)); $this->fail('Lost response resolved.'); }
        catch (\RuntimeException) {}
        unset($journal);
        $journal = new Journal($path, 'account-hash');
        $lookup = new SandboxHttpFixture([$this->listing()]);
        try { Webhook::ensure($lookup, $this->connection(), $journal, str_repeat('w', 64)); $this->fail('Absent evidence permitted retry.'); }
        catch (\RuntimeException) { $this->assertSame([['GET', '/webhooks']], $lookup->calls); }
        $lookup = new SandboxHttpFixture([$this->listing([$this->webhook()])]);
        $this->assertSame('webhook-original', Webhook::ensure($lookup, $this->connection(), $journal, str_repeat('w', 64))['id']);
        $this->assertSame([['GET', '/webhooks']], $lookup->calls);
    }

    public function testWebhookForeignDuplicatesAndChangedSecretFailClosed(): void
    {
        foreach ([[$this->webhook()], [$this->webhook(), array_replace($this->webhook(), ['id' => 'second'])]] as $rows) {
            [, $journal] = $this->journal();
            $http = new SandboxHttpFixture([$this->listing($rows)]);
            try { Webhook::ensure($http, $this->connection(), $journal, str_repeat('w', 64)); $this->fail('Existing webhook silently adopted.'); }
            catch (\RuntimeException) { $this->assertSame([['GET', '/webhooks']], $http->calls); }
        }
        [, $journal] = $this->journal();
        $http = new SandboxHttpFixture([$this->listing(), new Response(200, $this->webhook())]);
        Webhook::ensure($http, $this->connection(), $journal, str_repeat('w', 64));
        $calls = $http->calls;
        try { Webhook::ensure($http, $this->connection(), $journal, str_repeat('x', 64)); $this->fail('Secret changed silently.'); }
        catch (\RuntimeException) { $this->assertSame($calls, $http->calls); }
    }

    public function testJournalExclusiveLockAndAccountOwnership(): void
    {
        [$path, $journal] = $this->journal();
        $this->assertTrue($journal->claim('create', 'hash'));
        $this->assertFalse($journal->claim('create', 'hash'));
        try { new Journal($path, 'account-hash'); $this->fail('Concurrent operator acquired journal.'); }
        catch (\RuntimeException) { $this->addToAssertionCount(1); }
        unset($journal);
        $this->expectException(\RuntimeException::class);
        new Journal($path, 'another-account');
    }

    public function testIncompleteListingCannotAuthorizeCreation(): void
    {
        [, $journal] = $this->journal();
        $http = new SandboxHttpFixture([new Response(200, ['data' => [], 'hasMore' => false, 'totalCount' => '1', 'offset' => '0'])]);
        try { Webhook::ensure($http, $this->connection(), $journal, str_repeat('w', 64)); $this->fail('Truncated list accepted.'); }
        catch (\RuntimeException) { $this->assertSame([], $journal->state['steps']); }
    }
}
