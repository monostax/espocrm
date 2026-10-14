<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Crypt;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\BalanceStatus;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\ConsistencyAudit;
use Espo\Modules\FeatureCredits\Accounting\FixedSettlement;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Accounting\OperationHistory;
use Espo\Modules\FeatureCredits\Accounting\OperationHistoryQuery;
use Espo\Modules\FeatureCredits\Accounting\ReservationHistory;
use Espo\Modules\FeatureCredits\Accounting\ReservationHistoryQuery;
use Espo\Modules\FeatureCredits\Accounting\Reservations;
use Espo\Modules\FeatureCredits\Accounting\WalletLock;
use Espo\Modules\FeatureCredits\Controllers\CreditPhoneVerification;
use Espo\Modules\FeatureCredits\Enrichment\FieldCache;
use Espo\Modules\FeatureCredits\Enrichment\PurchasedErasure;
use Espo\Modules\FeatureCredits\Enrichment\Quotes;
use Espo\Modules\FeatureCredits\Enrichment\ReviewLock;
use Espo\Modules\FeatureCredits\Services\OperationSource;
use Espo\Modules\FeatureCredits\Verification\Access;
use Espo\Modules\FeatureCredits\Verification\ActorAccess;
use Espo\Modules\FeatureCredits\Verification\CapabilityGate;
use Espo\Modules\FeatureCredits\Verification\Execution;
use Espo\Modules\FeatureCredits\Verification\Operations;
use Espo\Modules\FeatureCredits\Verification\TwilioCredentials;
use Espo\Modules\FeatureCredits\Verification\TwilioLookup;
use Espo\Modules\FeatureCredits\Verification\TwilioTransport;
use Espo\Modules\FeatureCredits\Verification\VerificationResult;
use Espo\Modules\FeatureCredits\Verification\BounceBanCredentials;
use Espo\Modules\FeatureCredits\Verification\BounceBanLookup;
use Espo\Modules\FeatureCredits\Verification\BounceBanTransport;
use Espo\Modules\FeatureCredits\Verification\EmailResult;
use Espo\Modules\FeatureCredits\Verification\Kind;
use Espo\Modules\FeatureCredits\Verification\CustomerProjection;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SchemaTest.php';

final class PhoneVerificationDatabaseTest extends TestCase
{
    use SchemaFixture;

    private string $now = '2026-10-14 12:00:00';
    private string $actor = 'user';
    private bool $allowed = true;
    private array $deniedFields = [];
    private array $deniedWrites = [];
    private array $deniedScopes = [];
    private array $deniedEntities = [];
    private bool $apiActor = false;
    public int $providerCalls = 0;
    public ?VerificationResult $nextResult = null;
    public ?EmailResult $emailResult = null;
    public array $emailCalls = [];

    public static function dialects(): iterable
    {
        yield 'MySQL' => ['Mysql'];
        yield 'PostgreSQL' => ['Postgresql'];
    }

