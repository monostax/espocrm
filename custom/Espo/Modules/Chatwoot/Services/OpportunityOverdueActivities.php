<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use DateTimeZone;
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
        foreach (['Task', 'Meeting', 'Call'] as $type) {
            $dueWhere = ['dateEnd>=' => $startedAt, 'dateEnd<' => $now->format('Y-m-d H:i:s')];
            if ($type === 'Task') {
                $dueWhere = ['OR' => [
                    ['dateEndDate' => null] + $dueWhere,
                    [
                        'dateEndDate>=' => (new DateTimeImmutable($startedAt))->modify('-1 day')->format('Y-m-d'),
                        'dateEndDate<=' => $now->modify('+1 day')->format('Y-m-d'),
                    ],
                ]];
            }
            $afterId = '';
            do {
                $activities = $this->entityManager->getRDBRepository($type)->where([
                    'parentType' => 'Opportunity', 'parentId!=' => null, 'id>' => $afterId,
                ])->where($this->pendingWhere($type))->where($dueWhere)->order('id')->limit(0, 200)->find();
                foreach ($activities as $activity) {
                    $afterId = $activity->getId();
                    try {
                        $this->record($activity, $now, $startedAt);
                    } catch (Throwable $e) {
                        // Retry this activity on the next tick without blocking the other deadlines.
                        $this->log->error("Opportunity overdue event failed for {$type}/{$afterId}: {$e->getMessage()}");
                    }
                }
            } while (count($activities) === 200);
        }
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

    private function record(Entity $candidate, DateTimeImmutable $now, string $startedAt): void
    {
        $this->entityManager->getTransactionManager()->run(function () use ($candidate, $now, $startedAt): void {
            // A completion or reschedule can race the scan. Recheck under the activity lock.
            $activity = $this->entityManager->getRDBRepository($candidate->getEntityType())
                ->where(['id' => $candidate->getId(), 'parentType' => 'Opportunity'])
                ->where($this->pendingWhere($candidate->getEntityType()))->forUpdate()->findOne();
            if (!$activity || !$activity->get('parentId')) {
                return;
            }
            $opportunity = $this->entityManager->getEntityById('Opportunity', $activity->get('parentId'));
            $tenantId = $opportunity?->get('tenantId');
            if (!$tenantId || ($activity->get('tenantId') && $activity->get('tenantId') !== $tenantId)) {
                return;
            }
            if (!isset($this->timeZones[$tenantId])) {
                $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
                $this->timeZones[$tenantId] = $tenant?->get('timeZone') ?: ($this->config->get('timeZone') ?: 'UTC');
            }
            $timeZone = $this->timeZones[$tenantId];
            $dueAt = $this->deadline($activity, $timeZone);
            if (!$dueAt || $dueAt < $startedAt || $dueAt >= $now->format('Y-m-d H:i:s')) {
                return;
            }

            $key = hash('sha256', implode(':', [
                OpportunityStreamEvents::ACTIVITY_OVERDUE, $opportunity->getId(),
                $activity->getEntityType(), $activity->getId(), $dueAt,
            ]));
            $this->events->write(OpportunityStreamEvents::ACTIVITY_OVERDUE, $opportunity, $activity, [
                'activityName' => $activity->get('name'),
                'dueAt' => $dueAt,
                'dueDate' => $activity->get('dateEndDate'),
                'timeZone' => $timeZone,
                'occurredAt' => $dueAt,
            ], $key, $activity->getLinkMultipleIdList('teams'));
        });
    }
}
