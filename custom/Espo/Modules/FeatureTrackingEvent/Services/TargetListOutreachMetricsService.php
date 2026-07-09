<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

use DateTimeImmutable;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEvent;
use Espo\Modules\FeatureTrackingEvent\Hooks\Opportunity\TrackStageChange;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * Aggregates outbound-batch FLOW metrics for a TargetList from the
 * TrackingEvent ledger: per-stage funnel (distinct opportunities that
 * entered each stage, average time to reach it) plus won/lost outcomes.
 *
 * Data model: opportunities are attributed to the batch via the
 * sourceTargetList link (stamped at creation, Global module); their funnel
 * motion is recorded by Hooks\Opportunity\TrackStageChange as
 * opportunity_stage_changed / opportunity_won / opportunity_lost events with
 * parent=Opportunity. This service joins the two: batch -> opportunity ids
 * (indexed FK on the opportunity table) -> ledger events by parentId. No
 * JSON-path SQL is needed; payload decoding and aggregation happen in PHP.
 *
 * ACL / tenancy: the opportunity id set is built through SelectBuilderFactory
 * with strict access control FOR THE GIVEN USER (same team/tenant filters as
 * any Opportunity list view). Ledger rows are only read via those accessible
 * parent ids, so tenant isolation holds transitively and no raw TrackingEvent
 * data is exposed beyond the user's own funnel motion. No Opportunity read
 * access -> empty metrics.
 *
 * STATE metrics (current counts/amounts) intentionally live on the
 * TargetList record itself — see Global's OpportunityMetricsLoader.
 *
 * Complements the ledger's design: events are immutable history; this is a
 * read-model computed on demand for the TargetList detail panel.
 */
