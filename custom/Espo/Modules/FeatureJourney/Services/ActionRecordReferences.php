<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Closure;
use Espo\Core\AclManager;
use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/** Durable, enrollment-local references to records created by journey actions. */
class ActionRecordReferences
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
        private AclManager $aclManager,
        private Metadata $metadata,
    ) {}

    public function validateAction(Entity $action): void
    {
        $meta = $this->metadata->get(['app', 'journeyActionTypes', 'types', $action->get('type')]) ?? [];

        foreach (['targetReference', 'saveAs'] as $field) {
            $key = $this->key($action->get($field));
            if ($key === '') {
                continue;
            }

            $enabled = $field === 'saveAs' ? ($meta['producesRecord'] ?? false) : ($meta['referenceTargetAccess'] ?? false);
            if (!$enabled) {
                throw new Error("{$field} is not supported by action type '{$action->get('type')}'.");
            }
        }

        if ($action->get('saveAs') && $action->get('saveAs') === $action->get('targetReference')) {
            throw new Error('An action cannot target its own saved record.');
        }

        $params = (array) ($action->get('params') ?? []);
        $formulas = (array) ($params['paramFormulas'] ?? []);
        if ($action->get('saveAs') && (isset($formulas['entityType']) || isset($formulas['link']))) {
            throw new Error('Saved creations require a fixed record type and relation link.');
        }
    }

    public function resolveTarget(
        Entity $action,
        Entity $target,
        Entity $record,
        Entity $journey,
        ?User $actor,
    ): Entity {
        $this->validateAction($action);
        $key = $this->key($action->get('targetReference'));
        if ($key === '') {
            return $target;
        }

        $tenantId = $this->tenantGuard->requireTenantId($journey, 'journey');
        $this->tenantGuard->assertRecordMatchesJourney($record, $journey);
        $reference = $this->referenceMap($record)[$key] ?? null;
        $entity = $this->loadReference($reference, $record, $tenantId, $actor, $key);
        $access = $this->metadata->get([
            'app', 'journeyActionTypes', 'types', $action->get('type'), 'referenceTargetAccess',
        ]);

        if ($access === 'edit') {
            $this->assertAccess($entity, $actor, 'edit');
        }

        return $entity;
    }

    public function assertActionAccess(Entity $action, ActionContext $context): void
    {
        $actor = $context->actor;
        if (!$actor) {
            throw new Error('Saved record actions require a run-as user.');
        }
        $acl = $this->aclManager->createUserAcl($actor);
        $type = $action->get('type');
        $createdType = match ($type) {
            'createTask' => 'Task',
            'createRecord' => (string) ($context->params['entityType'] ?? $context->params['link'] ?? ''),
            'createRelatedRecord' => $this->tenantGuard->resolveLinkForeignEntityType(
                $context->target, (string) ($context->params['link'] ?? ''),
            ),
            default => null,
        };
        if ($createdType !== null && !$acl->checkScope($createdType, 'create')) {
            throw new Error("Run-as user cannot create {$createdType} records.");
        }
        if ($type === 'createTask') {
            $parents = $this->metadata->get(['entityDefs', 'Task', 'fields', 'parent', 'entityList']) ?? [];
            if (!in_array($context->target->getEntityType(), $parents, true)) {
                throw new Error('The selected record type cannot be a Task parent.');
            }
        }
        if ($type === 'updateTarget') {
            $fields = (array) ($context->params['fields'] ?? $context->params);
            $forbidden = $acl->getScopeForbiddenAttributeList($context->target->getEntityType(), 'edit');
            foreach (array_keys($fields) as $field) {
                if (in_array(explode('.', $field)[0], $forbidden, true)) {
                    throw new Error("Run-as user cannot edit field '{$field}' on the saved record.");
                }
            }
        }
    }

    /**
     * Save creation + reference atomically. Lock the enrollment so a retried or
     * concurrent invocation reuses the original result instead of creating again.
     * The caller's record is changed only after the transaction succeeds.
     */
    public function runCreate(Entity $action, ActionContext $context, Closure $run): void
    {
        $this->validateAction($action);
        $key = $this->key($action->get('saveAs'));
        if ($key === '') {
            $run();
            return;
        }

        $tenantId = $this->tenantGuard->requireTenantId($context->journey, 'journey');
        $references = $this->entityManager->getTransactionManager()->run(function () use (
            $action, $context, $run, $key, $tenantId,
        ): stdClass {
            $record = $this->entityManager->getRDBRepository('JourneyRecord')
                ->where(['id' => $context->record->getId()])->forUpdate()->findOne();

            if (!$record) {
                throw new Error('Cannot save an action result: enrollment no longer exists.');
            }

            $this->tenantGuard->assertRecordMatchesJourney($record, $context->journey);
            $this->tenantGuard->assertEntityTenant($record, $tenantId, 'enrollment');
            if ((int) $record->get('cycleCount') !== (int) $context->record->get('cycleCount')) {
                throw new Error('Cannot save an action result for a different enrollment cycle.');
            }

            $references = $this->referenceMap($record);
            if (isset($references[$key])) {
                $reference = (array) $references[$key];
                if (($reference['actionId'] ?? null) !== $action->getId()) {
                    throw new Error("Record reference '{$key}' is already owned by another action.");
                }
                $context->createdRecord = $this->loadReference(
                    $reference, $record, $tenantId, $context->actor, $key,
                );

                return (object) $references;
            }

            $context->createdRecord = null;
            $run();
            $created = $context->createdRecord;
            if (!$created || !$created->hasId()) {
                throw new Error("Action did not produce a record for '{$key}'.");
            }
            $this->tenantGuard->assertEntityTenant($created, $tenantId, 'created record');
            $this->assertAccess($created, $context->actor, 'read');

            $references[$key] = (object) [
                'entityType' => $created->getEntityType(),
                'id' => $created->getId(),
                'actionId' => $action->getId(),
                'cycleCount' => (int) $record->get('cycleCount'),
            ];
            $record->set('recordReferences', (object) $references);
            $this->entityManager->saveEntity($record, [
                SaveOption::SILENT => true,
                'skipJourneyDispatch' => true,
                'skipWorkflow' => true,
            ]);

            return (object) $references;
        });

        $context->record->set('recordReferences', $references);
        $context->record->setFetched('recordReferences', $references);
    }

    private function loadReference(
        mixed $reference,
        Entity $record,
        string $tenantId,
        ?User $actor,
        string $key,
    ): Entity {
        $reference = $reference instanceof stdClass ? (array) $reference : $reference;
        if (
            !is_array($reference) ||
            !is_string($reference['entityType'] ?? null) ||
            !is_string($reference['id'] ?? null) ||
            !isset($reference['cycleCount']) ||
            (int) $reference['cycleCount'] !== (int) $record->get('cycleCount')
        ) {
            throw new Error("Record reference '{$key}' is unavailable in this enrollment. Run its create action first.");
        }

        $this->tenantGuard->assertEntityTypeCreatable($reference['entityType']);
        $entity = $this->tenantGuard->loadEntityInTenant(
            $reference['entityType'], $reference['id'], $tenantId, "record reference '{$key}'",
        );
        $this->assertAccess($entity, $actor, 'read');

        return $entity;
    }

    private function assertAccess(Entity $entity, ?User $actor, string $access): void
    {
        if (!$actor || !$this->aclManager->createUserAcl($actor)->check($entity, $access)) {
            throw new Error("Run-as user has no {$access} access to the saved {$entity->getEntityType()} record.");
        }
    }

    /** @return array<string, mixed> */
    private function referenceMap(Entity $record): array
    {
        $value = $record->get('recordReferences');

        return $value instanceof stdClass ? (array) $value : (is_array($value) ? $value : []);
    }

    public function key(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $value)) {
            throw new Error('Record reference names must start with a letter and contain only letters, digits or underscores (max 64).');
        }

        return $value;
    }

    /** @return list<Entity> */
    public function loadActions(Entity $journey): array
    {
        $tenantId = $this->tenantGuard->requireTenantId($journey, 'journey');
        $stageIds = [];
        foreach ($this->entityManager->getRDBRepository('JourneyStage')->where([
            'journeyId' => $journey->getId(), 'isActive' => true, 'tenantId' => $tenantId,
        ])->find() as $stage) {
            $stageIds[] = $stage->getId();
        }
        if ($stageIds === []) {
            return [];
        }

        $actions = [];
        foreach ($this->entityManager->getRDBRepository('JourneyStageAction')->where([
            'stageId' => $stageIds, 'isActive' => true, 'tenantId' => $tenantId,
        ])->order('order', 'ASC')->find() as $action) {
            $actions[] = $action;
        }

        return $actions;
    }

    public function assertUniqueName(Entity $action, Entity $journey): void
    {
        $key = $this->key($action->get('saveAs'));
        if ($key === '' || !$action->get('isActive')) {
            return;
        }
        foreach ($this->loadActions($journey) as $other) {
            if ($other->getId() !== $action->getId() && $other->get('saveAs') === $key) {
                throw new Error("Record reference '{$key}' is already used by another action in this journey.");
            }
        }
    }

    /**
     * Definition-time references for the builder and publish validation. Their
     * entity types are derived from create actions, never supplied by consumers.
     * @param list<Entity> $actions
     * @return list<array{key: string, entityType: string, actionId: string, actionName: string}>
     */
    public function definitions(Entity $journey, array $actions, bool $validateConsumers = true): array
    {
        $producers = [];
        foreach ($actions as $action) {
            $this->validateAction($action);
            $key = $this->key($action->get('saveAs'));
            if ($key === '') {
                continue;
            }
            if (isset($producers[$key])) {
                throw new Error("Record reference '{$key}' is used by multiple actions.");
            }
            $producers[$key] = $action;
        }

        $types = [];
        $resolve = function (string $key, array $visiting = []) use (&$resolve, &$types, $producers, $journey): string {
            if (isset($types[$key])) {
                return $types[$key];
            }
            if (isset($visiting[$key])) {
                throw new Error("Circular record reference '{$key}'.");
            }
            $action = $producers[$key] ?? null;
            if (!$action) {
                throw new Error("Record reference '{$key}' has no active create action in this journey.");
            }
            $visiting[$key] = true;
            $parentKey = $this->key($action->get('targetReference'));
            $targetType = $parentKey !== ''
                ? $resolve($parentKey, $visiting)
                : (string) $journey->get('targetEntityType');
            $params = (array) ($action->get('params') ?? []);
            $entityType = match ($action->get('type')) {
                'createTask' => 'Task',
                'createRecord' => (string) ($params['entityType'] ?? $params['link'] ?? ''),
                'createRelatedRecord' => (string) ($this->metadata->get([
                    'entityDefs', $targetType, 'links', $params['link'] ?? '', 'entity',
                ]) ?? ''),
                default => '',
            };
            $this->tenantGuard->assertEntityTypeCreatable($entityType);
            if ($entityType === '') {
                throw new Error("Cannot determine the record type for '{$key}'.");
            }

            return $types[$key] = $entityType;
        };

        foreach ($actions as $action) {
            $key = $this->key($action->get('targetReference'));
            if ($validateConsumers && $key !== '') {
                $resolve($key);
            }
        }

        $definitions = [];
        foreach ($producers as $key => $action) {
            $definitions[] = [
                'key' => $key,
                'entityType' => $resolve($key),
                'actionId' => $action->getId(),
                'actionName' => (string) ($action->get('name') ?: $key),
            ];
        }

        return $definitions;
    }
}
