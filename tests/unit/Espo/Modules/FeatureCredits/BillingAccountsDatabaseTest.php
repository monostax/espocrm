<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\{MySQL80Platform, PostgreSQLPlatform};
use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{Conflict, NotFound};
use Espo\Core\Utils\{Config, Crypt};
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\{Accounts, Catalog, Creation, CreationStore, Providers};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Adapter, Connections, Transport};
use Espo\Modules\FeatureCredits\Controllers\CreditBilling;
use Espo\Modules\Global\Tools\Tenant\{TenantResolver, UserTenantResolver};
use Espo\ORM\{Entity, EntityManager};
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SchemaTest.php';
require_once __DIR__ . '/BillingFixtures.php';
require_once __DIR__ . '/BillingAccountFixtures.php';

final class BillingAccountsDatabaseTest extends TestCase
{
    use SchemaFixture;

    private string $now = '2026-10-12 12:00:00';

    public static function dialects(): iterable
    {
        yield 'MySQL' => ['Mysql']; yield 'PostgreSQL' => ['Postgresql'];
    }

    private function database(string $dialect, callable $test): void
    {
        $dsn = getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN');
        if (!$dsn) $this->markTestSkipped('Requires an empty feature_credits_schema_test database.');
        $pdo = new PDO($dsn, 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('feature_credits_schema_test', $pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query($dialect === 'Mysql' ?
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()' :
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'")->fetchColumn(), 'Refusing a nonempty database.');
        $schema = $this->schema($dialect); $platform = $dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform();
        try {
            foreach ($schema->toSql($platform) as $sql) $pdo->exec($sql);
            $pdo->exec('CREATE TABLE tenant (id VARCHAR(24) PRIMARY KEY, deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec("INSERT INTO tenant (id) VALUES ('tenant'), ('other')");
            $test($pdo);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('DROP TABLE IF EXISTS tenant');
            foreach (array_reverse($schema->getTables()) as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
        }
    }

    private function manager(PDO $pdo): EntityManager
    {
        $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
        $manager->method('getEntityById')->willReturn($this->createStub(Entity::class));
        return $manager;
    }

    private function services(PDO $pdo, ?Providers $providers = null, ?Clock $clock = null): array
    {
        $manager = $this->manager($pdo);
        if (!$clock) { $clock = $this->createStub(Clock::class); $clock->method('now')->willReturnCallback(fn () => $this->now); }
        $catalog = new Catalog($manager, $clock);
        return [new Accounts($manager, $clock, $catalog, $providers ?? new BillingTestProviders()), $catalog];
    }

    private function prepare(PDO $pdo, ?Providers $providers = null): array
    {
        [$accounts, $catalog] = $this->services($pdo, $providers);
        $catalog->publish(BillingFixtures::definition(), 'admin');
        $accounts->changeCountry('tenant', 'country-br', 0, 'BR', 'admin');
        $this->now = '2026-10-13 00:00:00';
        return [$accounts, $catalog];
    }

    private function acceptance(Catalog $catalog, string $key = 'buy', string $offer = 'monthly-br', string $tenant = 'tenant'): array
    {
        $current = $catalog->current();
        return [$tenant, $key, 1, $current->id, $offer, $current->offers[$offer]->termsId, 'admin'];
    }

    private function snapshot(PDO $pdo): array
    {
        return array_map(static fn ($table) => $pdo->query("SELECT * FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
            ['tenant_billing_assignment', 'credit_billing_change', 'credit_billing_obligation']);
    }

    private function creation(Accounts $accounts): Creation
    {
        $config = $this->createStub(Config::class); $config->method('get')->willReturn('billing-accounts-test-key');
        $transport = $this->createMock(Transport::class); $transport->expects($this->never())->method('send');
        return new Creation(new CreationStore($accounts, new Crypt($config), new Connections($config)), new Adapter($transport));
    }

    #[DataProvider('dialects')]
    public function testCountryOverrideAuditRevisionAndReplayWithoutCreatingWallet(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts] = $this->services($pdo);
            $empty = $this->snapshot($pdo); $context = $accounts->context('tenant');
            $this->assertSame(0, $context['revision']); $this->assertNull($context['effectiveProvider']);
            $this->assertTrue($context['catalogUnavailable']); $this->assertSame($empty, $this->snapshot($pdo));
            $first = $accounts->changeCountry('tenant', 'country', 0, 'BR', 'admin');
            $this->assertSame('asaas', $first['after']['effectiveProvider']);
            $second = $accounts->changeCountry('tenant', 'country-us', 1, 'US', 'admin');
            $this->assertSame('stripe', $second['after']['effectiveProvider']);
            $third = $accounts->changeOverride('tenant', 'override', 2, 'asaas', 'admin');
            $this->assertSame('asaas', $third['after']['effectiveProvider']);
            $accounts->changeCountry('tenant', 'country-ca', 3, 'CA', 'admin');
            $this->assertSame('asaas', $accounts->context('tenant')['effectiveProvider']);
            $accounts->changeOverride('tenant', 'remove', 4, null, 'admin');
            $this->assertNull($accounts->context('tenant')['effectiveProvider']);
            $saved = $this->snapshot($pdo);
            $this->assertSame($first, $accounts->changeCountry('tenant', 'country', 0, 'BR', 'admin'));
            $this->assertSame($saved, $this->snapshot($pdo));
            foreach ([fn () => $accounts->changeCountry('tenant', 'country', 0, 'US', 'admin'),
                fn () => $accounts->changeCountry('tenant', 'stale', 0, 'BR', 'admin'),
                fn () => $accounts->changeCountry('tenant', 'country', 0, 'BR', 'other')] as $change) {
                try { $change(); $this->fail('Changed or stale command accepted.'); } catch (Conflict) {}
                $this->assertSame($saved, $this->snapshot($pdo));
            }
            $accounts->changeCountry('other', 'country', 0, 'BR', 'other-admin');
            $this->assertSame(1, $accounts->context('other')['revision']);
            foreach (['tenant_credit_balance', 'credit_grant', 'credit_transaction'] as $table) $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testRealFinancialAccessControllerAcceptsAndReplaysOriginalTermsAfterWithdrawal(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog] = $this->prepare($pdo);
            $user = $this->createStub(User::class); $user->method('getId')->willReturn('admin');
            $user->method('getLinkMultipleIdList')->willReturn([md5('tenant-admin')]);
            $memberships = $this->createStub(UserTenantResolver::class); $memberships->method('resolveTenantIds')->willReturn(['tenant']);
            $access = new Access($user, $this->manager($pdo), $this->createStub(TenantResolver::class), $memberships);
            $controller = new CreditBilling($user, $access, $accounts, $this->creation($accounts));
            $args = $this->acceptance($catalog);
            $request = $this->createStub(Request::class); $request->method('getBodyContents')->willReturn(json_encode([
                'tenantId' => $args[0], 'requestKey' => $args[1], 'revision' => $args[2], 'catalogId' => $args[3], 'offerId' => $args[4], 'termsId' => $args[5],
            ], JSON_THROW_ON_ERROR));
            $receipt = (array) $controller->postActionAccept($request, $this->createStub(Response::class));
            $this->assertFalse($receipt['dispatchAllowed']); $this->assertArrayNotHasKey('provider', $receipt);
            $this->assertArrayNotHasKey('integrationId', $receipt); $this->assertSame('100.0000', $receipt['terms']['credits']);
            $this->assertSame('asaas', $pdo->query('SELECT provider FROM credit_billing_obligation')->fetchColumn());
            $this->assertSame('test-asaas', $pdo->query('SELECT integration_id FROM credit_billing_obligation')->fetchColumn());
            $this->assertSame('admin', $pdo->query('SELECT created_by_id FROM credit_billing_obligation')->fetchColumn());
            $withdraw = BillingFixtures::definition('withdraw', '2026-10-14 00:00:00'); $withdraw->products = $withdraw->offers = [];
            $catalog->publish($withdraw, 'admin'); $this->now = '2026-10-14 00:00:00';
            $move = $this->createStub(Request::class); $move->method('getBodyContents')->willReturn(json_encode([
                'tenantId' => 'tenant', 'requestKey' => 'move-us', 'revision' => 1, 'billingCountry' => 'US',
            ], JSON_THROW_ON_ERROR));
            $controller->postActionCountry($move, $this->createStub(Response::class));
            $before = $this->snapshot($pdo);
            $this->assertSame($receipt, (array) $controller->postActionAccept($request, $this->createStub(Response::class)));
            $this->assertSame($before, $this->snapshot($pdo));
            $this->assertSame($receipt, \Espo\Modules\FeatureCredits\Billing\CustomerProjection::acceptance(
                $accounts->readObligation('tenant', $receipt['obligationId'])['acceptance']));
            $this->assertTrue($accounts->context('tenant')['routeMismatch']);
            try { $accounts->readObligation('other', $receipt['obligationId']); $this->fail('Cross-tenant read allowed.'); } catch (NotFound) {}
            foreach (['credit_grant', 'credit_transaction', 'credit_reservation', 'tenant_credit_balance'] as $table) $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testInstanceAdministratorOverrideUsesAuthenticatedActorAndRevision(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts] = $this->prepare($pdo);
            $user = $this->createStub(User::class); $user->method('getId')->willReturn('root-admin'); $user->method('isAdmin')->willReturn(true);
            $access = new Access($user, $this->manager($pdo), $this->createStub(TenantResolver::class), $this->createStub(UserTenantResolver::class));
            $controller = new CreditBilling($user, $access, $accounts, $this->creation($accounts));
            $request = $this->createStub(Request::class); $request->method('getBodyContents')->willReturn(json_encode([
                'tenantId' => 'tenant', 'requestKey' => 'override', 'revision' => 1, 'providerOverride' => 'stripe',
            ], JSON_THROW_ON_ERROR));
            $receipt = (array) $controller->postActionOverride($request, $this->createStub(Response::class));
            $this->assertArrayNotHasKey('effectiveProvider', $receipt['after']);
            $this->assertSame('stripe', $accounts->context('tenant')['effectiveProvider']);
            $this->assertSame('root-admin', $pdo->query("SELECT created_by_id FROM credit_billing_change WHERE kind='override'")->fetchColumn());
            $this->assertSame($receipt, (array) $controller->postActionOverride($request, $this->createStub(Response::class)));
        });
    }

    #[DataProvider('dialects')]
    public function testProductionAdapterGateAndStaleTermsCannotCreateObligations(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $config = $this->createStub(Config::class);
            [$accounts, $catalog] = $this->prepare($pdo, new Providers($config, new Connections($config)));
            $args = $this->acceptance($catalog);
            $this->assertSame('billing_provider_unavailable', $accounts->context('tenant')['offers'][0]['eligibility']['blockedReason']);
            try { $accounts->accept(...$args); $this->fail('Unimplemented adapter admitted purchase.'); } catch (Conflict) {}
            [$ready] = $this->services($pdo);
            foreach ([2 => 0, 3 => str_repeat('0', 64), 5 => str_repeat('0', 64)] as $key => $value) {
                $bad = $args; $bad[$key] = $value;
                try { $ready->accept(...$bad); $this->fail('Stale terms accepted.'); } catch (Conflict) {}
            }
            $next = BillingFixtures::definition('v2', '2026-10-14 00:00:00'); $catalog->publish($next, 'admin');
            $this->now = '2026-10-14 00:00:00';
            try { $ready->accept(...$args); $this->fail('Old catalog accepted despite new publication.'); } catch (Conflict) {}
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_billing_obligation')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testPrivateOfferIsHiddenAndCannotBeAcceptedByAnotherTenantEvenWithExactTerms(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog] = $this->services($pdo);
            $input = BillingFixtures::definition();
            $input->offers[] = (object) ['offerId' => 'monthly-private', 'version' => 'v1', 'productId' => 'monthly',
                'productVersion' => 'v1', 'countries' => ['BR'], 'currency' => 'BRL', 'price' => '2023',
                'interval' => 'month', 'tenantId' => 'tenant'];
            $catalog->publish($input, 'admin');
            foreach (['tenant', 'other'] as $tenantId) $accounts->changeCountry($tenantId, 'country', 0, 'BR', 'admin');
            $this->now = $input->effectiveFrom;
            $own = $accounts->context('tenant'); $foreign = $accounts->context('other');
            $this->assertSame(['monthly-br', 'monthly-private', 'pack-br'], array_column(array_column($own['offers'], 'terms'), 'offerId'));
            $this->assertSame(['monthly-br', 'pack-br'], array_column(array_column($foreign['offers'], 'terms'), 'offerId'));
            $this->assertStringNotContainsString('monthly-private', json_encode($foreign, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('2023.00', json_encode($foreign, JSON_THROW_ON_ERROR));
            $args = $this->acceptance($catalog, 'private-plan', 'monthly-private');
            $before = $this->snapshot($pdo);
            $forged = $args; $forged[0] = 'other';
            try { $accounts->accept(...$forged); $this->fail('A foreign tenant accepted a known private offer.'); }
            catch (Conflict) { $this->assertSame($before, $this->snapshot($pdo)); }
            $receipt = $accounts->accept(...$args);
            $this->assertSame('tenant', $receipt['terms']['tenantId']);
            $this->assertSame('2023.00', $receipt['terms']['price']);
            $this->assertArrayNotHasKey('tenantId', \Espo\Modules\FeatureCredits\Billing\CustomerProjection::acceptance($receipt)['terms']);
            $this->assertSame($receipt, $accounts->accept(...$args));
            $withdraw = BillingFixtures::definition('private-withdrawn', '2026-10-14 00:00:00');
            $catalog->publish($withdraw, 'admin'); $this->now = $withdraw->effectiveFrom;
            $this->assertSame($receipt, $accounts->accept(...$args));
            $this->assertSame($receipt, $accounts->readObligation('tenant', $receipt['obligationId'])['acceptance']);
            try { $accounts->readObligation('other', $receipt['obligationId']); $this->fail('A foreign tenant read the private acceptance.'); }
            catch (NotFound) { $this->addToAssertionCount(1); }
            foreach (['credit_billing_creation', 'credit_grant', 'credit_transaction', 'tenant_credit_balance'] as $table) {
                $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testAllUnresolvedStatesPinProviderAndIntegrationAndCannotBeCancelledLocally(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            $providers = new BillingTestProviders(); [$accounts, $catalog] = $this->prepare($pdo, $providers);
            $receipt = $accounts->accept(...$this->acceptance($catalog));
            foreach (['creating', 'unknown', 'pending', 'active'] as $state) {
                $pdo->prepare('UPDATE credit_billing_obligation SET state=? WHERE id=?')->execute([$state, $receipt['obligationId']]);
                try { $accounts->cancel('tenant', 'cancel-' . $state, $receipt['obligationId'], 'admin'); $this->fail('Unresolved external work cancelled locally.'); } catch (Conflict) {}
                try { $accounts->changeOverride('tenant', 'switch-' . $state, 1, 'stripe', 'admin'); $this->fail('Obligation escaped reassignment fence.'); } catch (Conflict) {}
                try { $accounts->accept(...$this->acceptance($catalog, 'duplicate-' . $state)); $this->fail('Another subscription for unresolved product accepted.'); } catch (Conflict) {}
            }
            $providers->integration = 'replacement';
            try { $accounts->accept(...$this->acceptance($catalog, 'pack', 'pack-br')); $this->fail('Changed integration bypassed original binding.'); } catch (Conflict) {}
            $providers->enabled = false;
            $this->assertSame($receipt, $accounts->readObligation('tenant', $receipt['obligationId'])['acceptance']);
            $this->assertSame($receipt, $accounts->accept(...$this->acceptance($catalog)));
            $accounts->changeCountry('tenant', 'move-us', 1, 'US', 'admin');
            $context = $accounts->context('tenant');
            $this->assertSame('asaas', $context['effectiveProvider']); $this->assertTrue($context['pinned']); $this->assertTrue($context['routeMismatch']);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM credit_billing_obligation')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testUnstartedCancellationReleasesRouteButCannotReopenAcceptance(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog] = $this->prepare($pdo);
            $args = $this->acceptance($catalog); $receipt = $accounts->accept(...$args);
            $accounts->changeCountry('tenant', 'move-us', 1, 'US', 'admin');
            $cancel = $accounts->cancel('tenant', 'cancel', $receipt['obligationId'], 'admin');
            $this->assertSame('stripe', $cancel['after']['effectiveProvider']);
            $this->assertFalse($cancel['after']['pinned']); $this->assertSame(3, $cancel['after']['revision']);
            $saved = $this->snapshot($pdo);
            $this->assertSame($cancel, $accounts->cancel('tenant', 'cancel', $receipt['obligationId'], 'admin'));
            $this->assertSame($receipt, $accounts->accept(...$args));
            $this->assertSame($saved, $this->snapshot($pdo));
            $this->assertSame('cancelled', $accounts->readObligation('tenant', $receipt['obligationId'])['state']);
            try { $accounts->cancel('other', 'cross-tenant', $receipt['obligationId'], 'admin'); $this->fail('Cross-tenant cancellation.'); } catch (NotFound) {}
            $this->assertSame($saved, $this->snapshot($pdo));
        });
    }

    #[DataProvider('dialects')]
    public function testBindingCorruptionAndDeletedObligationsFailClosed(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog] = $this->prepare($pdo); $args = $this->acceptance($catalog); $receipt = $accounts->accept(...$args);
            $original = $pdo->query('SELECT * FROM credit_billing_obligation')->fetch(PDO::FETCH_ASSOC);
            foreach (['provider' => 'stripe', 'integration_id' => 'forged', 'catalog_id' => str_repeat('a', 64),
                'terms_snapshot' => '{}', 'billing_country' => 'US', 'state' => 'unexpected', 'acceptance_hash' => str_repeat('a', 64)] as $field => $value) {
                $pdo->prepare("UPDATE credit_billing_obligation SET $field=? WHERE id=?")->execute([$value, $receipt['obligationId']]);
                foreach ([fn () => $accounts->accept(...$args), fn () => $accounts->context('tenant')] as $read) {
                    try { $read(); $this->fail('Corrupt binding accepted.'); } catch (Conflict) { $this->addToAssertionCount(1); }
                }
                $pdo->prepare("UPDATE credit_billing_obligation SET $field=? WHERE id=?")->execute([$original[$field], $receipt['obligationId']]);
            }
            $pdo->exec('UPDATE credit_billing_obligation SET deleted=TRUE');
            try { $accounts->changeOverride('tenant', 'escape-deleted', 1, 'stripe', 'admin'); $this->fail('Deleted obligation permitted reassignment.'); } catch (Conflict) {}
            $this->assertSame('asaas', $pdo->query('SELECT effective_provider FROM tenant_billing_assignment')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testAccountAuditAcceptanceAndCancellationFailuresAreAtomic(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect): void {
            [$accounts, $catalog] = $this->prepare($pdo);
            $args = $this->acceptance($catalog);
            foreach (['tenant_billing_assignment', 'credit_billing_change', 'credit_billing_obligation'] as $table) {
                $before = $this->snapshot($pdo);
                $condition = $table === 'tenant_billing_assignment' ? 'revision=1' : ($table === 'credit_billing_change' ? "request_key='country-br'" : '1=0');
                $pdo->exec("ALTER TABLE $table ADD CONSTRAINT injected_failure CHECK ($condition)");
                try {
                    if ($table === 'credit_billing_obligation') $accounts->accept(...$args);
                    else $accounts->changeCountry('tenant', 'change', 1, 'US', 'admin');
                    $this->fail('Database fault did not abort billing command.');
                } catch (PDOException) { $this->assertSame($before, $this->snapshot($pdo)); $this->assertFalse($pdo->inTransaction()); }
                finally { $pdo->exec("ALTER TABLE $table DROP " . self::dropCheckKeyword($pdo) . ' injected_failure'); }
            }
            $receipt = $accounts->accept(...$args); $before = $this->snapshot($pdo);
            $pdo->exec("ALTER TABLE credit_billing_change ADD CONSTRAINT injected_failure CHECK (kind <> 'cancel')");
            try { $accounts->cancel('tenant', 'cancel', $receipt['obligationId'], 'admin'); $this->fail('Half-cancellation committed.'); }
            catch (PDOException) { $this->assertSame($before, $this->snapshot($pdo)); }
            finally { $pdo->exec('ALTER TABLE credit_billing_change DROP ' . self::dropCheckKeyword($pdo) . ' injected_failure'); }
        });
    }

    #[DataProvider('dialects')]
    public function testScheduledCatalogBoundaryDuringAcceptanceRollsBackObligation(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo): void {
            [$accounts, $catalog] = $this->prepare($pdo); $args = $this->acceptance($catalog);
            $catalog->publish(BillingFixtures::definition('v2', '2026-10-14 00:00:00'), 'admin');
            $clock = $this->createStub(Clock::class);
            $clock->method('now')->willReturnCallback(fn () => (int) $pdo->query('SELECT COUNT(*) FROM credit_billing_obligation')->fetchColumn() ? '2026-10-14 00:00:00' : $this->now);
            [$accounts] = $this->services($pdo, null, $clock);
            $before = $this->snapshot($pdo);
            try { $accounts->accept(...$args); $this->fail('Scheduled catalog cutover ignored.'); } catch (Conflict) {}
            $this->assertSame($before, $this->snapshot($pdo));
        });
    }

    public static function races(): iterable
    {
        foreach (self::dialects() as $name => [$dialect]) {
            foreach (['replay', 'override', 'country', 'subscription', 'purchase'] as $race) yield "$name $race" => [$dialect, $race];
        }
    }

    #[DataProvider('races')]
    public function testSeparateProcessAcceptanceAndAssignmentRaces(string $dialect, string $race): void
    {
        $this->database($dialect, function (PDO $pdo) use ($dialect, $race): void {
            [$accounts, $catalog] = $this->prepare($pdo);
            $args = $this->acceptance($catalog, 'buy', $race === 'purchase' ? 'pack-br' : 'monthly-br');
            $second = $args; $second[1] = 'buy-second';
            $jobs = [['accept', $args], match ($race) {
                'replay' => ['accept', $args],
                'override' => ['changeOverride', ['tenant', 'switch', 1, 'stripe', 'admin']],
                'country' => ['changeCountry', ['tenant', 'move', 1, 'US', 'admin']],
                default => ['accept', $second],
            }];
            $results = $this->race($pdo, $dialect, $jobs);
            $count = (int) $pdo->query('SELECT COUNT(*) FROM credit_billing_obligation')->fetchColumn();
            if ($race === 'replay') {
                $this->assertSame($results[0], $results[1]); $this->assertArrayHasKey('result', $results[0]); $this->assertSame(1, $count);
            } elseif ($race === 'purchase') {
                $this->assertArrayHasKey('result', $results[0]); $this->assertArrayHasKey('result', $results[1]); $this->assertSame(2, $count);
            } elseif ($race === 'subscription') {
                $this->assertCount(1, array_column($results, 'result')); $this->assertSame([Conflict::class], array_column($results, 'error')); $this->assertSame(1, $count);
            } elseif ($race === 'override') {
                $this->assertCount(1, array_column($results, 'result')); $this->assertSame([Conflict::class], array_column($results, 'error'));
                $this->assertSame($count ? 'asaas' : 'stripe', $accounts->context('tenant')['effectiveProvider']);
            } else {
                $this->assertArrayHasKey('result', $results[1]);
                $context = $accounts->context('tenant');
                $this->assertSame('US', $context['billingCountry']);
                $this->assertSame($count ? 'asaas' : 'stripe', $context['effectiveProvider']);
                $this->assertSame((bool) $count, $context['pinned']);
                if (!$count) $this->assertSame(['error' => Conflict::class], $results[0]);
            }
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM credit_transaction')->fetchColumn());
        });
    }

    private function race(PDO $pdo, string $dialect, array $jobs): array
    {
        $workers = []; $pdo->beginTransaction(); $pdo->query("SELECT id FROM tenant WHERE id='tenant' FOR UPDATE");
        try {
            foreach ($jobs as [$method, $args]) {
                $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/billing-account-worker.php', $dialect, $method,
                    base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), $this->now],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process); $workers[] = [$process, $pipes]; stream_set_timeout($pipes[1], 15);
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($workers as [$process, $pipes]) {
                $read = [$pipes[1]]; $write = $except = [];
                $this->assertSame(0, stream_select($read, $write, $except, 0, 150000)); $this->assertTrue(proc_get_status($process)['running']);
            }
            $pdo->commit(); $results = [];
            foreach ($workers as [$process, $pipes]) { $results[] = json_decode(fgets($pipes[1]), true, 32, JSON_THROW_ON_ERROR); $this->assertSame('', stream_get_contents($pipes[2])); }
            return $results;
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($workers as [$process, $pipes]) {
                if (proc_get_status($process)['running']) proc_terminate($process);
                foreach ($pipes as $pipe) fclose($pipe); proc_close($process);
            }
        }
    }
}
