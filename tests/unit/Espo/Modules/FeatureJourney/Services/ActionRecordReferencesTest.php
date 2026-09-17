<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\FeatureJourney\Services\ActionConditionEvaluator;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\ActionRecordReferences;
use Espo\Modules\FeatureJourney\Services\ActionRunner;
use Espo\Modules\FeatureJourney\Services\JourneyRateLimiter;
use Espo\Modules\FeatureJourney\Services\RestrictedFormulaRunner;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\QueryComposer\QueryComposer;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PDO;
use PHPUnit\Framework\TestCase;

class ActionRecordReferencesTest extends TestCase
{
    private EntityManager $em;
    private TenantGuard $guard;
    private Metadata $metadata;
    private Acl $acl;
    private PDO $pdo;
    private ActionRecordReferences $references;
    private ActionRunner $runner;
    private Entity $journey;
    private Entity $account;
    private User $actor;
    /** @var array<string, Entity> */
    private array $stored = [];
    private int $sequence = 0;
    private bool $failReferenceSave = false;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManager::class);
        $this->guard = $this->createMock(TenantGuard::class);
        $this->guard->method('requireTenantId')->willReturn('tenant-1');
        $this->guard->method('assertTenantScope')->willReturn('tenant-1');
        $this->guard->method('loadEntityInTenant')->willReturnCallback(function ($type, $id, $tenantId) {
            $entity = $this->stored[$id] ?? null;
            if (!$entity || $entity->getEntityType() !== $type || $entity->get('tenantId') !== $tenantId) {
                throw new Error('Missing or foreign-tenant reference.');
            }
            return clone $entity;
        });
        $this->guard->method('stampNewEntity')->willReturnCallback(
            static fn (Entity $entity, string $tenantId) => $entity->set('tenantId', $tenantId),
        );
        $this->guard->method('filterMutationFields')->willReturnCallback(static fn ($type, $fields) => $fields);
        $this->guard->method('filterTargetUpdateFields')->willReturnCallback(static fn ($type, $fields) => $fields);
        $this->guard->method('applyTargetUpdateFields')->willReturnCallback(static function ($entity, $fields) {
            $entity->set($fields);
            return array_keys($fields);
        });
        $this->guard->method('resolveLinkForeignEntityType')->willReturn('Opportunity');

        $types = json_decode(file_get_contents(
            'custom/Espo/Modules/FeatureJourney/Resources/metadata/app/journeyActionTypes.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        $meta = ['app' => ['journeyActionTypes' => $types], 'entityDefs' => [
            'Task' => ['fields' => ['parent' => ['entityList' => ['Account', 'Opportunity', 'Contact', 'Lead']]]],
            'Account' => ['links' => ['opportunities' => ['entity' => 'Opportunity']]],
        ]];
        $this->metadata = $this->createMock(Metadata::class);
        $this->metadata->method('get')->willReturnCallback(static function ($path) use ($meta) {
            $value = $meta;
            foreach ($path as $key) {
                $value = $value[$key] ?? null;
            }
            return $value;
        });
        $this->acl = $this->createMock(Acl::class);
        $manager = $this->createMock(AclManager::class);
        $manager->method('createUserAcl')->willReturn($this->acl);
        $this->pdo = $this->createMock(PDO::class);
        $this->em->method('getTransactionManager')->willReturn(new TransactionManager(
            $this->pdo, $this->createMock(QueryComposer::class),
        ));
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('forUpdate')->willReturnSelf();
        $builder->method('findOne')->willReturnCallback(fn () => clone $this->stored['enrollment-1']);
        $repo = $this->createMock(RDBRepository::class);
        $repo->method('where')->willReturn($builder);
        $this->em->method('getRDBRepository')->willReturn($repo);
        $this->em->method('getNewEntity')->willReturnCallback(fn ($type) => $this->entity($type));
        $this->em->method('saveEntity')->willReturnCallback(function (Entity $entity) {
            if ($entity->getEntityType() === 'JourneyRecord' && $this->failReferenceSave) {
                $this->failReferenceSave = false;
                throw new Error('Simulated reference save failure.');
            }
            if (!$entity->hasId()) {
                $entity->set('id', 'created-' . ++$this->sequence);
            }
            $this->stored[$entity->getId()] = clone $entity;
        });
        $this->references = new ActionRecordReferences($this->em, $this->guard, $manager, $this->metadata);
        $this->journey = $this->entity('Journey', ['id' => 'journey-1', 'targetEntityType' => 'Account']);
        $this->account = $this->entity('Account', ['id' => 'account-1', 'name' => 'Acme']);
        $this->stored['enrollment-1'] = $this->entity('JourneyRecord', [
            'id' => 'enrollment-1', 'journeyId' => 'journey-1', 'cycleCount' => 0,
            'targetType' => 'Account', 'targetId' => 'account-1',
        ]);
        $this->actor = $this->createMock(User::class);
        $this->actor->method('getId')->willReturn('actor-1');

        $factory = $this->createMock(InjectableFactory::class);
        $factory->method('create')->willReturnCallback(fn ($class) => new $class($this->em, $this->guard));
        $formulas = $this->createMock(FormulaManager::class);
        $formulas->method('run')->willReturnCallback(static fn ($script, $entity) => $entity->get('name'));
        $conditions = $this->createMock(ActionConditionEvaluator::class);
        $conditions->method('isEmpty')->willReturnCallback(static fn ($value) => !$value);
        $conditions->method('matches')->willReturnCallback(static fn ($target) => $target->getEntityType() === 'Opportunity');
        $this->runner = new ActionRunner(
            $this->em, $this->metadata, $factory, $this->createMock(JourneyRateLimiter::class),
            $this->guard, new RestrictedFormulaRunner($formulas), $conditions,
            $this->createMock(Log::class), $this->references,
        );
    }

    public function testCrossStageTasksUseTheirSelectedParentAndCreationIsReused(): void
    {
        $this->allowAccess();
        $this->pdo->expects($this->exactly(2))->method('beginTransaction');
        $this->pdo->expects($this->exactly(2))->method('commit');
        $create = $this->action('createRecord', [
            'saveAs' => 'prospectingOpportunity',
            'params' => ['entityType' => 'Opportunity', 'fields' => ['name' => 'Acme deal'], 'linkToTarget' => 'account'],
        ]);
        $result = $this->runActions([$create], 'D0');
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame('account-1', $this->stored['created-1']->get('accountId'));

        // A later job reloads only persisted enrollment state.
        $task = $this->action('createTask', [
            'targetReference' => 'prospectingOpportunity',
            'conditionsGroup' => ['type' => 'equals', 'attribute' => 'name', 'value' => 'Acme deal'],
            'params' => ['paramFormulas' => ['name' => 'entity\\attribute("name")']],
        ]);
        $accountTask = $this->action('createTask', ['params' => ['name' => 'Account follow-up']]);
        $this->assertTrue($this->runActions([$task, $accountTask], 'D2')['ok']);
        $this->assertSame('Opportunity', $this->stored['created-2']->get('parentType'));
        $this->assertSame('created-1', $this->stored['created-2']->get('parentId'));
        $this->assertSame('Acme deal', $this->stored['created-2']->get('name'));
        $this->assertSame('Account', $this->stored['created-3']->get('parentType'));
        $this->assertSame('account-1', $this->stored['created-3']->get('parentId'));

        $this->assertTrue($this->runActions([$create], 'D0')['ok']);
        $this->assertSame(3, $this->sequence, 'Replaying the create action must not create a second Opportunity.');
    }

    public function testSaveFailureRollsBackAndDoesNotPolluteTheCallersEnrollment(): void
    {
        $this->allowAccess();
        $this->failReferenceSave = true;
        $this->pdo->expects($this->once())->method('rollBack');
        $this->pdo->expects($this->never())->method('commit');
        $record = clone $this->stored['enrollment-1'];
        $context = $this->context($record);
        $action = $this->action('createRecord', ['saveAs' => 'deal']);
        try {
            $this->references->runCreate($action, $context, function () use ($context): void {
                $context->createdRecord = $this->entity('Opportunity', ['id' => 'deal-1']);
            });
            $this->fail('Expected a save failure.');
        } catch (Error $e) {
            $this->assertStringContainsString('save failure', $e->getMessage());
        }
        $this->assertNull($record->get('recordReferences'));
        $this->assertNull($this->stored['enrollment-1']->get('recordReferences'));
    }

    public function testSameStageCanConsumeAndSaveATaskReference(): void
    {
        $this->allowAccess();
        $create = $this->action('createRecord', [
            'saveAs' => 'deal', 'params' => ['entityType' => 'Opportunity'],
        ]);
        $task = $this->action('createTask', ['saveAs' => 'followup', 'targetReference' => 'deal']);
        $this->assertTrue($this->runActions([$create, $task])['ok']);
        $saved = (array) $this->stored['enrollment-1']->get('recordReferences');
        $this->assertSame('Opportunity', $saved['deal']->entityType);
        $this->assertSame('Task', $saved['followup']->entityType);
        $this->assertSame('created-1', $this->stored['created-2']->get('parentId'));
        $this->assertTrue($this->runActions([$task])['ok']);
        $this->assertSame(2, $this->sequence);
    }

    public function testRelatedCreationCanBeSavedAndUpdatedInALaterStage(): void
    {
        $this->allowAccess();
        $create = $this->action('createRelatedRecord', [
            'saveAs' => 'deal', 'params' => ['link' => 'opportunities', 'fields' => ['name' => 'Initial deal']],
        ]);
        $this->assertTrue($this->runActions([$create], 'D0')['ok']);
        $this->assertSame('account-1', $this->stored['created-1']->get('accountId'));
        $update = $this->action('updateTarget', [
            'targetReference' => 'deal', 'params' => ['fields' => ['name' => 'Qualified deal']],
        ]);
        $this->assertTrue($this->runActions([$update], 'D2')['ok']);
        $this->assertSame('Qualified deal', $this->stored['created-1']->get('name'));
        $this->assertSame('Acme', $this->account->get('name'));
    }

    public function testTransientReferenceSaveFailureUsesActionRetries(): void
    {
        $this->allowAccess();
        $this->failReferenceSave = true;
        $this->pdo->expects($this->exactly(2))->method('beginTransaction');
        $this->pdo->expects($this->once())->method('rollBack');
        $this->pdo->expects($this->once())->method('commit');
        $create = $this->action('createRecord', [
            'saveAs' => 'deal', 'maxRetries' => 1, 'params' => ['entityType' => 'Opportunity'],
        ]);
        $this->assertTrue($this->runActions([$create])['ok']);
        $this->assertSame('created-2', $this->stored['enrollment-1']->get('recordReferences')->deal->id);
    }

    public function testReadDeniedReferenceDoesNotExecute(): void
    {
        $this->seedReference();
        $this->acl->method('check')->willReturn(false);
        $result = $this->runActions([$this->action('createTask', ['targetReference' => 'deal'])]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no read access', $result['error']);
        $this->assertSame(0, $this->sequence);
    }

    public function testForbiddenUpdateFieldDoesNotExecute(): void
    {
        $this->seedReference();
        $this->allowAccess();
        $this->acl->method('getScopeForbiddenAttributeList')->willReturn(['amount']);
        $result = $this->runActions([$this->action('updateTarget', [
            'targetReference' => 'deal', 'params' => ['fields' => ['amount' => 100]],
        ])]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("cannot edit field 'amount'", $result['error']);
    }

    public function testMissingReferenceDoesNotFallBackToAccountAndHonorsContinueOnError(): void
    {
        $missing = $this->action('createTask', ['targetReference' => 'missing']);
        $this->assertFalse($this->runActions([$missing])['ok']);
        $this->assertSame(0, $this->sequence);
        $missing->set('continueOnError', true);
        $result = $this->runActions([$missing, $this->action('createTask')]);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('Account', $this->stored['created-1']->get('parentType'));
    }

    public function testDeletedAndForeignTenantRecordsAreRejected(): void
    {
        $this->allowAccess();
        $this->seedReference();
        $this->stored['deal-1']->set('tenantId', 'another-tenant');
        $action = $this->action('createTask', ['targetReference' => 'deal']);
        $this->assertFalse($this->runActions([$action])['ok']);
        unset($this->stored['deal-1']);
        $this->assertFalse($this->runActions([$action])['ok']);
        $this->assertSame(0, $this->sequence);
    }

    public function testReferencesCannotBeSharedBetweenEnrollmentsOrCycles(): void
    {
        $this->allowAccess();
        $this->seedReference();
        $action = $this->action('createTask', ['targetReference' => 'deal']);
        $other = $this->entity('JourneyRecord', ['id' => 'enrollment-2', 'cycleCount' => 0]);
        $this->assertFalse($this->runActions([$action], record: $other)['ok']);
        $this->stored['enrollment-1']->set('cycleCount', 1);
        $this->assertFalse($this->runActions([$action])['ok']);
    }

    public function testRunAsReadAndEditDenialsAreEnforced(): void
    {
        $this->seedReference();
        $this->acl->method('check')->willReturnCallback(static fn ($entity, $access) => $access === 'read');
        $this->assertFalse($this->runActions([$this->action('updateTarget', ['targetReference' => 'deal'])])['ok']);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('no read access');
        $this->references->resolveTarget(
            $this->action('createTask', ['targetReference' => 'deal']), $this->account,
            $this->stored['enrollment-1'], $this->journey, null,
        );
    }

    public function testDeniedCreateScopeDoesNotCreateATask(): void
    {
        $this->seedReference();
        $this->acl->method('check')->willReturn(true);
        $this->acl->method('checkScope')->willReturn(false);
        $result = $this->runActions([$this->action('createTask', ['targetReference' => 'deal'])]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('cannot create Task', $result['error']);
        $this->assertSame(0, $this->sequence);
    }

    public function testDifferentProducerCannotOverwriteAnExistingReference(): void
    {
        $this->allowAccess();
        $this->seedReference();
        $this->expectException(Error::class);
        $this->expectExceptionMessage('already owned');
        $this->references->runCreate(
            $this->action('createRecord', ['saveAs' => 'deal']),
            $this->context(clone $this->stored['enrollment-1']),
            fn () => $this->fail('Must not execute the second producer.'),
        );
    }

    public function testDefinitionsResolveRelatedCreationsOnSavedRecords(): void
    {
        $account = $this->action('createRecord', ['saveAs' => 'company', 'params' => ['entityType' => 'Account']]);
        $deal = $this->action('createRelatedRecord', [
            'saveAs' => 'deal', 'targetReference' => 'company', 'params' => ['link' => 'opportunities'],
        ]);
        $this->assertSame(['Account', 'Opportunity'], array_column(
            $this->references->definitions($this->journey, [$account, $deal]), 'entityType',
        ));
    }

    public function testDuplicateAndCircularDefinitionsAreRejected(): void
    {
        $a = $this->action('createRecord', ['saveAs' => 'a', 'params' => ['entityType' => 'Account']]);
        $b = $this->action('createRecord', ['saveAs' => 'a', 'params' => ['entityType' => 'Account']]);
        try {
            $this->references->definitions($this->journey, [$a, $b]);
            $this->fail('Expected a duplicate reference error.');
        } catch (Error $e) {
            $this->assertStringContainsString('multiple actions', $e->getMessage());
        }
        $a->set('targetReference', 'b');
        $b->set(['saveAs' => 'b', 'targetReference' => 'a']);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Circular');
        $this->references->definitions($this->journey, [$a, $b]);
    }

    public function testMissingProducerIsRejectedAtPublish(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessage('no active create action');
        $this->references->definitions($this->journey, [$this->action('createTask', ['targetReference' => 'missing'])]);
    }

    public function testBuilderCanRepairAConsumerAfterAProducerIsRenamed(): void
    {
        $definitions = $this->references->definitions($this->journey, [
            $this->action('createRecord', ['saveAs' => 'newName', 'params' => ['entityType' => 'Opportunity']]),
            $this->action('createTask', ['targetReference' => 'oldName']),
        ], validateConsumers: false);
        $this->assertSame(['newName'], array_column($definitions, 'key'));
    }

    public function testInvalidReferenceNamesAndUnsupportedActionsAreRejected(): void
    {
        foreach (['../deal', '1deal', 'deal.name', str_repeat('a', 65)] as $key) {
            try {
                $this->references->key($key);
                $this->fail('Expected an invalid key error.');
            } catch (Error $e) {
                $this->assertStringContainsString('reference names', $e->getMessage());
            }
        }
        $this->expectException(Error::class);
        $this->references->validateAction($this->action('sendEmail', ['saveAs' => 'mail']));
    }

    private function allowAccess(): void
    {
        $this->acl->method('check')->willReturn(true);
        $this->acl->method('checkScope')->willReturn(true);
    }

    private function seedReference(): void
    {
        $this->stored['deal-1'] = $this->entity('Opportunity', ['id' => 'deal-1']);
        $this->stored['enrollment-1']->set('recordReferences', (object) ['deal' => (object) [
            'entityType' => 'Opportunity', 'id' => 'deal-1', 'actionId' => 'original-producer', 'cycleCount' => 0,
        ]]);
    }

    private function runActions(array $actions, string $stage = 'D2', ?Entity $record = null): array
    {
        return $this->runner->runActionsList(
            $actions, $this->account, $record ?? clone $this->stored['enrollment-1'],
            $this->entity('JourneyStage', ['id' => $stage]), $this->journey, 'OnEnter', 'tenant-1', $this->actor,
        );
    }

    private function context(Entity $record): ActionContext
    {
        return new ActionContext(
            $this->account, $record, $this->entity('JourneyStage', ['id' => 'D0']),
            $this->journey, 'OnEnter', [], 'tenant-1', $this->actor,
        );
    }

    private function action(string $type, array $values = []): Entity
    {
        return $this->entity('JourneyStageAction', $values + [
            'id' => uniqid('action-'), 'type' => $type, 'isActive' => true,
            'params' => [], 'maxRetries' => 0, 'continueOnError' => false,
        ]);
    }

    private function entity(string $type, array $values = []): Entity
    {
        $attributes = array_fill_keys(array_merge(array_keys($values), [
            'id', 'tenantId', 'recordReferences', 'cycleCount', 'name', 'status', 'priority', 'description',
            'dateEnd', 'assignedUserId', 'parentType', 'parentId', 'accountId', 'saveAs', 'targetReference',
            'continueOnError',
        ]), ['type' => 'varchar']);
        foreach (['params', 'recordReferences', 'conditionsGroup'] as $attribute) {
            $attributes[$attribute] = ['type' => 'jsonObject'];
        }
        foreach (['isActive', 'continueOnError'] as $attribute) {
            $attributes[$attribute] = ['type' => 'bool'];
        }
        $attributes['cycleCount'] = ['type' => 'int'];
        $entity = new CoreEntity($type, ['attributes' => $attributes, 'relations' => [
            'account' => ['type' => Entity::BELONGS_TO, 'entity' => 'Account'],
            'opportunities' => ['type' => Entity::HAS_MANY, 'entity' => 'Opportunity', 'foreign' => 'account'],
        ]]);
        $entity->set($values + ['tenantId' => 'tenant-1']);

        return $entity;
    }
}
