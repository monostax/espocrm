<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Helper;
use Espo\Core\Select\Text\MetadataProvider as TextMetadataProvider;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\SystemUser;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Rebuild\BackfillOpportunityReadStates;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use tests\unit\Espo\Modules\Chatwoot\Support\EntityDouble;

require_once __DIR__ . '/../Support/EntityDouble.php';

class BackfillOpportunityReadStatesTest extends TestCase
{
    private const FLAG = 'opportunityReadStatesBackfilledAt';
    private const CUTOFF = 'opportunityReadStatesBackfillCutoff';

    private EntityManager $entityManager;
    private Config $config;
    private ConfigWriter $configWriter;
    private AclManager $aclManager;
    private UserTenantResolver $tenantResolver;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->config = $this->createMock(Config::class);
        $this->configWriter = $this->createMock(ConfigWriter::class);
        $this->aclManager = $this->createMock(AclManager::class);
        $this->tenantResolver = $this->createMock(UserTenantResolver::class);
    }

    /** Use the real DI factory: eager User/Acl injection must fail like the rebuild CLI. */
    private function createAction(?User $currentUser = null): BackfillOpportunityReadStates
    {
        $container = $this->createMock(Container::class);
        $factory = new InjectableFactory($container);
        $services = [
            'container' => $container,
            'entityManager' => $this->entityManager,
            'injectableFactory' => $factory,
            'config' => $this->config,
            'configWriter' => $this->configWriter,
            'aclManager' => $this->aclManager,
            'tenantResolver' => $this->tenantResolver,
            'textMetadataProvider' => $this->createMock(TextMetadataProvider::class),
        ];
        if ($currentUser) {
            $services['user'] = $currentUser;
            $services['acl'] = new Acl($this->aclManager, $currentUser);
        }
        $userServices = ['user' => User::class, 'acl' => Acl::class];
        $container->method('has')->willReturnCallback(
            fn (string $id) => isset($services[$id]) || isset($userServices[$id]),
        );
        $container->method('getClass')->willReturnCallback(
            fn (string $id) => new ReflectionClass($userServices[$id] ?? $services[$id]),
        );
        $container->method('get')->willReturnCallback(function (string $id) use ($services): object {
            if (!isset($services[$id])) {
                throw new RuntimeException("Could not load '$id' service.");
            }
            return $services[$id];
        });
        // A rebuild must not replace the caller or register a global privileged user.
        $container->expects($this->never())->method('set');

        return $factory->create(BackfillOpportunityReadStates::class);
    }

    public function testCompletedBackfillCanBeConstructedAndSkippedWithoutAUser(): void
    {
        $this->config->expects($this->once())->method('get')->with(self::FLAG)->willReturn('2026-09-05 00:00:00');
        $this->entityManager->expects($this->never())->method('getRDBRepositoryByClass');
        $this->entityManager->expects($this->never())->method('getPDO');
        $this->configWriter->expects($this->never())->method('save');

        $this->createAction()->process();
    }

    public function testMissingSystemUserFailsWithoutMarkingTheBackfillComplete(): void
    {
        $this->systemUserRepository(null);
        $this->entityManager->expects($this->never())->method('getPDO');
        $this->configWriter->expects($this->never())->method('set');
        $this->configWriter->expects($this->never())->method('save');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('System user is not found.');
        $this->createAction()->process();
    }

    public static function callerContexts(): array
    {
        return [
            'CLI: historical unread participant' => [false, 7, true, false],
            'authenticated caller: historical non-participant' => [true, 7, false, false],
            'retry uses the original cutoff' => [false, 7, true, true],
            'newer read cutoff is preserved' => [false, 99, true, true],
            'first personal state starts with history read' => [false, 0, false, false, true],
            'viewer history is read without enrolling them' => [false, 7, false, false, false, false],
        ];
    }

    #[DataProvider('callerContexts')]
    public function testBackfillUsesScopedSystemContextAndInitializesHistoryAsRead(
        bool $hasCaller,
        int $existingNumber,
        bool $isParticipant,
        bool $isRetry,
        bool $isNew = false,
        bool $eligibleParticipant = true,
    ): void
    {
        $systemUser = $this->user('system-id', User::TYPE_SYSTEM);
        $participant = $this->user('participant');
        $denied = $this->user('denied');
        $outsider = $this->user('outsider');
        $this->systemUserRepository($systemUser);
        $cutoff = $isRetry ? ['timestamp' => '2026-09-05 12:00:00', 'number' => 42] : null;
        $this->config->method('get')->willReturnCallback(
            fn ($key) => $key === self::CUTOFF ? $cutoff : null,
        );

        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $this->entityManager->method('getPDO')->willReturn($pdo);
        $transactions = $this->createMock(TransactionManager::class);
        $transactions->expects($this->once())->method('run')->willReturnCallback(fn ($callback) => $callback());
        $this->entityManager->method('getTransactionManager')->willReturn($transactions);

        $opportunity = new EntityDouble([
            'id' => 'opportunity', 'tenantId' => 'tenant',
            'assignedUserId' => $eligibleParticipant ? 'participant' : null,
        ], 'Opportunity');
        $post = new Note('Note', ['attributes' => [
            'id' => ['type' => 'varchar'], 'parentId' => ['type' => 'varchar'],
            'post' => ['type' => 'text'], 'data' => ['type' => 'jsonObject'],
            'number' => ['type' => 'int'], 'createdById' => ['type' => 'varchar'],
            'opportunityMentionUserIds' => ['type' => 'jsonArray'],
        ]]);
        $post->set([
            'id' => 'post', 'parentId' => 'opportunity', 'number' => 42,
            'post' => '@participant @denied @outsider',
            'data' => (object) ['mentions' => (object) [
                '@participant' => (object) ['_scope' => 'User', 'id' => 'participant'],
                '@denied' => (object) ['_scope' => 'User', 'id' => 'denied'],
                '@outsider' => (object) ['_scope' => 'User', 'id' => 'outsider'],
            ]],
        ]);
        if (!$eligibleParticipant) {
            $post->set('post', 'Historical post without mentions.');
            $post->set('data', (object) []);
        }
        $state = new EntityDouble([
            'opportunityId' => 'opportunity', 'userId' => 'participant',
            'isParticipant' => $isParticipant, 'lastSeenAt' => '2026-09-01 12:00:00',
            'lastSeenNumber' => $existingNumber, 'version' => 3,
        ], 'OpportunityReadState');
        if ($isNew) {
            $state = new BaseEntity('OpportunityReadState', ['attributes' => [
                'opportunityId' => ['type' => 'varchar'], 'userId' => ['type' => 'varchar'],
                'isParticipant' => ['type' => 'bool'], 'lastSeenAt' => ['type' => 'datetime'],
                'lastSeenNumber' => ['type' => 'int'], 'version' => ['type' => 'int'],
            ]]);
            $this->entityManager->expects($this->once())->method('getNewEntity')
                ->with('OpportunityReadState')->willReturn($state);
        }

        $opportunities = $this->select([$opportunity], $opportunity);
        $notes = $this->select([$post]);
        $states = $this->select($isNew ? [] : [$state], $isNew ? null : $state);
        $subscriptions = $this->select([]);
        $mentionedUsers = $this->select([$participant, $denied, $outsider]);
        $participants = $this->select([$participant]);
        $repositories = [];
        foreach ([
            'Opportunity' => $opportunities, 'Note' => $notes,
            'OpportunityReadState' => $states, 'StreamSubscription' => $subscriptions,
        ] as $type => $select) {
            $repositories[$type] = $this->createMock(RDBRepository::class);
            $repositories[$type]->method('where')->willReturn($select);
        }
        $repositories['Note']->expects($isRetry ? $this->never() : $this->once())
            ->method('max')->with('number')->willReturn(42);
        $repositories['User'] = $this->createMock(RDBRepository::class);
        $repositories['User']->method('where')->willReturnCallback(
            fn (array $where) => isset($where['isActive']) ? $mentionedUsers : $participants,
        );
        $this->entityManager->method('getRDBRepository')->willReturnCallback(fn ($type) => $repositories[$type]);
        $this->entityManager->method('getEntityById')->with('Opportunity', 'opportunity')->willReturn($opportunity);

        // Real Acl and both real backfill helpers are constructed by InjectableFactory.
        $this->aclManager->expects($this->exactly($eligibleParticipant ? 3 : 0))->method('checkUserPermission')
            ->willReturnCallback(function (User $actor, User $target, string $permission) use ($systemUser): bool {
                $this->assertSame($systemUser, $actor);
                $this->assertSame('mention', $permission);
                return $target->getId() !== 'denied';
            });
        $this->aclManager->method('checkEntityRead')->willReturn(true);
        $this->aclManager->method('checkEntityStream')->willReturn(true);
        $this->tenantResolver->method('canActForTenant')->willReturnCallback(
            fn (User $user, string $tenantId) => $user->getId() === 'participant' && $tenantId === 'tenant',
        );
        $stateChanges = ($eligibleParticipant && !$isParticipant) || $existingNumber < 42;
        $this->entityManager->expects($this->exactly($stateChanges ? 2 : 1))->method('saveEntity')
            ->willReturnCallback(function (Entity $entity, array $options = []) use ($post, $state, $eligibleParticipant, &$cutoff): void {
                $this->assertNotNull($cutoff, 'Persist the read boundary before processing personal state.');
                if ($entity === $post) {
                    $this->assertSame(['skipAll' => true], $options);
                    $this->assertSame($eligibleParticipant ? ['participant'] : [], $post->get('opportunityMentionUserIds'));
                    return;
                }
                $this->assertSame($state, $entity);
            });
        $this->configWriter->expects($this->exactly($isRetry ? 1 : 2))->method('set')
            ->willReturnCallback(function ($key, $value) use (&$cutoff): void {
                if ($key === self::CUTOFF) {
                    $this->assertNull($cutoff);
                    $this->assertSame(42, $value['number']);
                    $cutoff = $value;
                    return;
                }
                $this->assertSame(self::FLAG, $key);
                $this->assertSame($cutoff['timestamp'], $value);
            });
        $this->configWriter->expects($this->exactly($isRetry ? 1 : 2))->method('save');

        $this->createAction($hasCaller ? $this->user('caller') : null)->process();

        $this->assertSame($eligibleParticipant || $isParticipant, $state->get('isParticipant'));
        $this->assertSame($existingNumber < 42 ? $cutoff['timestamp'] : '2026-09-01 12:00:00', $state->get('lastSeenAt'));
        $this->assertSame(max(42, $existingNumber), $state->get('lastSeenNumber'));
        $this->assertSame($isNew ? 1 : ($stateChanges ? 4 : 3), $state->get('version'));
    }

    private function user(string $id, string $type = User::TYPE_REGULAR): User
    {
        $user = new User('User', ['attributes' => [
            'id' => ['type' => 'varchar'], 'type' => ['type' => 'varchar'],
        ]], $this->entityManager, $this->createMock(Helper::class));
        $user->set(['id' => $id, 'type' => $type]);
        return $user;
    }

    private function systemUserRepository(?User $user): void
    {
        $repository = $this->createMock(RDBRepository::class);
        $repository->expects($this->once())->method('where')
            ->with(['userName' => SystemUser::NAME])->willReturn($this->select([], $user));
        $this->entityManager->method('getRDBRepositoryByClass')->with(User::class)->willReturn($repository);
    }

    private function select(array $entities, ?Entity $one = null): RDBSelectBuilder
    {
        $select = $this->createMock(RDBSelectBuilder::class);
        foreach (['order', 'limit', 'forUpdate'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('find')->willReturn(new EntityCollection($entities));
        $select->method('findOne')->willReturn($one);
        return $select;
    }
}