    private function database(string $dialect, callable $test): void
    {
        $dsn = getenv('FEATURE_CREDITS_' . strtoupper($dialect) . '_DSN');
        if (!$dsn) {
            $this->markTestSkipped('Requires an empty feature_credits_schema_test database.');
        }
        $pdo = new PDO($dsn, 'credits_test', 'credits_test', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame(
            'feature_credits_schema_test',
            $pdo->query($dialect === 'Mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn(),
        );
        $this->assertSame(
            0,
            (int) $pdo->query(
                $dialect === 'Mysql'
                    ? 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()'
                    : "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'",
            )->fetchColumn(),
        );

        $schema = $this->schema($dialect);
        $platform = $dialect === 'Mysql' ? new MySQL80Platform() : new PostgreSQLPlatform();
        $previousKillSwitch = getenv('VERIFICATION_KILL_SWITCH');
        putenv('VERIFICATION_KILL_SWITCH');

        try {
            foreach ($schema->toSql($platform) as $sql) {
                $pdo->exec($sql);
            }
            $pdo->exec('CREATE TABLE tenant (id VARCHAR(17) PRIMARY KEY, deleted BOOLEAN NOT NULL DEFAULT FALSE)');
            $pdo->exec("INSERT INTO tenant (id) VALUES ('tenant'), ('other')");

            $pdo->exec('CREATE TABLE contact (
                id VARCHAR(17) PRIMARY KEY,
                tenant_id VARCHAR(17) NOT NULL,
                deleted BOOLEAN NOT NULL DEFAULT FALSE,
                name VARCHAR(255),
                first_name VARCHAR(100),
                last_name VARCHAR(100),
                phone_number VARCHAR(64),
                email_address VARCHAR(254),
                date_of_birth VARCHAR(10),
                data_nascimento VARCHAR(10),
                cpf VARCHAR(14)
            )');
            $pdo->exec("INSERT INTO contact (id, tenant_id, name, first_name, last_name, phone_number, date_of_birth) VALUES
                ('contact', 'tenant', 'Maria Silva', 'Maria', 'Silva', '+5511987654321', NULL),
                ('contact2', 'tenant', 'Maria Silva', 'Maria', 'Silva', '+5511987654321', NULL),
                ('foreign', 'other', 'Maria Silva', 'Maria', 'Silva', '+5511987654321', NULL)");

            $pdo->exec('CREATE TABLE contact_channel_identity (
                id VARCHAR(17) PRIMARY KEY,
                contact_id VARCHAR(17),
                tenant_id VARCHAR(17),
                channel_type VARCHAR(32),
                source_id VARCHAR(255),
                ownership_status VARCHAR(32) DEFAULT \'unverified\',
                is_primary BOOLEAN NOT NULL DEFAULT FALSE,
                deleted BOOLEAN NOT NULL DEFAULT FALSE
            )');
            $pdo->exec("INSERT INTO contact_channel_identity (id, contact_id, tenant_id, channel_type, source_id, ownership_status, is_primary) VALUES
                ('chan1', 'contact', 'tenant', 'whatsapp', '+5511987654321', 'unverified', TRUE)");

            $pdo->exec("INSERT INTO tenant_credit_billing_rate
                (id, tenant_id, version, effective_from, currency, credit_unit_price, monthly_credits, created_at)
                VALUES
                ('rate-1', 'tenant', 'v1', '2026-10-01 00:00:00', 'BRL', '1.00000000', '0.0000', '2026-10-01 00:00:00'),
                ('rate-other', 'other', 'v1', '2026-10-01 00:00:00', 'BRL', '1.00000000', '0.0000', '2026-10-01 00:00:00')");

            $this->now = '2026-10-14 12:00:00';
            $this->actor = 'user';
            $this->allowed = true;
            $this->deniedFields = [];
            $this->deniedWrites = [];
            $this->deniedScopes = [];
            $this->deniedEntities = [];
            $this->apiActor = false;
            $this->providerCalls = 0;
            $this->emailCalls = [];
            $this->emailResult = new EmailResult('completed', 'task1', 'deliverable', ['score' => 99, 'flags' => ['is_accept_all' => true], 'verifiedAt' => null]);
            $this->nextResult = VerificationResult::completed(
                'verified',
                ['firstName'],
                ['firstName' => 'exact_match', 'lastName' => 'no_match', 'dateOfBirth' => 'not_checked'],
            );

            [$operations, $execution, $gate, $funding, $crypt, $manager, $acl] = $this->services($pdo);
            $gate->publish((object) [
                'enabled' => true,
                'brazilMinimalInputValidated' => true,
                'releaseApprovalRef' => 'ADR-2026-10-14',
                'allowlistedTenantIds' => ['tenant', 'other'],
            ], 'admin');
            $funding->grant(new GrantInput(
                'tenant',
                'purchase',
                'seed-grant',
                '40.0000',
                '2026-10-14 00:00:00',
                null,
                (object) ['source' => 'fixture'],
            ));

            $test($pdo, $operations, $execution, $gate, $funding, $crypt, $manager, $acl);
        } finally {
            putenv($previousKillSwitch === false ? 'VERIFICATION_KILL_SWITCH' : 'VERIFICATION_KILL_SWITCH=' . $previousKillSwitch);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('DROP TABLE IF EXISTS contact_channel_identity');
            $pdo->exec('DROP TABLE IF EXISTS contact');
            $pdo->exec('DROP TABLE IF EXISTS tenant');
            foreach (array_reverse($schema->getTables()) as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table->getName()));
            }
        }
    }

    private function services(PDO $pdo): array
    {
        $manager = $this->createStub(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $manager->method('getEntityById')->willReturnCallback(function (string $type, string $id) use ($pdo): ?Entity {
            if ($type === 'User') {
                if ($id !== 'user' && $id !== 'user2') {
                    return null;
                }
                $u = $this->createStub(User::class);
                $u->method('getId')->willReturn($id);
                $u->method('hasId')->willReturn(true);
                $u->method('isPortal')->willReturn(false);
                $u->method('isApi')->willReturn($this->apiActor);
                $u->method('isSystem')->willReturn(false);
                $u->method('get')->willReturnCallback(fn (string $k) => $k === 'isActive' ? true : null);
                return $u;
            }
            $table = match ($type) {
                'Tenant' => 'tenant',
                'ContactChannelIdentity' => 'contact_channel_identity',
                default => 'contact',
            };
            $q = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
            $q->execute([$id]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $values = [];
            foreach ($row as $key => $value) {
                $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
                $values[$camel] = $value;
            }
            $entity = new BaseEntity($type, ['attributes' => array_fill_keys(array_keys($values), ['type' => 'varchar'])]);
            $entity->set($values);
            return $entity;
        });

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturnCallback(fn () => $this->now);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturnCallback(fn () => $this->actor);
        $user->method('hasId')->willReturn(true);
        $user->method('isPortal')->willReturn(false);
        $user->method('isApi')->willReturnCallback(fn () => $this->apiActor);
        $user->method('isSystem')->willReturn(false);

        $acl = $this->createStub(Acl::class);
        $acl->method('getPermissionLevel')->willReturnCallback(fn () => $this->allowed ? 'yes' : 'no');
        $acl->method('checkScope')->willReturnCallback(
            fn (string $scope, ?string $action = 'read') => !in_array($scope . '.' . ($action ?? 'read'), $this->deniedScopes, true),
        );
        $acl->method('check')->willReturnCallback(
            fn (Entity $entity, ?string $action = 'read') => !in_array($entity->getId() . '.' . ($action ?? 'read'), $this->deniedEntities, true) &&
                !in_array($entity->getId(), $this->deniedEntities, true),
        );
        $acl->method('checkField')->willReturnCallback(
            fn (string $scope, string $field, string $action = 'read') => !in_array(
                $scope . '.' . $field,
                $action === 'read' ? $this->deniedFields : $this->deniedWrites,
                true,
            ),
        );

        $tenants = $this->createStub(UserTenantResolver::class);
        $tenants->method('canActForTenant')->willReturnCallback(fn ($u, string $t) => in_array($t, ['tenant', 'other'], true));

        $config = new class extends Config {
            public function __construct() {}

            public function get(string $name, mixed $default = null): mixed
            {
                return $name === 'cryptKey' ? 'phone-verification-test-crypt-key' : $default;
            }
        };
        $crypt = new Crypt($config);
        $access = new Access($user, $acl, $tenants, $manager, $crypt);

        $aclManager = $this->createStub(AclManager::class);
        $aclManager->method('createUserAcl')->willReturn($acl);
        $actorAccess = new ActorAccess($manager, $aclManager, $tenants, $crypt);

        $creds = new class extends TwilioCredentials {
            public function get(): array
            {
                return ['accountSid' => 'AC_TEST', 'authToken' => 'AUTH_TEST'];
            }
        };
        $emailCredentials = new class extends BounceBanCredentials {
            public function get(): array { return ['apiKey' => 'test-only']; }
        };
        $gate = new CapabilityGate($config, $creds, $clock, emailCredentials: $emailCredentials);
        $lock = new WalletLock($manager, $clock);
        $funding = new Funding($lock);
        $reservations = new Reservations($lock, $funding);
        $settlement = new FixedSettlement($funding);
        $reviewLock = new ReviewLock($manager);

        $testCase = $this;
        $lookup = new class($creds, new TwilioTransport(), $testCase) extends TwilioLookup {
            public function __construct(TwilioCredentials $c, TwilioTransport $t, private PhoneVerificationDatabaseTest $suite)
            {
                parent::__construct($c, $t);
            }

            public function verify(array $identity, int $timeoutMs = 30000): VerificationResult
            {
                $this->suite->providerCalls++;
                return $this->suite->nextResult ?? VerificationResult::failed('no_fixture');
            }
        };

        $operations = new Operations($manager, $clock, $access, $gate, $lock, $reviewLock, $reservations, $crypt, $config);
        $emailLookup = new class($emailCredentials, new BounceBanTransport(), $this) extends BounceBanLookup {
            public function __construct(BounceBanCredentials $c, BounceBanTransport $t, private PhoneVerificationDatabaseTest $suite) { parent::__construct($c, $t); }
            public function verify(string $email, ?string $taskId, int $timeoutMs): EmailResult {
                $this->suite->emailCalls[] = [$email, $taskId];
                return $this->suite->emailResult ?? new EmailResult('failed');
            }
        };
        $execution = new Execution($manager, $lock, $actorAccess, $gate, $lookup, $settlement, $crypt, $config, $clock, emailProvider: $emailLookup);

        return [$operations, $execution, $gate, $funding, $crypt, $manager, $acl];
    }

    private function seedPurchasedPhones(PDO $pdo, Crypt $crypt, string $quoteId, string $attemptId, string $provider, array $phones, string $product = 'phone'): void
    {
        $request = [
            'tenantId' => 'tenant',
            'targetType' => 'Contact',
            'targetId' => 'contact',
            'products' => [$product],
            'requestKey' => 'req-' . $quoteId,
            'waterfall' => true,
            'refresh' => false,
            'budget' => '8.0000',
        ];
        $snapshot = [
            'request' => $request,
            'actorId' => 'user',
            'identity' => ['firstName' => 'Maria', 'lastName' => 'Silva'],
            'policyId' => str_repeat('a', 64),
            'routingPolicy' => 'br-identifiers-v1',
            'availability' => [$provider => [$product => str_repeat('a', 64)]],
            'budget' => '8.0000',
            'maximum' => '8.0000',
            'routes' => [$product => [['provider' => $provider, 'credits' => '8.0000', 'version' => str_repeat('a', 64)]]],
        ];
        $pdo->prepare('INSERT INTO enrichment_quote
            (id, tenant_id, target_type, target_id, request_key, snapshot_hash, snapshot, created_at, created_by_id, confirmed_at, admitted_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([
                $quoteId,
                'tenant',
                'Contact',
                'contact',
                'req-' . $quoteId,
                Quotes::hash($snapshot),
                json_encode($snapshot, JSON_THROW_ON_ERROR),
                $this->now,
                'user',
                $this->now,
                $this->now,
            ]);
        $binding = ['source_attempt_id' => null, 'acquisition_quote_id' => null, 'acquired_at' => $this->now];
        $pdo->prepare('INSERT INTO enrichment_attempt
            (id, tenant_id, quote_id, product, provider, state, claim_token, claimed_at, deadline_at, lease_until,
             credits, result_ciphertext, result_hash, result_expires_at, completed_at, purchased_at, acquired_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                $attemptId,
                'tenant',
                $quoteId,
                $product,
                $provider,
                'matched',
                bin2hex(random_bytes(24)),
                $this->now,
                $this->now,
                $this->now,
                '8.0000',
                $crypt->encrypt(json_encode($phones, JSON_THROW_ON_ERROR)),
                FieldCache::resultHash('tenant', $quoteId, $attemptId, $provider, $product, $phones, $binding),
                '2027-01-12 12:00:00',
                $this->now,
                $this->now,
                $this->now,
            ]);
    }

    private function emailStart(Operations $operations, string $key, array $source = ['key' => 'email', 'kind' => 'contact_email'], bool $refresh = false): array
    {
        $context = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source]], Kind::EMAIL);
        return $operations->start((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'source' => $source,
            'requestKey' => $key, 'contextRevision' => $context['rows'][$source['key']]['contextRevision'], 'refresh' => $refresh], Kind::EMAIL);
    }

    #[DataProvider('dialects')]
    public function testEmailOutcomesChargeTwoCreditsAndReuseAcrossSourcesWithoutNames(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Operations $operations, Execution $execution, CapabilityGate $gate, Funding $funding, Crypt $crypt): void {
            $pdo->exec("UPDATE contact SET email_address = ' Person+Tag@Example.com ', first_name = NULL, last_name = NULL, name = NULL WHERE id = 'contact'");
            $this->deniedFields = ['Contact.firstName', 'Contact.lastName', 'Contact.dateOfBirth'];
            $this->seedPurchasedPhones($pdo, $crypt, 'qemail', 'emailA', 'hub', ['person+tag@example.com'], 'email');
            $this->seedPurchasedPhones($pdo, $crypt, 'qemail2', 'emailB', 'cpfcnpj', ['PERSON+TAG@example.com'], 'email');
            $pdo->exec("INSERT INTO contact_channel_identity (id, contact_id, tenant_id, channel_type, source_id) VALUES ('emailchan', 'contact', 'tenant', 'email', 'person+tag@example.com')");
            $first = $this->emailStart($operations, 'email-start');
            self::assertSame('2.0000', $first['operation']['reservedCredits']);
            $joined = $this->emailStart($operations, 'email-join', ['key' => 'purchased', 'kind' => 'purchased', 'receiptId' => 'emailA', 'index' => 0]);
            self::assertSame('joined', $joined['resolution']);
            self::assertSame($first['operation']['id'], $joined['operation']['id']);
            $execution->run('tenant', $first['operation']['id']);
            $execution->run('tenant', $first['operation']['id']);
            self::assertCount(1, $this->emailCalls);
            self::assertSame(['person+tag@example.com', null], $this->emailCalls[0]);
            foreach ([['key' => 'email', 'kind' => 'contact_email'], ['key' => 'ch', 'kind' => 'channel', 'id' => 'emailchan'],
                ['key' => 'p', 'kind' => 'purchased', 'receiptId' => 'emailB', 'index' => 0]] as $index => $source) {
                self::assertSame('reused', $this->emailStart($operations, 'reuse-' . $index, $source)['resolution']);
            }
            foreach (['risky', 'undeliverable', 'unknown'] as $outcome) {
                $this->now = Operations::after($this->now, 61);
                $this->emailResult = new EmailResult('completed', 'task-' . $outcome, $outcome, ['score' => 0, 'flags' => []]);
                $attempt = $this->emailStart($operations, $outcome, refresh: true);
                $execution->run('tenant', $attempt['operation']['id']);
                $status = $operations->status(['tenantId' => 'tenant', 'contactId' => 'contact', 'operationId' => $attempt['operation']['id']], Kind::EMAIL);
                self::assertSame($outcome, $status['operation']['outcome']);
                self::assertSame('2.0000', $status['operation']['settledCredits']);
                $public = CustomerProjection::status($status);
                self::assertSame(0, $public['operation']['emailEvidence']['score']);
                self::assertArrayNotHasKey('canonicalPhone', $public['operation']);
                self::assertStringNotContainsString('task-', json_encode($public));
            }
            self::assertSame('32.0000', $pdo->query("SELECT balance FROM tenant_credit_balance WHERE tenant_id = 'tenant'")->fetchColumn());
            $this->assertHealthyAccounting($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testEmailPendingPollingRecoveryDeadlineAndErasure(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Operations $operations, Execution $execution, CapabilityGate $gate, Funding $funding, Crypt $crypt): void {
            $pdo->exec("UPDATE contact SET email_address = 'person@example.com' WHERE id = 'contact'");
            $this->emailResult = new EmailResult('pending', 'task1', tryAgainAt: strtotime($this->now . ' UTC') + 60);
            $start = $this->emailStart($operations, 'pending');
            $id = $start['operation']['id'];
            $execution->run('tenant', $id);
            $execution->run('tenant', $id);
            self::assertCount(1, $this->emailCalls);
            self::assertSame('awaiting', $pdo->query("SELECT state FROM phone_verification_operation WHERE id = '$id'")->fetchColumn());
            self::assertSame('2.0000', $pdo->query("SELECT reserved_credits FROM tenant_credit_balance WHERE tenant_id = 'tenant'")->fetchColumn());
            $this->assertHealthyAccounting($pdo);
            $this->now = Operations::after($this->now, 60);
            $claim = $execution->claim('tenant', $id);
            self::assertSame('task1', $claim['taskId']);
            self::assertNull($execution->claim('tenant', $id));
            $this->now = Operations::after($this->now, 31);
            $execution->recover('tenant', $id);
            $pdo->exec("UPDATE contact SET first_name = 'Changed', email_address = NULL WHERE id = 'contact'");
            putenv('VERIFICATION_KILL_SWITCH=true');
            $this->emailResult = new EmailResult('completed', 'task1', 'unknown', ['score' => 0, 'flags' => []]);
            $execution->run('tenant', $id);
            self::assertSame(['person@example.com', 'task1'], $this->emailCalls[1]);
            self::assertSame('2.0000', $operations->status(['tenantId' => 'tenant', 'contactId' => 'contact', 'operationId' => $id], Kind::EMAIL)['operation']['settledCredits']);
            putenv('VERIFICATION_KILL_SWITCH');
            $pdo->exec("UPDATE contact SET email_address = 'changed@example.com' WHERE id = 'contact'");
            $next = $this->emailStart($operations, 'deadline');
            $this->emailResult = new EmailResult('pending', 'task2');
            $execution->run('tenant', $next['operation']['id']);
            $this->now = Operations::after($this->now, 301);
            $execution->recover('tenant', $next['operation']['id']);
            self::assertSame('0.0000', $operations->status(['tenantId' => 'tenant', 'contactId' => 'contact', 'operationId' => $next['operation']['id']], Kind::EMAIL)['operation']['settledCredits']);
            $third = $this->emailStart($operations, 'erase');
            $execution->run('tenant', $third['operation']['id']);
            $pdo->exec("UPDATE contact SET deleted = TRUE WHERE id = 'contact'");
            $execution->recover('tenant', $third['operation']['id']);
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM phone_verification_operation WHERE id = '{$third['operation']['id']}' AND supplier_task_ciphertext IS NOT NULL")->fetchColumn());
            $this->assertHealthyAccounting($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testEmailAuthorizationFailuresRejectionAndSharedPilotLimits(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Operations $operations, Execution $execution, CapabilityGate $gate): void {
            $pdo->exec("UPDATE contact SET email_address = 'person@example.com' WHERE id = 'contact'");
            $gate->publish((object) ['enabled' => false], 'admin');
            $first = $this->emailStart($operations, 'email-independent');
            $this->deniedFields = ['Contact.emailAddress'];
            $execution->run('tenant', $first['operation']['id']);
            self::assertSame([], $this->emailCalls);
            $this->deniedFields = [];
            self::assertSame('0.0000', $operations->status(['tenantId' => 'tenant', 'contactId' => 'contact', 'requestKey' => 'email-independent'], Kind::EMAIL)['operation']['settledCredits']);
            $this->now = Operations::after($this->now, 61);
            $second = $this->emailStart($operations, 'email-provider-error');
            $this->emailResult = new EmailResult('failed');
            $execution->run('tenant', $second['operation']['id']);
            self::assertSame('40.0000', $pdo->query("SELECT balance FROM tenant_credit_balance WHERE tenant_id = 'tenant'")->fetchColumn());
            $pdo->exec("INSERT INTO contact_channel_identity (id, contact_id, tenant_id, channel_type, source_id, ownership_status)
                VALUES ('rejected-email', 'contact', 'tenant', 'email', 'PERSON@example.com', 'rejected')");
            $context = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact',
                'sources' => [['key' => 'email', 'kind' => 'contact_email']]], Kind::EMAIL);
            self::assertSame('ownership_rejected', $context['rows']['email']['reason']);
            for ($i = 0; $i < 5; $i++) {
                $pdo->exec("UPDATE contact SET email_address = 'person$i@example.com' WHERE id = 'contact'");
                $this->emailStart($operations, 'open-' . $i);
            }
            $gate->publish((object) ['enabled' => true], 'admin');
            $source = ['key' => 'phone', 'kind' => 'contact_phone'];
            $context = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source]]);
            try {
                $operations->start((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'source' => $source,
                    'requestKey' => 'phone-over-limit', 'contextRevision' => $context['rows']['phone']['contextRevision']]);
                self::fail('Email operations must count against the shared phone pilot allowance.');
            } catch (Conflict) {}
            self::assertSame('10.0000', $pdo->query("SELECT reserved_credits FROM tenant_credit_balance WHERE tenant_id = 'tenant'")->fetchColumn());
            $this->assertHealthyAccounting($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testEmailReplayKindIsolationPollingBudgetAndRetention(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Operations $operations, Execution $execution, CapabilityGate $gate, Funding $funding, Crypt $crypt): void {
            $pdo->exec("UPDATE contact SET email_address = 'person@example.com' WHERE id = 'contact'");
            $source = ['key' => 'email', 'kind' => 'contact_email'];
            $context = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source]], Kind::EMAIL);
            $command = (object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'source' => $source, 'requestKey' => 'bound-key',
                'contextRevision' => $context['rows']['email']['contextRevision']];
            $start = $operations->start($command, Kind::EMAIL);
            self::assertSame($start, $operations->start($command, Kind::EMAIL));
            try {
                $operations->status(['tenantId' => 'tenant', 'contactId' => 'contact', 'requestKey' => 'bound-key']);
                self::fail('Phone endpoint must not expose email receipts.');
            } catch (NotFound) {}
            $this->emailResult = new EmailResult('pending', 'task1');
            $execution->run('tenant', $start['operation']['id']);
            for ($i = 0; $i < 9; $i++) {
                $this->now = Operations::after($this->now, 30);
                $execution->run('tenant', $start['operation']['id']);
                $execution->run('tenant', $start['operation']['id']);
            }
            self::assertCount(10, $this->emailCalls); // One paid submit, nine free reads before the deadline.
            self::assertSame(9, (int) $pdo->query("SELECT poll_count FROM phone_verification_operation WHERE id = '{$start['operation']['id']}'")->fetchColumn());
            $this->now = Operations::after($this->now, 30);
            $execution->run('tenant', $start['operation']['id']);
            self::assertCount(10, $this->emailCalls);
            $this->emailResult = new EmailResult('completed', 'task2', 'deliverable', ['score' => 90, 'flags' => []]);
            $next = $this->emailStart($operations, 'retained');
            $execution->run('tenant', $next['operation']['id']);
            $this->now = Operations::after($this->now, CapabilityGate::FRESH_REUSE_SECONDS);
            $ctx = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source]], Kind::EMAIL);
            self::assertTrue($ctx['rows']['email']['currentResult']['stale']);
            $this->now = Operations::after($this->now, CapabilityGate::RETENTION_SECONDS);
            $execution->expireEvidence();
            $receipt = $operations->status(['tenantId' => 'tenant', 'contactId' => 'contact', 'operationId' => $next['operation']['id']], Kind::EMAIL);
            self::assertNull($receipt['operation']['canonicalEmail']);
            self::assertNull($receipt['operation']['emailEvidence']);
            self::assertSame('2.0000', $receipt['operation']['settledCredits']);
            self::assertSame($start['operation']['id'], $operations->start($command, Kind::EMAIL)['operation']['id']);
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM phone_verification_operation WHERE supplier_task_ciphertext IS NOT NULL')->fetchColumn());
            $this->assertHealthyAccounting($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testEmailHistoryReauthorizesChannelSourceWithoutExposingRevokedEvidence(string $dialect): void
    {
        $this->database($dialect, function (PDO $pdo, Operations $operations, Execution $execution): void {
            $pdo->exec("UPDATE contact SET email_address = 'person@example.com' WHERE id = 'contact'");
            $pdo->exec("INSERT INTO contact_channel_identity (id, contact_id, tenant_id, channel_type, source_id)
                VALUES ('emailchan', 'contact', 'tenant', 'email', 'person@example.com')");
            $start = $this->emailStart($operations, 'email-channel', ['key' => 'ch', 'kind' => 'channel', 'id' => 'emailchan']);
            $execution->run('tenant', $start['operation']['id']);
            $this->deniedEntities = ['emailchan'];
            $receipt = $operations->status(['tenantId' => 'tenant', 'contactId' => 'contact', 'operationId' => $start['operation']['id']], Kind::EMAIL);
            self::assertSame('2.0000', $receipt['operation']['settledCredits']);
            self::assertNull($receipt['operation']['canonicalEmail']);
            self::assertNull($receipt['operation']['emailEvidence']);
            self::assertNull($receipt['operation']['outcome']);
            $context = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact',
                'sources' => [['key' => 'email', 'kind' => 'contact_email']]], Kind::EMAIL);
            self::assertNull($context['rows']['email']['currentResult']);
            $this->assertHealthyAccounting($pdo);
        });
    }

    private function assertHealthyAccounting(PDO $pdo, string $tenantId = 'tenant'): void
    {
        $manager = $this->createStub(EntityManager::class);
        $manager->method('getPDO')->willReturn($pdo);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now);

        $audit = (new ConsistencyAudit($manager, $clock))->inspect($tenantId);
        self::assertSame([], $audit['findings'], json_encode($audit['findings']));
        self::assertTrue($audit['consistent']);

        $ops = (new OperationHistory($manager, $clock))->inspect(new OperationHistoryQuery($tenantId));
        $res = (new ReservationHistory($manager, $clock))->inspect(new ReservationHistoryQuery($tenantId));
        self::assertSame(count($ops['list']), count($res['list']));
    }

