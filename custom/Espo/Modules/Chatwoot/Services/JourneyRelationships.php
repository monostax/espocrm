<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\Modules\FeatureJourney\Services\JourneyEnrollmentService;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

/** The relationship is an enrollment cycle, not the journey's audience configuration. */
class JourneyRelationships
{
    private const LIVE = ['Active', 'Processing', 'Paused'];

    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private User $user,
        private Access $access,
        private TenantGuard $guard,
        private SelectBuilderFactory $select,
        private JourneyEnrollmentService $enrollment,
    ) {}

    public function context(int $accountId, string $type, string $id): array
    {
        if (!in_array($type, ['Contact', 'Account', 'Lead', 'Opportunity'], true) || $id === '') {
            throw new BadRequest('Unsupported enrollment target.');
        }
        $tenant = $this->access->workspace($accountId);
        $target = $this->em->getEntityById($type, $id);
        if (!$target || !$this->acl->check($target, 'read') ||
            !$this->guard->entityBelongsToTenant($target, $tenant->getId())) {
            throw new NotFound();
        }
        return [$tenant, $target];
    }

    private function query(string $type, Entity $tenant): SelectBuilder
    {
        return $this->select->create()->from($type)->withStrictAccessControl()
            ->buildQueryBuilder()->where(['tenantId' => $tenant->getId()]);
    }

    private function targetWhere(Entity $target): array
    {
        return ['targetType' => $target->getEntityType(), 'targetId' => $target->getId()];
    }

    private function canEnroll(Entity $target): bool
    {
        return $this->acl->check($target, 'edit') &&
            $this->acl->checkScope('Journey', 'read') &&
            $this->acl->checkField('Journey', 'name', 'read') &&
            $this->canReadEnrollments() &&
            $this->acl->checkScope('JourneyRecord', 'create') &&
            $this->acl->checkField('JourneyRecord', 'journey', 'edit') &&
            $this->acl->checkField('JourneyRecord', 'target', 'edit');
    }

    private function canReadEnrollments(): bool
    {
        return $this->acl->checkScope('JourneyRecord', 'read') &&
            $this->acl->checkField('JourneyRecord', 'target', 'read');
    }

    public function list(Entity $tenant, Entity $target, int $offset): object
    {
        if (!$this->canReadEnrollments()) {
            return (object) ['list' => [], 'hasMore' => false, 'canEnroll' => false];
        }
        $query = $this->query('JourneyRecord', $tenant)->where($this->targetWhere($target))
            ->order('createdAt', 'DESC')->order('id', 'DESC')->limit($offset, 101);
        $records = iterator_to_array($this->em->getRDBRepository('JourneyRecord')->clone($query->build())->find(), false);
        return (object) [
            'list' => array_map(fn (Entity $record) => $this->present($record, $tenant, $target), array_slice($records, 0, 100)),
            'hasMore' => count($records) > 100,
            'canEnroll' => $this->canEnroll($target),
        ];
    }

    public function options(Entity $tenant, Entity $target, string $search, int $offset): object
    {
        if (!$this->canEnroll($target)) throw new Forbidden();
        // Look at all cycles in this workspace, including cycles outside the caller's
        // record ACL, so a hidden enrollment cannot become a duplicate candidate.
        $history = SelectBuilder::create()->from('JourneyRecord')->select('journeyId')
            ->where(['tenantId' => $tenant->getId(), ...$this->targetWhere($target)]);
        $live = clone $history;
        $live->where(['status' => self::LIVE]);
        $query = $this->query('Journey', $tenant)->where([
            'targetEntityType' => $target->getEntityType(),
            'status' => 'Active',
            'id!=s' => $live->build(),
            'OR' => [['allowReEnrollment' => true], ['id!=s' => $history->build()]],
        ])->order('name')->order('id')->limit($offset, 51);
        if ($search !== '') $query->where(['name*' => '%' . $search . '%']);
        $records = iterator_to_array($this->em->getRDBRepository('Journey')->clone($query->build())->find(), false);
        return (object) [
            'list' => array_map(fn (Entity $journey) => (object) [
                'kind' => 'record', 'entityType' => 'Journey',
                'recordId' => $journey->getId(), 'label' => $this->value($journey, 'name'),
            ], array_slice($records, 0, 50)),
            'hasMore' => count($records) > 50,
        ];
    }

    public function enroll(Entity $tenant, Entity $target, string $journeyId): object
    {
        if (!$this->canEnroll($target)) throw new Forbidden();
        $journey = $this->related('Journey', $journeyId, $tenant);
        if (!$journey) throw new NotFound();
        if ($journey->get('status') !== 'Active') throw new BadRequest('Only Active journeys accept enrollment.');
        if ($journey->get('targetEntityType') !== $target->getEntityType()) {
            throw new BadRequest('Enrollment target type must match the Journey target type.');
        }
        // Use the engine's run-as identity, duplicate/cycle checks, logs and OnEnter actions.
        $result = $this->enrollment->tryEnrollOne($journey, $target->getEntityType(), $target->getId());
        // Engine diagnostics may contain internal exception messages. Only expose
        // the stable result code; the engine already logs the diagnostic details.
        return (object) ['ok' => $result['ok'], 'reason' => $result['reason'] ?? null];
    }

    private function record(Entity $tenant, Entity $target, string $id): Entity
    {
        if (!$this->canReadEnrollments()) throw new Forbidden();
        $query = $this->query('JourneyRecord', $tenant)->where(['id' => $id, ...$this->targetWhere($target)]);
        $record = $this->em->getRDBRepository('JourneyRecord')->clone($query->build())->findOne();
        if (!$record) throw new NotFound();
        return $record;
    }

    public function read(Entity $tenant, Entity $target, string $id): object
    {
        return $this->present($this->record($tenant, $target, $id), $tenant, $target);
    }

    public function unlink(Entity $tenant, Entity $target, string $id): object
    {
        $record = $this->record($tenant, $target, $id);
        if (!$this->acl->check($target, 'edit') || !$this->acl->check($record, 'edit') ||
            !$this->acl->checkField('JourneyRecord', 'status', 'edit')) throw new Forbidden();
        // Retrying an exit is harmless; never delete the cycle or its history.
        if ($record->get('status') !== 'Exited') {
            $record = $this->enrollment->exitRecord($id, $this->user->getId(), 'manual');
        }
        return $this->present($record, $tenant, $target);
    }

    private function related(string $type, ?string $id, Entity $tenant): ?Entity
    {
        if (!$id || !$this->acl->checkScope($type, 'read')) return null;
        return $this->em->getRDBRepository($type)
            ->clone($this->query($type, $tenant)->where(['id' => $id])->build())->findOne();
    }

    private function present(Entity $record, Entity $tenant, Entity $target): object
    {
        $journey = $this->related('Journey', $this->value($record, 'journeyId'), $tenant);
        $stage = $this->related('JourneyStage', $this->value($record, 'currentStageId'), $tenant);
        return (object) [
            'id' => $record->getId(), 'entityType' => 'JourneyRecord',
            'name' => $this->value($journey, 'name'),
            'journeyId' => $journey?->getId(), 'journeyName' => $this->value($journey, 'name'),
            'targetType' => $target->getEntityType(), 'targetId' => $target->getId(),
            'targetName' => $this->value($target, 'name'),
            'currentStageName' => $this->value($stage, 'name'),
            'status' => $this->value($record, 'status'), 'createdAt' => $this->value($record, 'createdAt'),
            'enteredStageAt' => $this->value($record, 'enteredStageAt'),
            'cycleCount' => $this->value($record, 'cycleCount'), 'exitReason' => $this->value($record, 'exitReason'),
            'canUnlink' => in_array($record->get('status'), self::LIVE, true) &&
                $this->acl->check($record, 'edit') && $this->acl->check($target, 'edit') &&
                $this->acl->checkField('JourneyRecord', 'status', 'edit'),
            'canStream' => false, 'canDelete' => false,
        ];
    }

    private function value(?Entity $entity, string $attribute): mixed
    {
        if (!$entity || in_array($attribute, $this->acl->getScopeForbiddenAttributeList($entity->getEntityType()), true)) {
            return null;
        }
        return $entity->get($attribute);
    }
}