class TargetListOutreachMetricsService
{
    private const OPPORTUNITY_LIMIT = 20000;
    private const EVENT_CHUNK_SIZE = 1000;

    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
    ) {}

    public function build(string $targetListId, User $user): stdClass
    {
        $opportunityCreatedAtMap = $this->fetchOpportunityCreatedAtMap($targetListId, $user);

        $opportunityCount = count($opportunityCreatedAtMap);

        if ($opportunityCount === 0) {
            return (object) [
                'opportunityCount' => 0,
                'trackedOpportunityCount' => 0,
                'stages' => [],
                'wonCount' => 0,
                'wonValue' => 0.0,
                'avgHoursToWon' => null,
                'lostCount' => 0,
            ];
        }

        $aggregate = $this->aggregateEvents($opportunityCreatedAtMap);

        return (object) [
            'opportunityCount' => $opportunityCount,
            'trackedOpportunityCount' => count($aggregate['trackedOppIds']),
            'stages' => $this->buildStageList($aggregate, $opportunityCreatedAtMap, $opportunityCount),
            'wonCount' => count($aggregate['wonAt']),
            'wonValue' => $aggregate['wonValue'],
            'avgHoursToWon' => $this->averageHours($aggregate['wonAt'], $opportunityCreatedAtMap),
            'lostCount' => count($aggregate['lostOppIds']),
        ];
    }

    /**
     * ACL-filtered (team/tenant) id => createdAt map of the batch's
     * opportunities, for the given user.
     *
     * @return array<string, DateTimeImmutable> opportunityId => createdAt
     */
    private function fetchOpportunityCreatedAtMap(string $targetListId, User $user): array
    {
        try {
            $query = $this->selectBuilderFactory
                ->create()
                ->from('Opportunity')
                ->forUser($user)
                ->withStrictAccessControl()
                ->buildQueryBuilder()
                ->select(['id', 'createdAt'])
                ->where(['sourceTargetListId' => $targetListId])
                ->limit(0, self::OPPORTUNITY_LIMIT)
                ->build();
        } catch (Forbidden|BadRequest) {
            // No Opportunity read access for the user.
            return [];
        }

        $collection = $this->entityManager
            ->getRDBRepository('Opportunity')
            ->clone($query)
            ->find();

        $map = [];

        foreach ($collection as $opportunity) {
            $createdAt = $opportunity->get('createdAt');

            if (!is_string($createdAt) || $createdAt === '') {
                continue;
            }

            try {
                $map[$opportunity->getId()] = new DateTimeImmutable($createdAt);
            } catch (Throwable) {
                continue;
            }
        }

        return $map;
    }

    /**
     * Single pass over the batch's ledger events.
     *
     * @param array<string, DateTimeImmutable> $opportunityCreatedAtMap
     * @return array{
     *     stages: array<string, array{name: ?string, enteredAt: array<string, DateTimeImmutable>}>,
     *     trackedOppIds: array<string, true>,
     *     wonAt: array<string, DateTimeImmutable>,
     *     wonValue: float,
     *     lostOppIds: array<string, true>,
     * }
     */
    private function aggregateEvents(array $opportunityCreatedAtMap): array
    {
        $stages = [];
        $trackedOppIds = [];
        $wonAt = [];
        $wonValue = 0.0;
        $lostOppIds = [];

        $codeList = [
            TrackStageChange::CODE_STAGE_CHANGED,
            TrackStageChange::CODE_WON,
            TrackStageChange::CODE_LOST,
        ];

        foreach (array_chunk(array_keys($opportunityCreatedAtMap), self::EVENT_CHUNK_SIZE) as $idChunk) {
            $events = $this->entityManager
                ->getRDBRepository(TrackingEvent::ENTITY_TYPE)
                ->select(['id', 'parentId', 'code', 'occurredAt', 'payload', 'value'])
                ->where([
                    'parentType' => 'Opportunity',
                    'parentId' => $idChunk,
                    'code' => $codeList,
                ])
                ->order('occurredAt')
                ->find();

            foreach ($events as $event) {
                $oppId = $event->get('parentId');
                $code = $event->get('code');
                $occurredAt = $this->toDateTime($event->get('occurredAt'));

                if (!is_string($oppId) || $oppId === '' || $occurredAt === null) {
                    continue;
                }

                $trackedOppIds[$oppId] = true;

                if ($code === TrackStageChange::CODE_WON) {
                    if (!isset($wonAt[$oppId])) {
                        $wonAt[$oppId] = $occurredAt;
                        $wonValue += (float) ($event->get('value') ?? 0.0);
                    }

                    continue;
                }

                if ($code === TrackStageChange::CODE_LOST) {
                    $lostOppIds[$oppId] = true;

                    continue;
                }

                $payload = $event->get('payload');

                $stageId = is_object($payload) ? ($payload->toStageId ?? null) : null;

                if (!is_string($stageId) || $stageId === '') {
                    continue;
                }

                if (!isset($stages[$stageId])) {
                    $stages[$stageId] = [
                        'name' => is_object($payload) && is_string($payload->toStageName ?? null)
                            ? $payload->toStageName
                            : null,
                        'enteredAt' => [],
                    ];
                }

                // Events are ordered by occurredAt: the first hit is the
                // earliest entry into the stage for this opportunity.
                if (!isset($stages[$stageId]['enteredAt'][$oppId])) {
                    $stages[$stageId]['enteredAt'][$oppId] = $occurredAt;
                }
            }
        }

        return [
            'stages' => $stages,
            'trackedOppIds' => $trackedOppIds,
            'wonAt' => $wonAt,
            'wonValue' => $wonValue,
            'lostOppIds' => $lostOppIds,
        ];
    }

    /**
     * Orders stages by the OpportunityStage `order` field (funnel position);
     * stages whose row no longer exists go last, alphabetically.
     *
     * @param array{stages: array<string, array{name: ?string, enteredAt: array<string, DateTimeImmutable>}>} $aggregate
     * @param array<string, DateTimeImmutable> $opportunityCreatedAtMap
     * @return list<stdClass>
     */
    private function buildStageList(array $aggregate, array $opportunityCreatedAtMap, int $opportunityCount): array
    {
        $stageIds = array_keys($aggregate['stages']);

        if ($stageIds === []) {
            return [];
        }

        $orderMap = [];
        $nameMap = [];

        $stageCollection = $this->entityManager
            ->getRDBRepository('OpportunityStage')
            ->select(['id', 'name', 'order'])
            ->where(['id' => $stageIds])
            ->find();

        foreach ($stageCollection as $stage) {
            $orderMap[$stage->getId()] = (int) ($stage->get('order') ?? 0);
            $nameMap[$stage->getId()] = $stage->get('name');
        }

        $list = [];

        foreach ($aggregate['stages'] as $stageId => $item) {
            $entered = count($item['enteredAt']);

            $list[] = (object) [
                'stageId' => $stageId,
                'stageName' => $nameMap[$stageId] ?? $item['name'] ?? $stageId,
                'entered' => $entered,
                'enteredPct' => $opportunityCount > 0
                    ? round($entered * 100 / $opportunityCount, 1)
                    : 0.0,
                'avgHoursToReach' => $this->averageHours($item['enteredAt'], $opportunityCreatedAtMap),
                'order' => $orderMap[$stageId] ?? PHP_INT_MAX,
            ];
        }

        usort($list, static function (stdClass $a, stdClass $b): int {
            return [$a->order, (string) $a->stageName] <=> [$b->order, (string) $b->stageName];
        });

        foreach ($list as $item) {
            unset($item->order);
        }

        return $list;
    }

    /**
     * Average hours between each opportunity's creation and the given
     * per-opportunity timestamps. Null when nothing is measurable.
     *
     * @param array<string, DateTimeImmutable> $timestampByOppId
     * @param array<string, DateTimeImmutable> $opportunityCreatedAtMap
     */
    private function averageHours(array $timestampByOppId, array $opportunityCreatedAtMap): ?float
    {
        $totalSeconds = 0;
        $count = 0;

        foreach ($timestampByOppId as $oppId => $timestamp) {
            $createdAt = $opportunityCreatedAtMap[$oppId] ?? null;

            if ($createdAt === null) {
                continue;
            }

            $delta = $timestamp->getTimestamp() - $createdAt->getTimestamp();

            if ($delta < 0) {
                $delta = 0;
            }

            $totalSeconds += $delta;
            $count++;
        }

        if ($count === 0) {
            return null;
        }

        return round($totalSeconds / $count / 3600, 1);
    }

    private function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
