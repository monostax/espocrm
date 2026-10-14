<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Controllers\CreditEnrichmentQuotes;
use Espo\Modules\FeatureCredits\Enrichment\Access;
use Espo\Modules\FeatureCredits\Enrichment\Catalog;
use Espo\Modules\FeatureCredits\Enrichment\ProviderAvailability;
use Espo\Modules\FeatureCredits\Enrichment\QuoteInput;
use Espo\Modules\FeatureCredits\Enrichment\Quotes;
use Espo\Modules\FeatureCredits\Enrichment\RoutePlanner;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SchemaTest.php';
require_once __DIR__ . '/EnrichmentFixtures.php';
require_once __DIR__ . '/EnrichmentAdmissionCases.php';
require_once __DIR__ . '/EnrichmentExecutionCases.php';
require_once __DIR__ . '/EnrichmentFieldCacheCases.php';
require_once __DIR__ . '/EnrichmentDiscoveryQuoteCases.php';
require_once __DIR__ . '/EnrichmentPurchaseCases.php';
require_once __DIR__ . '/EnrichmentCandidateCacheCases.php';
require_once __DIR__ . '/EnrichmentDiscoveryCallCases.php';
require_once __DIR__ . '/EnrichmentDiscoveryExecutionCases.php';
require_once __DIR__ . '/EnrichmentDiscoveryAdmissionCases.php';
require_once __DIR__ . '/EnrichmentPreviewCases.php';
require_once __DIR__ . '/EnrichmentSelectedProviderCases.php';

class EnrichmentQuoteDatabaseTest extends TestCase
{
    use SchemaFixture;
    use EnrichmentAdmissionCases;
    use EnrichmentExecutionCases;
    use EnrichmentFieldCacheCases;
    use EnrichmentDiscoveryQuoteCases;
    use EnrichmentPurchaseCases;
    use EnrichmentCandidateCacheCases;
    use EnrichmentDiscoveryCallCases;
    use EnrichmentDiscoveryExecutionCases;
    use EnrichmentDiscoveryAdmissionCases;
    use EnrichmentPreviewCases;
    use EnrichmentSelectedProviderCases;

    private string $now = '2026-10-11 12:00:00';
    private string $actor = 'user';
    private bool $allowed = true;
    private bool $available = true;
    private string $revision = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private ?Access $executionAccess = null;
    private array $hiddenIdentityFields = [];
    private array $deniedIdentityIds = [];
    private array $unavailableDiscoveryProviders = [];
    private array $deniedScopes = [];
    private array $deniedWrites = [];
    private bool $apiActor = false;
    private ?ProviderAvailability $providerAvailability = null;

    public static function dialects(): iterable
    {
        yield 'MySQL' => ['Mysql'];
        yield 'PostgreSQL' => ['Postgresql'];
    }

