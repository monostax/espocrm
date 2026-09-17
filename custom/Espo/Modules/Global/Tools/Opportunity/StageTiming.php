<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Tools\Opportunity;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\DateTime\Clock;
use Espo\Core\Utils\Util;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use LogicException;
use WeakMap;

/** Transactional, server-owned stage visits. No dependency on opt-in analytics. */
class StageTiming
{
    public const CACHE_FIELDS = [
        'stageTrackingStartedAt', 'currentStageVisitId', 'stageEnteredAt',
        'stageTargetTimeSeconds', 'stageDueAt', 'stageTimingIsPartial',
    ];

    /** @var WeakMap<Entity, list<Entity>> */
    private WeakMap $pending;

    public function __construct(private EntityManager $entityManager, private Clock $clock)
    {
        $this->pending = new WeakMap();
    }

    public function prepare(Entity $entity, ?string $activationTime = null): void
    {
        unset($this->pending[$entity]);

        if (!$this->entityManager->getTransactionManager()->isStarted()) {
            throw new LogicException('Stage timing requires a transactional Opportunity save.');
        }

        $old = null;
        if (!$entity->isNew()) {
            $old = $this->entityManager->getRDBRepository('Opportunity')
                ->where(['id' => $entity->getId()])->forUpdate()->findOne();

            if (!$old) {
                throw new Conflict('The opportunity no longer exists.');
            }

            // Reject stale transitions, including A -> B -> A between load and save.
            // An unrelated stale save must not undo a timer or a status side effect either.
            foreach (['opportunityStageId', 'funnelId', 'status', 'currentStageVisitId'] as $field) {
                if ($entity->hasFetched($field) && $entity->getFetched($field) !== $old->get($field)) {
                    throw new Conflict('The opportunity stage changed. Reload the record and try again.');
                }
            }
        }

        // Never accept copied/imported/client-supplied timing fields.
        foreach (self::CACHE_FIELDS as $field) {
            $entity->set($field, $old?->get($field));
        }

        $changed = !$old;
        foreach (['opportunityStageId', 'funnelId', 'status'] as $field) {
            $changed = $changed || ($old && $old->get($field) !== $entity->get($field));
        }

        if (!$changed && $old?->get('stageTrackingStartedAt')) {
            return;
        }

        $now = $activationTime ?? $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        // Do not produce negative intervals if the server clock is corrected backwards.
        $now = max($now, (string) ($old?->get('stageEnteredAt') ?? ''));
        $rows = [];

        if ($old?->get('currentStageVisitId')) {
            $visit = $this->entityManager->getEntityById('OpportunityStageHistory', $old->get('currentStageVisitId'));
            if (!$visit || $visit->get('opportunityId') !== $entity->getId() ||
                $visit->get('exitedAt') || $visit->get('kind') !== 'Visit') {
                throw new LogicException('Invalid active opportunity stage visit.');
            }
            $visit->set([
                'exitedAt' => $now,
                'durationSeconds' => self::secondsBetween($visit->get('enteredAt'), $now),
            ]);
            $rows[] = $visit;
        } elseif ($changed && $old && !$old->get('stageTrackingStartedAt') &&
            $old->get('status') === 'Open' && $old->get('opportunityStageId')) {
            // A transition before the rollout initializer reaches this row: acknowledge
            // the previous visit as partial, with zero observed time, never infer its age.
            $visit = $this->newEntry($old, $now, true);
            $visit->set(['exitedAt' => $now, 'durationSeconds' => 0]);
            $rows[] = $visit;
        }

        $entity->set([
            'stageTrackingStartedAt' => $old?->get('stageTrackingStartedAt') ?: $now,
            'currentStageVisitId' => null,
            'stageEnteredAt' => null,
            'stageTargetTimeSeconds' => null,
            'stageDueAt' => null,
            'stageTimingIsPartial' => false,
        ]);

        if ($entity->get('opportunityStageId') && ($entity->get('status') === 'Open' || $changed)) {
            $partial = (bool) ($old && !$changed);
            $entry = $this->newEntry($entity, $now, $partial);
            $rows[] = $entry;
            $entity->set('stageEnteredAt', $now);

            if ($entry->get('kind') === 'Visit') {
                $entity->set([
                    'currentStageVisitId' => $entry->getId(),
                    'stageTargetTimeSeconds' => $entry->get('targetTimeSeconds'),
                    'stageDueAt' => $entry->get('dueAt'),
                    'stageTimingIsPartial' => $partial,
                ]);
            }
        }

        $this->pending[$entity] = $rows;
    }

    public function complete(Entity $entity): void
    {
        $rows = $this->pending[$entity] ?? [];
        unset($this->pending[$entity]);
        if (!$rows) {
            return;
        }
        $sequence = (int) $this->entityManager->getRDBRepository('OpportunityStageHistory')
            ->where(['opportunityId' => $entity->getId()])->max('sequence');
        foreach ($rows as $row) {
            $row->set('opportunityId', $entity->getId());
            if ($row->isNew()) {
                $row->set('sequence', ++$sequence);
            }
            $this->entityManager->saveEntity($row, ['skipAll' => true]);
        }
    }

    /** Rebuild path: short per-record transaction, no replay of opportunity automations. */
    public function initialize(string $id, string $activationTime): void
    {
        $this->entityManager->getTransactionManager()->run(function () use ($id, $activationTime): void {
            $entity = $this->entityManager->getRDBRepository('Opportunity')
                ->where(['id' => $id])->forUpdate()->findOne();
            if (!$entity || $entity->get('stageTrackingStartedAt')) {
                return;
            }

            $this->prepare($entity, $activationTime);
            $this->entityManager->saveEntity($entity, ['skipAll' => true]);
            $this->complete($entity);
        });
    }

    private function newEntry(Entity $opportunity, string $now, bool $partial): Entity
    {
        $stage = $this->entityManager->getEntityById('OpportunityStage', $opportunity->get('opportunityStageId'));
        $funnel = $opportunity->get('funnelId') ?
            $this->entityManager->getEntityById('Funnel', $opportunity->get('funnelId')) : null;
        $open = $opportunity->get('status') === 'Open';
        $target = $open && $stage?->get('targetTimeSeconds') > 0 ? (int) $stage->get('targetTimeSeconds') : null;
        $entry = $this->entityManager->getNewEntity('OpportunityStageHistory');
        $entry->set([
            'id' => Util::generateId(),
            'opportunityId' => $opportunity->get('id'),
            'stageId' => $opportunity->get('opportunityStageId'),
            'funnelId' => $opportunity->get('funnelId'),
            'stageName' => $stage?->get('name') ?? $opportunity->get('opportunityStageName'),
            'funnelName' => $funnel?->get('name'),
            'enteredAt' => $now,
            'exitedAt' => $open ? null : $now,
            'durationSeconds' => $open ? null : 0,
            'targetTimeSeconds' => $target,
            'dueAt' => $target === null ? null :
                (new DateTimeImmutable($now, new DateTimeZone('UTC')))->modify("+$target seconds")->format('Y-m-d H:i:s'),
            'isPartial' => $partial,
            'kind' => $open ? 'Visit' : $opportunity->get('status'),
        ]);
        return $entry;
    }

    public static function secondsBetween(string $start, string $end): int
    {
        $tz = new DateTimeZone('UTC');
        return max(0, (new DateTimeImmutable($end, $tz))->getTimestamp() -
            (new DateTimeImmutable($start, $tz))->getTimestamp());
    }
}
