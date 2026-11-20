<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

class OpportunityOverdueActivities
{
    public const STARTED_AT = 'opportunityOverdueEventsStartedAt';
    private array $timeZones = [];

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Metadata $metadata,
        private OpportunityStreamEvents $events,
        private Log $log,
    ) {}

    public function process(?DateTimeImmutable $now = null): void
    {
        $startedAt = $this->config->get(self::STARTED_AT);
        if (!$startedAt) {
            return;
        }
        $now = ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
        $starts = ['Opportunity' => $startedAt] + array_filter(array_map(
            fn ($setting) => $this->config->get($setting), OpportunityStreamEvents::DISCUSSION_ROLLOUT,
        ));
        foreach (['Task', 'Meeting', 'Call'] as $type) {
            $dueWhere = [];
            foreach ($starts as $parentType => $since) $dueWhere[] = ['parentType' => $parentType] + $this->dueWhere($type, $now, $since);
            $afterId = '';
            do {
                $activities = $this->entityManager->getRDBRepository($type)->where([
                    'parentId!=' => null, 'id>' => $afterId, 'OR' => $dueWhere,
                ])->where($this->pendingWhere($type))->order('id')->limit(0, 200)->find();
                foreach ($activities as $activity) {
                    $afterId = $activity->getId();
                    try {
                        $this->record($activity, $now, $starts[$activity->get('parentType')]);
                    } catch (Throwable $e) {
                        // Retry this activity on the next tick without blocking the other deadlines.
                        $this->log->error("Overdue event failed for {$type}/{$afterId}: {$e->getMessage()}");
                    }
                }
            } while (count($activities) === 200);
        }
    }

    /** Include newly created, already-overdue activities without replaying the pre-rollout backlog. */
    private function dueWhere(string $type, DateTimeImmutable $now, string $startedAt): array
    {
        $dueWhere = [
            'dateEnd<' => $now->format('Y-m-d H:i:s'),
            'OR' => [['dateEnd>=' => $startedAt], ['createdAt>=' => $startedAt]],
        ];
        if ($type !== 'Task') {
            return $dueWhere;
        }
        // Date-only candidates are deliberately broad; record() checks the tenant's exact midnight.
        return ['OR' => [
            ['dateEndDate' => null] + $dueWhere,
            [
                'dateEndDate<=' => $now->modify('+1 day')->format('Y-m-d'),
                'OR' => [
                    ['dateEndDate>=' => (new DateTimeImmutable($startedAt, new DateTimeZone('UTC')))
                        ->modify('-1 day')->format('Y-m-d')],
                    ['createdAt>=' => $startedAt],
                ],
            ],
        ]];
    }

    /** A shared event needs a shared calendar: tenant timezone, then instance timezone. */
    public function deadline(Entity $activity, string $timeZone): ?string
    {
        if ($activity->getEntityType() === 'Task' && $activity->get('dateEndDate')) {
            return (new DateTimeImmutable($activity->get('dateEndDate'), new DateTimeZone($timeZone)))
                ->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        return $activity->get('dateEnd');
    }

    private function pendingWhere(string $type): array
    {
        return $type === 'Task'
            ? ['status!=' => $this->metadata->get(['entityDefs', 'Task', 'fields', 'status', 'notActualOptions']) ?? []]
            : ['status' => $this->metadata->get(['scopes', $type, 'activityStatusList']) ?? []];
    }

    /** The Account Mural doesn't load the Opportunity board's pending-activity list. */
    public function decorateAccountPage(array $entries, string $accountId, Acl $acl): void
    {
        $byType = [];
        foreach ($entries as $entry) {
            if ($entry->type === OpportunityStreamEvents::ACTIVITY_OVERDUE) $byType[$entry->relatedType][] = $entry;
        }
        foreach ($byType as $type => $notes) {
            if (!in_array($type, ['Task', 'Meeting', 'Call'], true) || !$acl->checkField($type, 'status')) continue;
            $pending = $this->entityManager->getRDBRepository($type)->select(['id'])->where([
                'id' => array_column($notes, 'relatedId'), 'parentType' => 'Account', 'parentId' => $accountId,
            ])->where($this->pendingWhere($type))->find();
            $ids = [];
            foreach ($pending as $activity) $ids[$activity->getId()] = true;
            foreach ($notes as $note) $note->activityResolved = !isset($ids[$note->relatedId]);
        }
    }

    private function record(Entity $candidate, DateTimeImmutable $now, string $startedAt): void
    {
        $this->entityManager->getTransactionManager()->run(function () use ($candidate, $now, $startedAt): void {
            // A completion or reschedule can race the scan. Recheck under the activity lock.
            $parentType = $candidate->get('parentType');
            $activity = $this->entityManager->getRDBRepository($candidate->getEntityType())
                ->where(['id' => $candidate->getId(), 'parentType' => $parentType])
                ->where($this->pendingWhere($candidate->getEntityType()))->forUpdate()->findOne();
            if (!$activity || !$activity->get('parentId')) {
                return;
            }
            $parent = $this->entityManager->getEntityById($parentType, $activity->get('parentId'));
            $tenantId = $parent?->get('tenantId');
            if (!$tenantId || ($activity->get('tenantId') && $activity->get('tenantId') !== $tenantId)) {
                return;
            }
            if (!isset($this->timeZones[$tenantId])) {
                $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
                $this->timeZones[$tenantId] = $tenant?->get('timeZone') ?: ($this->config->get('timeZone') ?: 'UTC');
            }
            $timeZone = $this->timeZones[$tenantId];
            $dueAt = $this->deadline($activity, $timeZone);
            if (!$dueAt || $dueAt >= $now->format('Y-m-d H:i:s')) {
                return;
            }
            // A newly created activity with a past deadline becomes overdue when it is created,
            // not before it existed. Keep the original deadline in the data and deduplication key.
            $occurredAt = max($dueAt, $activity->get('createdAt') ?? $dueAt);
            if ($occurredAt < $startedAt) {
                return;
            }

            $key = hash('sha256', implode(':', [
                OpportunityStreamEvents::ACTIVITY_OVERDUE, ($parentType === 'Opportunity' ? '' : "$parentType:") . $parent->getId(),
                $activity->getEntityType(), $activity->getId(), $dueAt,
            ]));
            $this->events->write(OpportunityStreamEvents::ACTIVITY_OVERDUE, $parent, $activity, [
                'activityName' => $activity->get('name'),
                'dueAt' => $dueAt,
                'dueDate' => $activity->get('dateEndDate'),
                'timeZone' => $timeZone,
                'occurredAt' => $occurredAt,
            ], $key, $activity->getLinkMultipleIdList('teams'));
        });
    }
}