    private function database(string $dialect, callable $test): void
    {
        $dsn = getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN');
        if (!$dsn) $this->markTestSkipped('Requires an empty feature_credits_schema_test database.');
        $pdo = new PDO($dsn, 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame('feature_credits_schema_test', $pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query($dialect === 'Mysql' ?
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()' :
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'")->fetchColumn());
        $schema = $this->schema($dialect);
        $platform = $dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform();
        try {
            foreach ($schema->toSql($platform) as $sql) $pdo->exec($sql);
            $pdo->exec('CREATE TABLE tenant (id VARCHAR(17) PRIMARY KEY, deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec("INSERT INTO tenant (id) VALUES ('tenant'), ('other')");
            $pdo->exec('CREATE TABLE contact (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(17) NOT NULL,
                deleted BOOLEAN NOT NULL DEFAULT FALSE, cpf VARCHAR(14), first_name VARCHAR(64), email_address VARCHAR(128), linkedin_url VARCHAR(512))');
            $pdo->exec("INSERT INTO contact (id, tenant_id, cpf, first_name, email_address) VALUES
                ('contact', 'tenant', '52998224725', 'Person', 'person@example.test')");
            $pdo->exec('CREATE TABLE account (id VARCHAR(17) PRIMARY KEY, tenant_id VARCHAR(17) NOT NULL,
                deleted BOOLEAN NOT NULL DEFAULT FALSE, cnpj VARCHAR(18), name VARCHAR(64), website VARCHAR(128), linkedin_url VARCHAR(512))');
            $pdo->exec("INSERT INTO account (id, tenant_id, cnpj, name, website) VALUES
                ('contact', 'tenant', '11222333000181', 'Company', 'https://example.test')");
            $pdo->exec('CREATE TABLE contact_channel_identity (id VARCHAR(17) PRIMARY KEY, contact_id VARCHAR(17), tenant_id VARCHAR(17),
                channel_type VARCHAR(32), source_id VARCHAR(255), ownership_status VARCHAR(32), is_primary BOOLEAN NOT NULL DEFAULT FALSE,
                deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            [$quotes, $catalog] = $this->services($pdo);
            $catalog->publish((object) EnrichmentFixtures::definition(), 'admin');
            $this->now = '2026-10-12 00:00:00';
            $test($pdo, $quotes, $catalog);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('DROP TABLE IF EXISTS contact_channel_identity');
            $pdo->exec('DROP TABLE IF EXISTS account');
            $pdo->exec('DROP TABLE IF EXISTS contact'); $pdo->exec('DROP TABLE IF EXISTS tenant');
            foreach (array_reverse($schema->getTables()) as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
        }
    }

    private function services(PDO $pdo): array
    {
        $manager = $this->createStub(EntityManager::class); $manager->method('getPDO')->willReturn($pdo);
        $manager->method('getEntityById')->willReturnCallback(static function (string $type, string $id) use ($pdo): ?Entity {
            $table = match ($type) { 'Tenant' => 'tenant', 'Account' => 'account', 'ContactChannelIdentity' => 'contact_channel_identity', default => 'contact' };
            $q = $pdo->prepare("SELECT * FROM $table WHERE id=?"); $q->execute([$id]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            $values = [];
            foreach ($row as $key => $value) {
                $key = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
                $values[$key] = $value;
            }
            $entity = new BaseEntity($type, ['attributes' => array_fill_keys(array_keys($values), ['type' => 'varchar'])]);
            $entity->set($values);
            return $entity;
        });
        $clock = $this->createStub(Clock::class); $clock->method('now')->willReturnCallback(fn () => $this->now);
        $user = $this->createStub(User::class); $user->method('getId')->willReturnCallback(fn () => $this->actor);
        $user->method('isApi')->willReturnCallback(fn () => $this->apiActor);
        $acl = $this->createStub(Acl::class);
        $acl->method('getPermissionLevel')->willReturnCallback(fn () => $this->allowed ? 'yes' : 'no');
        $acl->method('checkScope')->willReturnCallback(fn ($scope, $action) => !in_array($scope . '.' . $action, $this->deniedScopes, true));
        $acl->method('check')->willReturnCallback(fn ($entity) => !in_array($entity->getId(),$this->deniedIdentityIds,true));
        $acl->method('checkField')->willReturnCallback(fn ($scope,$field,$action = 'read') =>
            !in_array($scope . '.' . $field, $action === 'read' ? $this->hiddenIdentityFields : $this->deniedWrites, true));
        $tenants = $this->createStub(UserTenantResolver::class); $tenants->method('canActForTenant')->willReturn(true);
        $access = new Access($user, $acl, $tenants, $manager);
        $this->executionAccess = $access;
        $availability = $this->createStub(ProviderAvailability::class);
        $availability->method('inspect')->willReturnCallback(function ($tenant, $type, $products, $identity, $waterfall): array {
            if (!$this->available) return [];
            if (in_array($products, [['employees'], ['socios'], ['cnpj']], true)) {
                $providers = match ($products[0]) {
                    'employees' => ['apollo'], 'socios' => $waterfall ? ['cpfcnpj', 'hub'] : ['cpfcnpj'], 'cnpj' => ['registrobr'],
                };
                return array_fill_keys(array_diff($providers, $this->unavailableDiscoveryProviders), array_fill_keys($products, $this->revision));
            }
            return array_fill_keys($waterfall ? ['apollo', 'cpfcnpj', 'hub'] : ['apollo'], array_fill_keys($products, $this->revision));
        });
        $catalog = new Catalog($manager, $clock);
        $lock = new \Espo\Modules\FeatureCredits\Accounting\WalletLock($manager, $clock);
        $reservations = new \Espo\Modules\FeatureCredits\Accounting\Reservations($lock, new \Espo\Modules\FeatureCredits\Accounting\Funding($lock));
        return [new Quotes($manager, $clock, $access, $catalog, $this->providerAvailability ?? $availability, new RoutePlanner(), $lock, $reservations), $catalog];
    }

    private function input(array $changes = []): QuoteInput
    {
        return new QuoteInput((object) [...['tenantId' => 'tenant', 'targetType' => 'Contact', 'targetId' => 'contact',
            'products' => ['email', 'phone'], 'requestKey' => 'request-1', 'waterfall' => true, 'budget' => '8'], ...$changes]);
    }

    #[DataProvider('dialects')]
    public function testDiscoveryCatalogCutoverRequotesFieldsAndPreservesConfirmedTerms(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $confirmed = $quotes->quote($this->input());
            $confirmed = $quotes->confirm('tenant', $confirmed['id']);
            $stale = $quotes->quote($this->input(['requestKey' => 'stale']));
            $next = $catalog->publish((object) EnrichmentFixtures::discoveryDefinition(), 'admin');
            $this->now = '2026-10-13 00:00:00';
            $this->assertSame($confirmed, $quotes->confirm('tenant', $confirmed['id']));
            try { $quotes->confirm('tenant', $stale['id']); $this->fail('Old unconfirmed catalog accepted.'); }
            catch (Conflict) {}
            $current = $quotes->quote($this->input(['requestKey' => 'new-catalog']));
            $this->assertSame($next['policy']['policyId'], $current['policyId']);
            $this->assertSame($confirmed['maximum'], $current['maximum']);
            foreach ($current['routes'] as $product => $route) {
                $this->assertSame(array_column($confirmed['routes'][$product], 'provider'), array_column($route, 'provider'));
                $this->assertSame(array_column($confirmed['routes'][$product], 'credits'), array_column($route, 'credits'));
                foreach ($route as $step) $this->assertSame($current['policyId'], $step['version']);
            }
            $accepted = $quotes->confirm('tenant', $current['id']);
            $this->assertTrue($accepted['confirmable']);
            $this->assertFalse($accepted['dispatchAllowed']);
            foreach (['credit_usage', 'credit_reservation', 'credit_transaction'] as $table) {
                $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testQuoteApiFreezesSharedCapAndConfirmationReplaysWithoutFinancialOrIdentityPayloads(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $controller = new CreditEnrichmentQuotes($quotes);
            $request = $this->createStub(Request::class);
            $request->method('getBodyContents')->willReturn(json_encode($this->input()->data, JSON_THROW_ON_ERROR));
            $quoted = (array) $controller->postActionQuote($request, $this->createStub(Response::class));
            $this->assertSame($quoted, \Espo\Modules\FeatureCredits\Enrichment\CustomerProjection::quote($quotes->quote($this->input())));
            $this->assertSame('8.0000', $quoted['maximum']);
            $this->assertSame(['email', 'phone'], $quoted['products']);
            $this->assertSame([1, 2, 3], array_column($quoted['routes']['email'], 'source'));
            foreach (['cpfcnpj', 'hub', 'apollo', 'policyId', 'provider'] as $private) {
                $this->assertStringNotContainsString($private, json_encode($quoted));
            }
            $this->assertStringNotContainsString('52998224725', json_encode($quoted));
            $this->assertStringNotContainsString('person@example.test', json_encode($quoted));
            $confirm = $this->createStub(Request::class);
            $confirm->method('getBodyContents')->willReturn(json_encode(['tenantId' => 'tenant', 'quoteId' => $quoted['id']]));
            $receipt = (array) $controller->postActionConfirm($confirm, $this->createStub(Response::class));
            $this->assertSame('terms_confirmed', $receipt['state']);
            $this->assertSame('not_admitted', $receipt['admission']);
            $this->assertFalse($receipt['dispatchAllowed']);
            $catalog->publish((object) EnrichmentFixtures::definition('v2', '2026-10-13 00:00:00'), 'admin');
            $this->now = '2026-10-13 00:00:00';
            $this->assertSame($receipt, \Espo\Modules\FeatureCredits\Enrichment\CustomerProjection::quote($quotes->confirm('tenant', $quoted['id'])));
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_quote')->fetchColumn());
            foreach (['tenant_credit_balance', 'credit_reservation', 'credit_usage', 'credit_transaction'] as $table) {
                $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
            }
        });
    }

    #[DataProvider('dialects')]
    public function testIdentityCatalogCapabilityChangesRequireNewQuoteAndChangedSettingsConflict(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes, Catalog $catalog): void {
            $quote = $quotes->quote($this->input());
            try { $quotes->quote($this->input(['budget' => '3'])); $this->fail('Existing settings changed.'); } catch (Conflict) {}
            try { $quotes->quote($this->input(['refresh' => true])); $this->fail('Retry silently became paid refresh.'); } catch (Conflict) {}
            foreach (["UPDATE contact SET cpf='01234567890'", "UPDATE contact SET first_name='Different'",
                "UPDATE contact SET email_address='different@example.test'"] as $sql) {
                $pdo->exec($sql);
                try { $quotes->confirm('tenant', $quote['id']); $this->fail('Changed identity confirmed.'); } catch (Conflict) {}
                $pdo->exec("UPDATE contact SET cpf='52998224725', first_name='Person', email_address='person@example.test'");
            }
            $this->revision = str_repeat('b', 64);
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Changed connection/capability accepted.'); } catch (Conflict) {}
            $this->revision = str_repeat('a', 64);
            $catalog->publish((object) EnrichmentFixtures::definition('v2', '2026-10-13 00:00:00'), 'admin');
            $this->now = '2026-10-13 00:00:00';
            $this->assertSame($quote, $quotes->quote($this->input()), 'Quote retries retain original terms even when now stale.');
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Changed catalog accepted.'); } catch (Conflict) {}
            $this->assertNull($pdo->query('SELECT confirmed_at FROM enrichment_quote')->fetchColumn());
            $fresh = $quotes->quote($this->input(['requestKey' => 'request-2']));
            $this->assertSame('terms_confirmed', $quotes->confirm('tenant', $fresh['id'])['state']);
        });
    }

    #[DataProvider('dialects')]
    public function testRevokedAccessForeignTenantAndOtherActorCannotReadOrConfirmQuote(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes): void {
            $quote = $quotes->quote($this->input());
            $this->allowed = false;
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Revoked permission accepted.'); } catch (Forbidden) {}
            $this->allowed = true;
            try { $quotes->confirm('other', $quote['id']); $this->fail('Other tenant accepted.'); } catch (NotFound) {}
            $this->actor = 'other-user';
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Other actor accepted.'); } catch (NotFound) {}
            $this->actor = 'user';
            $pdo->exec("UPDATE contact SET tenant_id='other'");
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Moved record accepted.'); } catch (Forbidden) {}
            $this->assertNull($pdo->query('SELECT confirmed_at FROM enrichment_quote')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testUnconfiguredAndUnaffordableProvidersCannotBeConfirmed(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes): void {
            $this->available = false;
            $quote = $quotes->quote($this->input());
            $this->assertSame('0.0000', $quote['maximum']);
            $this->assertFalse($quote['confirmable']);
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Unavailable work confirmed.'); } catch (Conflict) {}
            $this->available = true;
            $quote = $quotes->quote($this->input(['budget' => '0.5', 'requestKey' => 'cheap']));
            $this->assertSame(['email' => [], 'phone' => []], $quote['routes']);
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Unaffordable work confirmed.'); } catch (Conflict) {}
            $off = $quotes->quote($this->input(['budget' => null, 'waterfall' => false, 'requestKey' => 'off']));
            $this->assertSame('9.0000', $off['budget']);
            $this->assertSame(['apollo'], array_column($off['routes']['phone'], 'provider'));
        });
    }

    #[DataProvider('dialects')]
    public function testConfirmationWriteFailureRollsBackAndCorruptSnapshotCannotBeConfirmed(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes) use ($dialect): void {
            $quote = $quotes->quote($this->input());
            $pdo->exec('ALTER TABLE enrichment_quote ADD CONSTRAINT injected_failure CHECK (confirmed_at IS NULL)');
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Confirmation write failure swallowed.'); }
            catch (PDOException) { $this->assertFalse($pdo->inTransaction()); }
            finally { $pdo->exec('ALTER TABLE enrichment_quote DROP ' . self::dropCheckKeyword($pdo) . ' injected_failure'); }
            $this->assertNull($pdo->query('SELECT confirmed_at FROM enrichment_quote')->fetchColumn());
            $pdo->exec("UPDATE enrichment_quote SET snapshot='{}'");
            try { $quotes->confirm('tenant', $quote['id']); $this->fail('Corrupt terms accepted.'); } catch (Conflict) {}
            $this->assertNull($pdo->query('SELECT confirmed_at FROM enrichment_quote')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testConfirmationRejectsClientPricesAndAdditionalSettings(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes): void {
            $quote = $quotes->quote($this->input());
            $controller = new CreditEnrichmentQuotes($quotes);
            foreach (['maximum' => '0.0001', 'waterfall' => false, 'actorId' => 'admin'] as $key => $value) {
                $request = $this->createStub(Request::class);
                $request->method('getBodyContents')->willReturn(json_encode(['tenantId' => 'tenant', 'quoteId' => $quote['id'], $key => $value]));
                try { $controller->postActionConfirm($request, $this->createStub(Response::class)); $this->fail('Forged confirmation accepted.'); }
                catch (BadRequest) { $this->addToAssertionCount(1); }
            }
            $this->assertNull($pdo->query('SELECT confirmed_at FROM enrichment_quote')->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testIndependentWorkersSerializeQuoteReplayConfirmationAndIdentityRecheck(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Quotes $quotes) use ($dialect): void {
            $payload = $this->input()->data;
            $results = $this->race($pdo, $dialect, 'quote', [$payload, $payload]);
            $this->assertArrayHasKey('result', $results[0]);
            $this->assertSame($results[0], $results[1]);
            $quote = $results[0]['result'];
            $this->assertSame($quote, $quotes->quote($this->input()));
            $changed = $this->input(['budget' => '3'])->data;
            $results = $this->race($pdo, $dialect, 'quote', [$payload, $changed]);
            $this->assertSame(['result' => $quote], $results[0]);
            $this->assertSame(['error' => Conflict::class], $results[1]);
            $confirm = ['tenantId' => 'tenant', 'quoteId' => $quote['id']];
            $results = $this->race($pdo, $dialect, 'confirm', [$confirm, $confirm]);
            $this->assertArrayHasKey('result', $results[0]);
            $this->assertSame('terms_confirmed', $results[0]['result']['state']);
            $this->assertSame($results[0], $results[1]);
            $other = $quotes->quote($this->input(['requestKey' => 'identity-race']));
            $confirm['quoteId'] = $other['id'];
            $results = $this->race($pdo, $dialect, 'confirm', [$confirm, $confirm], "UPDATE contact SET cpf='01234567890'");
            $this->assertSame([['error' => Conflict::class], ['error' => Conflict::class]], $results);
            $q = $pdo->prepare('SELECT confirmed_at FROM enrichment_quote WHERE id=?'); $q->execute([$other['id']]);
            $this->assertNull($q->fetchColumn());
            $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM enrichment_quote')->fetchColumn());
        });
    }

    private function race(PDO $pdo, string $dialect, string $operation, array $payloads, ?string $beforeCommit = null, bool $lockContact = false): array
    {
        $workers = [];
        $pdo->beginTransaction();
        $pdo->query("SELECT id FROM tenant WHERE id='tenant' FOR UPDATE")->fetchColumn();
        if ($lockContact) $pdo->query("SELECT id FROM contact WHERE id='contact' FOR UPDATE")->fetchColumn();
        try {
            foreach ($payloads as $payload) {
                $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/enrichment-quote-worker.php', $dialect, $operation,
                    base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $this->now],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes]; stream_set_timeout($pipes[1], 15);
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($workers as [$process, $pipes]) {
                $read = [$pipes[1]]; $write = $except = [];
                $this->assertSame(0, stream_select($read, $write, $except, 0, 150000));
                $this->assertTrue(proc_get_status($process)['running']);
            }
            if ($beforeCommit !== null) $pdo->exec($beforeCommit);
            $pdo->commit();
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = json_decode(fgets($pipes[1]), true, 32, JSON_THROW_ON_ERROR);
                $this->assertSame('', stream_get_contents($pipes[2]));
            }
            return $results;
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($workers as [$process, $pipes]) {
                if (proc_get_status($process)['running']) proc_terminate($process);
                foreach ($pipes as $pipe) fclose($pipe);
                proc_close($process);
            }
        }
    }
}