    #[DataProvider('dialects')]
    public function testPositiveNegativeAndInsufficientDataChecksEachDebitEightCreditsAndShareAcrossRows(string $dialect): void
    {
        $this->database($dialect, function (
            PDO $pdo,
            Operations $operations,
            Execution $execution,
            CapabilityGate $gate,
            Funding $funding,
            Crypt $crypt,
            EntityManager $manager,
            Acl $acl,
        ): void {
            // Seed purchased phone rows from Provider A and Provider B with formatting differences.
            $this->seedPurchasedPhones($pdo, $crypt, 'quoteA', 'attA', 'cpfcnpj', ['+5511987654321']);
            $this->seedPurchasedPhones($pdo, $crypt, 'quoteB', 'attB', 'hub', ['+5511987654321']);

            $sources = [
                ['key' => 'provA', 'kind' => 'purchased', 'receiptId' => 'attA', 'index' => 0],
                ['key' => 'provB', 'kind' => 'purchased', 'receiptId' => 'attB', 'index' => 0],
                ['key' => 'savedChan', 'kind' => 'channel', 'id' => 'chan1'],
                ['key' => 'contactPhone', 'kind' => 'contact_phone'],
            ];

            $ctx = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => $sources]);
            foreach (['provA', 'provB', 'savedChan', 'contactPhone'] as $k) {
                self::assertTrue($ctx['rows'][$k]['eligible']);
                self::assertSame('+5511987654321', $ctx['rows'][$k]['canonicalPhone']);
            }
            $revision = $ctx['rows']['provA']['contextRevision'];

            // Start from Provider A row; concurrent click from Provider B row joins the same operation and hold!
            $startA = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'source' => $sources[0],
                'requestKey' => 'req-prov-a',
                'contextRevision' => $revision,
            ]);
            self::assertSame('admitted', $startA['resolution']);
            self::assertSame('pending', $startA['operation']['state']);
            self::assertSame('8.0000', $startA['operation']['reservedCredits']);

            $joinB = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'source' => $sources[1],
                'requestKey' => 'req-prov-b',
                'contextRevision' => $revision,
            ]);
            self::assertSame('joined', $joinB['resolution']);
            self::assertSame($startA['operation']['id'], $joinB['operation']['id']);
            self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM credit_usage WHERE operation_type='verification'")->fetchColumn());

            // Replay of req-prov-a returns identical receipt; conflicting parameters with same requestKey fail.
            $replayA = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'source' => $sources[0],
                'requestKey' => 'req-prov-a',
                'contextRevision' => $revision,
            ]);
            self::assertSame($startA, $replayA);
            try {
                $operations->start((object) [
                    'tenantId' => 'tenant',
                    'contactId' => 'contact',
                    'source' => $sources[0],
                    'requestKey' => 'req-prov-a',
                    'contextRevision' => $revision,
                    'refresh' => true,
                ]);
                self::fail('Conflicting requestKey replay accepted.');
            } catch (Conflict) {
                $this->addToAssertionCount(1);
            }

            // Generic pre-request release path cannot bypass verification lifecycle checks.
            $clock = $this->createStub(Clock::class);
            $clock->method('now')->willReturnCallback(fn () => $this->now);
            $lock = new WalletLock($manager, $clock);
            try {
                (new Reservations($lock, $funding))->release(
                    'tenant',
                    $startA['operation']['usageId'],
                    'verification:' . $startA['operation']['id'],
                );
                self::fail('Generic pre-request release bypassed verification lifecycle.');
            } catch (Conflict) {
                $this->addToAssertionCount(1);
            }

            // Execute background job: dispatches once, settles 8.0000. Redelivery does not dispatch again.
            $this->now = '2026-10-14 12:00:05';
            $execution->run('tenant', $startA['operation']['id']);
            $execution->run('tenant', $startA['operation']['id']);
            self::assertSame(1, $this->providerCalls);

            // All 4 rows (Provider A, Provider B, saved WhatsApp channel, and Contact.phoneNumber) share the verified result!
            $afterCtx = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => $sources]);
            foreach (['provA', 'provB', 'savedChan', 'contactPhone'] as $k) {
                self::assertSame('verified', $afterCtx['rows'][$k]['currentResult']['outcome']);
                self::assertSame(['firstName'], $afterCtx['rows'][$k]['currentResult']['matchedFields']);
                self::assertTrue($afterCtx['rows'][$k]['currentResult']['fresh']);
                self::assertFalse($afterCtx['rows'][$k]['currentResult']['stale']);
                self::assertSame('8.0000', $afterCtx['rows'][$k]['currentResult']['settledCredits']);
            }

            // Free reuse within 30 days from saved channel row does not charge or dispatch.
            $reuseSaved = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'source' => $sources[2],
                'requestKey' => 'req-reuse-saved',
                'contextRevision' => $afterCtx['rows']['savedChan']['contextRevision'],
            ]);
            self::assertSame('reused', $reuseSaved['resolution']);
            self::assertSame($startA['operation']['id'], $reuseSaved['operation']['id']);
            self::assertSame(1, $this->providerCalls);

            $balance = (new BalanceStatus($manager, $clock))->inspect('tenant');
            self::assertSame('32.0000', $balance['balance']);
            self::assertSame('0.0000', $balance['reservedCredits']);

            // Contact source attribution works through OperationSource.
            $sourceAttribution = (new OperationSource($manager, $acl))->inspect('tenant', $startA['operation']['usageId']);
            self::assertSame(['scope' => 'Contact', 'id' => 'contact', 'name' => 'Maria Silva'], $sourceAttribution['source']);

            // Explicit paid refresh after cooldown (61s later) with negative outcome ('not_verified') also charges 8.0000 and replaces current result!
            $this->now = '2026-10-14 12:01:05';
            $this->nextResult = VerificationResult::completed(
                'not_verified',
                [],
                ['firstName' => 'no_match', 'lastName' => 'partial_match', 'dateOfBirth' => 'not_checked'],
            );
            $refreshNegative = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'source' => $sources[2],
                'requestKey' => 'req-refresh-neg',
                'contextRevision' => $afterCtx['rows']['savedChan']['contextRevision'],
                'refresh' => true,
            ]);
            self::assertSame('admitted', $refreshNegative['resolution']);

            // During refresh, all matching rows show shared pending state AND earlier dated evidence!
            $duringRefreshCtx = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => $sources]);
            self::assertSame('pending', $duringRefreshCtx['rows']['provA']['activeOperation']['state']);
            self::assertSame('verified', $duringRefreshCtx['rows']['provA']['currentResult']['outcome']);
            self::assertSame('2026-10-14 12:00:05', $duringRefreshCtx['rows']['provA']['currentResult']['completedAt']);

            $this->now = '2026-10-14 12:01:08';
            $execution->run('tenant', $refreshNegative['operation']['id']);
            self::assertSame(2, $this->providerCalls);

            $negCtx = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => $sources]);
            self::assertSame('not_verified', $negCtx['rows']['provA']['currentResult']['outcome']);
            self::assertSame('24.0000', (new BalanceStatus($manager, $clock))->inspect('tenant')['balance']);

            // Explicit paid refresh with insufficient_data also charges 8.0000; failed refresh afterwards costs 0 and preserves previous evidence!
            $this->now = '2026-10-14 12:02:10';
            $this->nextResult = VerificationResult::completed(
                'insufficient_data',
                [],
                ['firstName' => 'not_available', 'lastName' => 'not_available', 'dateOfBirth' => 'not_checked'],
            );
            $refreshInsuff = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'source' => $sources[0],
                'requestKey' => 'req-refresh-insuff',
                'contextRevision' => $negCtx['rows']['provA']['contextRevision'],
                'refresh' => true,
            ]);
            $this->now = '2026-10-14 12:02:12';
            $execution->run('tenant', $refreshInsuff['operation']['id']);
            self::assertSame('16.0000', (new BalanceStatus($manager, $clock))->inspect('tenant')['balance']);

            // Failed refresh does NOT overwrite previous completed evidence or charge!
            $this->now = '2026-10-14 12:03:20';
            $this->nextResult = VerificationResult::failed('http_503');
            $refreshFailed = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'source' => $sources[0],
                'requestKey' => 'req-refresh-fail',
                'contextRevision' => $negCtx['rows']['provA']['contextRevision'],
                'refresh' => true,
            ]);
            $this->now = '2026-10-14 12:03:22';
            $execution->run('tenant', $refreshFailed['operation']['id']);
            self::assertSame('16.0000', (new BalanceStatus($manager, $clock))->inspect('tenant')['balance']);

            $postFailCtx = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => $sources]);
            self::assertSame('insufficient_data', $postFailCtx['rows']['provA']['currentResult']['outcome']);
            self::assertSame('2026-10-14 12:02:12', $postFailCtx['rows']['provA']['currentResult']['completedAt']);
            self::assertSame('failed', $postFailCtx['rows']['provA']['lastFailedAttempt']['outcome']);

            $this->assertHealthyAccounting($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testDobSaveFirstOptionalityInsufficientFundsAndSaveFailure(string $dialect): void
    {
        $this->database($dialect, function (
            PDO $pdo,
            Operations $operations,
            Execution $execution,
            CapabilityGate $gate,
            Funding $funding,
            Crypt $crypt,
            EntityManager $manager,
        ): void {
            // Drain wallet to 4.0000 credits (< 8.0000) to test D7: DOB save succeeds and remains when funds are insufficient!
            $pdo->exec("UPDATE tenant_credit_balance SET balance = 4.0000 WHERE tenant_id = 'tenant'");
            $pdo->exec("UPDATE credit_grant SET granted_credits = 4.0000, remaining_credits = 4.0000 WHERE tenant_id = 'tenant'");
            $pdo->exec("UPDATE credit_transaction SET credits = 4.0000 WHERE tenant_id = 'tenant'");

            $source = ['key' => 'chan1', 'kind' => 'channel', 'id' => 'chan1'];
            $ctx = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source]]);
            self::assertFalse($ctx['contact']['hasDateOfBirth']);

            try {
                $operations->start((object) [
                    'tenantId' => 'tenant',
                    'contactId' => 'contact',
                    'source' => $source,
                    'requestKey' => 'req-dob-nofunds',
                    'contextRevision' => $ctx['rows']['chan1']['contextRevision'],
                    'dateOfBirth' => '1990-05-15',
                ]);
                self::fail('Admitted with insufficient credits.');
            } catch (Conflict $e) {
                self::assertStringContainsString('insufficient_credits', (string) $e->getBody());
            }

            // DOB remains saved on Contact even though verification failed for insufficient credits (D7)!
            self::assertSame('1990-05-15', $pdo->query("SELECT date_of_birth FROM contact WHERE id = 'contact'")->fetchColumn());
            self::assertSame(0, $this->providerCalls);

            // If actor lacks dateOfBirth edit permission, saving a new DOB fails before admission/dispatch!
            $this->deniedWrites = ['Contact.dateOfBirth'];
            $ctxWithDob = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source]]);
            self::assertFalse($ctxWithDob['contact']['dateOfBirthEditable']);
            try {
                $operations->start((object) [
                    'tenantId' => 'tenant',
                    'contactId' => 'contact',
                    'source' => $source,
                    'requestKey' => 'req-dob-forbidden',
                    'contextRevision' => $ctxWithDob['rows']['chan1']['contextRevision'],
                    'dateOfBirth' => '1991-06-20',
                ]);
                self::fail('Allowed DOB write without field edit permission.');
            } catch (Forbidden) {
                $this->addToAssertionCount(1);
            }
            self::assertSame('1990-05-15', $pdo->query("SELECT date_of_birth FROM contact WHERE id = 'contact'")->fetchColumn());
        });
    }

    #[DataProvider('dialects')]
    public function testInputChangesBeforeAndAfterDispatchThirtyAndNinetyDayBoundariesAndContactErasure(string $dialect): void
    {
        $this->database($dialect, function (
            PDO $pdo,
            Operations $operations,
            Execution $execution,
            CapabilityGate $gate,
            Funding $funding,
            Crypt $crypt,
            EntityManager $manager,
        ): void {
            $source = ['key' => 'chan1', 'kind' => 'channel', 'id' => 'chan1'];
            $ctx = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source]]);

            // 1. Input changes BEFORE dispatch cancel and release at 0 charge (D19).
            $preEditOp = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'source' => $source,
                'requestKey' => 'req-pre-edit',
                'contextRevision' => $ctx['rows']['chan1']['contextRevision'],
            ]);
            $pdo->exec("UPDATE contact SET first_name = 'Mariana', name = 'Mariana Silva' WHERE id = 'contact'");
            $execution->run('tenant', $preEditOp['operation']['id']);
            self::assertSame(0, $this->providerCalls);
            $statusPreEdit = $operations->status([
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'operationId' => $preEditOp['operation']['id'],
            ]);
            self::assertSame('failed', $statusPreEdit['operation']['state']);
            self::assertSame('0.0000', $statusPreEdit['operation']['settledCredits']);

            // 2. Input changes AFTER dispatch claim complete the original snapshot and charge 8.0000,
            //    retaining historical evidence but NOT showing a current badge on the changed inputs (D19).
            $this->now = '2026-10-14 12:02:00';
            $ctxMariana = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source]]);
            $postEditOp = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'source' => $source,
                'requestKey' => 'req-post-edit',
                'contextRevision' => $ctxMariana['rows']['chan1']['contextRevision'],
            ]);
            $claim = $execution->claim('tenant', $postEditOp['operation']['id']);
            self::assertNotNull($claim);

            // Edit Contact name after dispatch claim was committed.
            $pdo->exec("UPDATE contact SET first_name = 'Beatriz', name = 'Beatriz Silva' WHERE id = 'contact'");
            $execution->record('tenant', $postEditOp['operation']['id'], $claim['token'], VerificationResult::completed(
                'verified',
                ['firstName'],
                ['firstName' => 'exact_match', 'lastName' => 'exact_match', 'dateOfBirth' => 'not_checked'],
            ));

            $statusPostEdit = $operations->status([
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'operationId' => $postEditOp['operation']['id'],
            ]);
            self::assertSame('completed', $statusPostEdit['operation']['state']);
            self::assertSame('8.0000', $statusPostEdit['operation']['settledCredits']);
            self::assertTrue($statusPostEdit['operation']['identityChanged']);

            // Current row context on 'Beatriz Silva' does NOT show Mariana's badge or reuse Mariana's result!
            $ctxBeatriz = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source]]);
            self::assertNull($ctxBeatriz['rows']['chan1']['currentResult']);

            // Restore name to Mariana Silva -> badge is active and fresh!
            $pdo->exec("UPDATE contact SET first_name = 'Mariana', name = 'Mariana Silva' WHERE id = 'contact'");
            $ctxRestored = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source]]);
            self::assertSame('verified', $ctxRestored['rows']['chan1']['currentResult']['outcome']);
            self::assertTrue($ctxRestored['rows']['chan1']['currentResult']['fresh']);

            // 3. At 30 days + 1 second, evidence is stale and requires confirmed paid refresh (D13).
            $this->now = '2026-11-13 12:02:01';
            $ctxStale = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source]]);
            self::assertFalse($ctxStale['rows']['chan1']['currentResult']['fresh']);
            self::assertTrue($ctxStale['rows']['chan1']['currentResult']['stale']);
            self::assertTrue($ctxStale['rows']['chan1']['actions']['requiresPaidRefresh']);

            // 4. At 90 days + 1 second, detailed evidence is inaccessible immediately and erased by cleanup;
            //    minimal financial history and request replay remain intact (D16, D25).
            $this->now = '2027-01-12 12:02:01';
            $ctxExpired = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source]]);
            self::assertNull($ctxExpired['rows']['chan1']['currentResult']);

            $execution->expireEvidence();
            $historyAfter90d = $operations->history(['tenantId' => 'tenant', 'contactId' => 'contact']);
            self::assertCount(2, $historyAfter90d['list']);
            self::assertTrue($historyAfter90d['list'][0]['erased']);
            self::assertNull($historyAfter90d['list'][0]['canonicalPhone']);
            self::assertNull($historyAfter90d['list'][0]['fieldOutcomes']);
            self::assertSame('8.0000', $historyAfter90d['list'][0]['settledCredits']);

            // Request replay still returns the original operation receipt without charging again!
            $replayAfterErase = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'source' => $source,
                'requestKey' => 'req-post-edit',
                'contextRevision' => $ctxMariana['rows']['chan1']['contextRevision'],
            ]);
            self::assertSame($postEditOp['operation']['id'], $replayAfterErase['operation']['id']);

            // 5. Contact erasure revokes pending delivery, waives unsettled charge, and scrubs ciphertext while keeping settled accounting intact (D16, D24).
            $this->now = '2027-01-12 12:05:00';
            $openBeforeErase = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'source' => $source,
                'requestKey' => 'req-before-erase',
                'contextRevision' => $ctxExpired['rows']['chan1']['contextRevision'],
            ]);
            $clock = $this->createStub(Clock::class);
            $clock->method('now')->willReturnCallback(fn () => $this->now);
            $erasure = new PurchasedErasure($manager, $crypt, new FixedSettlement($funding), $clock);
            $contactEntity = $manager->getEntityById('Contact', 'contact');

            $pdo->beginTransaction();
            $erasure->erase($contactEntity);
            $pdo->commit();

            $erasedRow = $pdo->query("SELECT * FROM phone_verification_operation WHERE id = '" . $openBeforeErase['operation']['id'] . "'")->fetch(PDO::FETCH_ASSOC);
            self::assertSame('waived', $erasedRow['state']);
            self::assertSame('cancelled', $erasedRow['outcome']);
            self::assertNull($erasedRow['identity_ciphertext']);
            self::assertNull($erasedRow['evidence_ciphertext']);
            self::assertNotNull($erasedRow['erased_at']);

            $this->assertHealthyAccounting($pdo);
        });
    }

    #[DataProvider('dialects')]
    public function testOwnershipRejectionContactIsolationCrashRecoveryGrantExpiryAndPilotLimits(string $dialect): void
    {
        $this->database($dialect, function (
            PDO $pdo,
            Operations $operations,
            Execution $execution,
            CapabilityGate $gate,
            Funding $funding,
            Crypt $crypt,
            EntityManager $manager,
        ): void {
            $source1 = ['key' => 'chan1', 'kind' => 'channel', 'id' => 'chan1'];
            $source2 = ['key' => 'contactPhone', 'kind' => 'contact_phone'];

            // 1. Same phone on another Contact ('contact2') does NOT reuse 'contact' evidence and bills separately (D13, D17).
            $ctx1 = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source1]]);
            $op1 = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact',
                'source' => $source1,
                'requestKey' => 'req-c1',
                'contextRevision' => $ctx1['rows']['chan1']['contextRevision'],
            ]);
            $execution->run('tenant', $op1['operation']['id']);

            $ctx2 = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact2', 'sources' => [$source2]]);
            self::assertNull($ctx2['rows']['contactPhone']['currentResult']);

            // 2. Ownership rejection blocks new checks and remains dominant over verified evidence (D21).
            $pdo->exec("UPDATE contact_channel_identity SET ownership_status = 'rejected' WHERE id = 'chan1'");
            $ctxRejected = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact', 'sources' => [$source1, $source2]]);
            self::assertFalse($ctxRejected['rows']['chan1']['eligible']);
            self::assertSame('rejected', $ctxRejected['rows']['chan1']['ownershipStatus']);
            self::assertNull($ctxRejected['rows']['chan1']['currentResult']);
            self::assertFalse($ctxRejected['rows']['contactPhone']['eligible']);
            self::assertSame('rejected', $ctxRejected['rows']['contactPhone']['ownershipStatus']);

            try {
                $operations->start((object) [
                    'tenantId' => 'tenant',
                    'contactId' => 'contact',
                    'source' => $source1,
                    'requestKey' => 'req-rejected',
                    'contextRevision' => $ctx1['rows']['chan1']['contextRevision'],
                ]);
                self::fail('Allowed verification on ownership-rejected phone.');
            } catch (Conflict $e) {
                self::assertStringContainsString('ownership_rejected', (string) $e->getBody());
            }

            // 3. Crash after dispatch claim + grant expiration while held + late provider response (D11, D12).
            // Grant expiring funds to 'other' tenant, claim an operation, let grant and deadline expire, then recover!
            $funding->grant(new GrantInput(
                'other',
                'subscription',
                'expiring-grant',
                '8.0000',
                '2026-10-14 00:00:00',
                '2026-10-14 12:03:00',
                (object) ['source' => 'fixture'],
            ));
            $ctxForeign = $operations->context((object) ['tenantId' => 'other', 'contactId' => 'foreign', 'sources' => [$source2]]);
            $opCrash = $operations->start((object) [
                'tenantId' => 'other',
                'contactId' => 'foreign',
                'source' => $source2,
                'requestKey' => 'req-crash',
                'contextRevision' => $ctxForeign['rows']['contactPhone']['contextRevision'],
            ]);
            $claimCrash = $execution->claim('other', $opCrash['operation']['id']);
            self::assertNotNull($claimCrash);
            // Second claim attempt while claimed returns null (never resends!).
            self::assertNull($execution->claim('other', $opCrash['operation']['id']));

            // Advance past both grant expiry (12:03:00) and operation deadline (12:05:00).
            $this->now = '2026-10-14 12:05:01';
            $execution->recover('other', $opCrash['operation']['id']);

            // Late provider response after closure is ignored and never reopens or charges!
            $execution->record('other', $opCrash['operation']['id'], $claimCrash['token'], VerificationResult::completed(
                'verified',
                ['firstName'],
                ['firstName' => 'exact_match', 'lastName' => 'exact_match', 'dateOfBirth' => 'not_checked'],
            ));
            $statusCrash = $operations->status([
                'tenantId' => 'other',
                'contactId' => 'foreign',
                'operationId' => $opCrash['operation']['id'],
            ]);
            self::assertSame('failed', $statusCrash['operation']['state']);
            self::assertSame('0.0000', $statusCrash['operation']['settledCredits']);

            $clock = $this->createStub(Clock::class);
            $clock->method('now')->willReturnCallback(fn () => $this->now);
            $otherBalance = (new BalanceStatus($manager, $clock))->inspect('other');
            // Expired held grant was expired on late release, not resurrected!
            self::assertSame('0.0000', $otherBalance['balance']);
            self::assertSame('0.0000', $otherBalance['reservedCredits']);
            $this->assertHealthyAccounting($pdo, 'other');

            // 4. Pilot rate limits and same-input cooldown (D20, D25).
            $gate->publish((object) ['maxOutstandingPerTenant' => 1], 'admin');
            $opLimit1 = $operations->start((object) [
                'tenantId' => 'tenant',
                'contactId' => 'contact2',
                'source' => $source2,
                'requestKey' => 'req-limit-1',
                'contextRevision' => $ctx2['rows']['contactPhone']['contextRevision'],
            ]);
            self::assertSame('admitted', $opLimit1['resolution']);

            // Same-input within 60s joins the active operation; different phone while 1 is outstanding hits maxOutstandingPerTenant!
            $pdo->exec("UPDATE contact SET phone_number = '+5511977776666' WHERE id = 'contact2'");
            $ctx2DiffPhone = $operations->context((object) ['tenantId' => 'tenant', 'contactId' => 'contact2', 'sources' => [$source2]]);
            try {
                $operations->start((object) [
                    'tenantId' => 'tenant',
                    'contactId' => 'contact2',
                    'source' => $source2,
                    'requestKey' => 'req-limit-2',
                    'contextRevision' => $ctx2DiffPhone['rows']['contactPhone']['contextRevision'],
                ]);
                self::fail('Exceeded maxOutstandingPerTenant limit.');
            } catch (Conflict $e) {
                self::assertStringContainsString('rate_limited', (string) $e->getBody());
            }

            // Controller rejects forged price or result fields and sets private, no-store header.
            $controller = new CreditPhoneVerification($operations);
            $badReq = $this->createStub(Request::class);
            $badReq->method('getBodyContents')->willReturn(json_encode([
                'tenantId' => 'tenant',
                'contactId' => 'contact2',
                'source' => $source2,
                'requestKey' => 'req-forged',
                'contextRevision' => $ctx2DiffPhone['rows']['contactPhone']['contextRevision'],
                'priceCredits' => '0.0001',
            ]));
            try {
                $controller->postActionStart($badReq, $this->createStub(Response::class));
                self::fail('Accepted caller-supplied price.');
            } catch (BadRequest) {
                $this->addToAssertionCount(1);
            }
        });
    }
}
