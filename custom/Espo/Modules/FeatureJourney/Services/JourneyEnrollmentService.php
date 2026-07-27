<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Entities\JourneyRecordLog;
use Espo\Modules\FeatureJourney\Entities\JourneyStage;
use Espo\Modules\FeatureJourney\Entities\JourneyStageAction;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Throwable;

class JourneyEnrollmentService
{
    private const SKIP_OPT = 'skipJourneyDispatch';

    public function __construct(
        private EntityManager $entityManager,
        private JourneyLifecycleEmitter $lifecycleEmitter,
        private JourneyRateLimiter $rateLimiter,
        private TenantGuard $tenantGuard,
        private ActionRunner $actionRunner,
        private JourneyRunIdentity $identity,
        private Log $log,
    ) {}

    /**
     * @return list<array{targetType: string, targetId: string}>
     */
    public function resolveAudience(Entity $journey): array
    {
        return $this->resolveAudienceDetailed($journey)['audience'];
    }

    /**
     * Audience resolution with include/exclude breakdown for Review & Publish.
     *
     * @return array{
     *     audience: list<array{targetType: string, targetId: string}>,
     *     targetEntityType: string,
     *     includeListCount: int,
     *     includeListsUsed: int,
     *     manualCount: int,
     *     excludedCount: int,
     *     excludeListsUsed: int,
     *     beforeExcludeCount: int
     * }
     */
    public function resolveAudienceDetailed(Entity $journey): array
    {
        $targetType = (string) $journey->get('targetEntityType');
        $seen = [];
        $audience = [];
        $includeListIds = [];
        $manualCount = 0;

        $relationName = match ($targetType) {
            'Contact' => 'contacts',
            'Account' => 'accounts',
            'Lead' => 'leads',
            default => 'contacts',
        };

        $targetLists = $this->entityManager
            ->getRDBRepository(Journey::ENTITY_TYPE)
            ->getRelation($journey, 'targetLists')
            ->find();

        $journeyTenantId = $journey->get('tenantId') ? (string) $journey->get('tenantId') : null;

        foreach ($targetLists as $targetList) {
            if ($journeyTenantId && !$this->tenantGuard->entityBelongsToTenant($targetList, $journeyTenantId)) {
                $this->log->warning(
                    'JourneyEnrollmentService: skip foreign targetList ' . $targetList->getId()
                );

                continue;
            }

            $includeListIds[$targetList->getId()] = true;

            $members = $this->entityManager
                ->getRDBRepository('TargetList')
                ->getRelation($targetList, $relationName)
                ->where(['@relation.optedOut' => false])
                ->find();

            foreach ($members as $member) {
                if ($journeyTenantId && !$this->tenantGuard->entityBelongsToTenant($member, $journeyTenantId)) {
                    continue;
                }

                $key = $targetType . ':' . $member->getId();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $audience[] = ['targetType' => $targetType, 'targetId' => $member->getId()];
            }
        }

        $includeListMemberCount = count($audience);

        if ($targetType === 'Contact') {
            $manual = $this->entityManager
                ->getRDBRepository(Journey::ENTITY_TYPE)
                ->getRelation($journey, 'manualContacts')
                ->find();

            foreach ($manual as $contact) {
                if ($journeyTenantId && !$this->tenantGuard->entityBelongsToTenant($contact, $journeyTenantId)) {
                    continue;
                }

                $key = 'Contact:' . $contact->getId();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $manualCount++;
                $audience[] = ['targetType' => 'Contact', 'targetId' => $contact->getId()];
            }
        }

        $beforeExcludeCount = count($audience);

        $excludeLists = $this->entityManager
            ->getRDBRepository(Journey::ENTITY_TYPE)
            ->getRelation($journey, 'excludeTargetLists')
            ->find();

        $excluded = [];
        $excludeListIds = [];
        foreach ($excludeLists as $targetList) {
            $excludeListIds[$targetList->getId()] = true;
            $members = $this->entityManager
                ->getRDBRepository('TargetList')
                ->getRelation($targetList, $relationName)
                ->find();

            foreach ($members as $member) {
                $excluded[$targetType . ':' . $member->getId()] = true;
            }
        }

        $excludedCount = 0;
        if ($excluded !== []) {
            $filtered = [];
            foreach ($audience as $row) {
                $key = $row['targetType'] . ':' . $row['targetId'];
                if (isset($excluded[$key])) {
                    $excludedCount++;
                    continue;
                }
                $filtered[] = $row;
            }
            $audience = $filtered;
        }

        return [
            'audience' => $audience,
            'targetEntityType' => $targetType,
            'includeListCount' => $includeListMemberCount,
            'includeListsUsed' => count($includeListIds),
            'manualCount' => $manualCount,
            'excludedCount' => $excludedCount,
            'excludeListsUsed' => count($excludeListIds),
            'beforeExcludeCount' => $beforeExcludeCount,
        ];
    }

    /**
     * @return int Number newly enrolled
     */
    public function enrollAudience(string $journeyId): int
    {
        return (int) ($this->enrollAudienceDetailed($journeyId)['enrolled'] ?? 0);
    }

    /**
     * Enroll audience and return structured counts (for activate / Review & Publish).
     *
     * @return array{
     *     enrolled: int,
     *     skippedCount: int,
     *     failedCount: int,
     *     skipped: list<array{targetType: string, targetId: string, reason: string}>,
     *     failed: list<array{targetType: string, targetId: string, reason: string, error?: string}>
     * }
     */
    public function enrollAudienceDetailed(string $journeyId, int $detailLimit = 50): array
    {
        $journey = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, $journeyId);

        if (!$journey) {
            return [
                'enrolled' => 0,
                'skippedCount' => 0,
                'failedCount' => 0,
                'skipped' => [],
                'failed' => [],
            ];
        }

        $actor = $this->identity->resolve($journey, 'enroll');

        $audience = $this->resolveAudience($journey);
        $enrolled = 0;
        $skipped = [];
        $failed = [];
        $skippedCount = 0;
        $failedCount = 0;

        $skipReasons = [
            'rate_limited',
            'missing_target',
            'tenant_rejected',
            'already_active',
            're_enrollment_blocked',
            'no_entry_stage',
            'duplicate_race',
        ];

        foreach ($audience as $row) {
            try {
                $result = $this->tryEnrollOne($journey, $row['targetType'], $row['targetId'], $actor);
            } catch (Throwable $e) {
                $this->log->error(
                    "JourneyEnrollmentService: enroll failed journey={$journeyId} " .
                    "{$row['targetType']}:{$row['targetId']}: " . $e->getMessage()
                );
                $failedCount++;
                if (count($failed) < $detailLimit) {
                    $failed[] = [
                        'targetType' => $row['targetType'],
                        'targetId' => $row['targetId'],
                        'reason' => 'exception',
                        'error' => $e->getMessage(),
                    ];
                }
                continue;
            }

            if ($result['ok']) {
                $enrolled++;
                continue;
            }

            $reason = (string) ($result['reason'] ?? 'unknown');
            if ($reason === 'on_enter_failed' || !in_array($reason, $skipReasons, true)) {
                $failedCount++;
                if (count($failed) < $detailLimit) {
                    $item = [
                        'targetType' => $row['targetType'],
                        'targetId' => $row['targetId'],
                        'reason' => $reason !== '' ? $reason : 'unknown',
                    ];
                    if (!empty($result['error'])) {
                        $item['error'] = (string) $result['error'];
                    }
                    $failed[] = $item;
                }
                continue;
            }

            $skippedCount++;
            if (count($skipped) < $detailLimit) {
                $skipped[] = [
                    'targetType' => $row['targetType'],
                    'targetId' => $row['targetId'],
                    'reason' => $reason,
                ];
            }
        }

        return [
            'enrolled' => $enrolled,
            'skippedCount' => $skippedCount,
            'failedCount' => $failedCount,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    public function enrollOne(Entity $journey, string $targetType, string $targetId): bool
    {
        return $this->tryEnrollOne($journey, $targetType, $targetId)['ok'];
    }

    /**
     * @return array{ok: bool, reason?: string, error?: string}
     */
    public function tryEnrollOne(
        Entity $journey,
        string $targetType,
        string $targetId,
        ?User $actor = null,
    ): array {
        $tenantId = $journey->get('tenantId');
        if ($tenantId && !$this->rateLimiter->allowEnrollments((string) $tenantId)) {
            $this->log->warning("JourneyEnrollmentService: enrollment rate limit tenant={$tenantId}");

            return ['ok' => false, 'reason' => 'rate_limited'];
        }

        $actor ??= $this->identity->resolve($journey, 'enroll');

        $target = $this->entityManager->getEntityById($targetType, $targetId);
        if (!$target) {
            return ['ok' => false, 'reason' => 'missing_target'];
        }

        try {
            $this->tenantGuard->assertTargetBelongsToJourney($target, $journey);
        } catch (Throwable $e) {
            $this->log->warning(
                "JourneyEnrollmentService: tenant reject {$targetType}:{$targetId}: " . $e->getMessage()
            );

            return ['ok' => false, 'reason' => 'tenant_rejected', 'error' => $e->getMessage()];
        }

        $active = $this->entityManager
            ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
            ->where([
                'journeyId' => $journey->getId(),
                'targetType' => $targetType,
                'targetId' => $targetId,
                'status' => [JourneyRecord::STATUS_ACTIVE, JourneyRecord::STATUS_PAUSED, JourneyRecord::STATUS_PROCESSING],
            ])
            ->findOne();

        if ($active) {
            return ['ok' => false, 'reason' => 'already_active'];
        }

        $maxCycle = $this->entityManager
            ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
            ->where([
                'journeyId' => $journey->getId(),
                'targetType' => $targetType,
                'targetId' => $targetId,
            ])
            ->order('cycleCount', 'DESC')
            ->findOne();

        $nextCycle = $maxCycle ? ((int) $maxCycle->get('cycleCount') + 1) : 0;

        if ($nextCycle > 0 && !$journey->get('allowReEnrollment')) {
            return ['ok' => false, 'reason' => 're_enrollment_blocked'];
        }

        $entryStage = $this->entityManager
            ->getRDBRepository(JourneyStage::ENTITY_TYPE)
            ->where([
                'journeyId' => $journey->getId(),
                'stageType' => JourneyStage::TYPE_ENTRY,
                'isActive' => true,
            ])
            ->order('order', 'ASC')
            ->findOne();

        if (!$entryStage) {
            $this->log->warning("JourneyEnrollmentService: no Entry stage for journey {$journey->getId()}");

            return ['ok' => false, 'reason' => 'no_entry_stage'];
        }

        $teamsIds = [];
        try {
            $teamsIds = $journey->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $teamsIds = $journey->get('teamsIds') ? (array) $journey->get('teamsIds') : [];
        }

        $now = date('Y-m-d H:i:s');
        $targetName = null;
        if ($this->entityManager->hasRepository($targetType)) {
            $targetEntity = $this->entityManager->getEntityById($targetType, $targetId);
            if ($targetEntity) {
                $targetName = $targetEntity->get('name');
            }
        }
        if (!is_string($targetName) || trim($targetName) === '') {
            $targetName = $targetType . ' ' . $targetId;
        }

        $record = $this->entityManager->getNewEntity(JourneyRecord::ENTITY_TYPE);
        $record->set([
            'journeyId' => $journey->getId(),
            'targetType' => $targetType,
            'targetId' => $targetId,
            'targetName' => $targetName,
            'name' => $targetName,
            'currentStageId' => $entryStage->getId(),
            'status' => JourneyRecord::STATUS_ACTIVE,
            'enteredStageAt' => $now,
            'cycleCount' => $nextCycle,
            'retryCount' => 0,
            'tenantId' => $journey->get('tenantId'),
            'teamsIds' => $teamsIds,
            'runAsUserId' => $actor->getId(),
            'deleteId' => '0',
        ]);

        try {
            $this->entityManager->saveEntity($record, [
                SaveOption::SILENT => true,
                self::SKIP_OPT => true,
                SaveOption::CREATED_BY_ID => $actor->getId(),
            ]);
        } catch (Throwable $e) {
            // unique index race backstop
            $this->log->info(
                "JourneyEnrollmentService: duplicate-key race journey={$journey->getId()} " .
                "{$targetType}:{$targetId}: " . $e->getMessage()
            );

            return ['ok' => false, 'reason' => 'duplicate_race', 'error' => $e->getMessage()];
        }

        $log = $this->entityManager->getNewEntity(JourneyRecordLog::ENTITY_TYPE);
        $log->set([
            'recordId' => $record->getId(),
            'journeyId' => $journey->getId(),
            'fromStageId' => null,
            'toStageId' => $entryStage->getId(),
            'transitionId' => null,
            'firedBy' => JourneyRecordLog::FIRED_BY_SYSTEM,
            'tenantId' => $journey->get('tenantId'),
            'teamsIds' => $teamsIds,
        ]);
        $this->entityManager->saveEntity($log, [
            SaveOption::SILENT => true,
            self::SKIP_OPT => true,
        ]);

        $j = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, (string) $journey->getId());
        if ($j) {
            $j->set('activeCount', (int) $j->get('activeCount') + 1);
            $this->entityManager->saveEntity($j, [
                SaveOption::SILENT => true,
                self::SKIP_OPT => true,
            ]);
        }

        $tid = (string) ($journey->get('tenantId') ?: '');
        if ($tid !== '') {
            $this->lifecycleEmitter->emit($tid, JourneyLifecycleEmitter::CODE_ENROLLED, [
                'contactId' => $targetType === 'Contact' ? $targetId : null,
                'parentType' => $targetType,
                'parentId' => $targetId,
                'properties' => [
                    'journeyId' => $journey->getId(),
                    'recordId' => $record->getId(),
                    'stageId' => $entryStage->getId(),
                    'cycleCount' => $nextCycle,
                    'runAsUserId' => $actor->getId(),
                ],
                'detail' => $journey->get('name'),
            ]);
        }

        // Entry-stage OnEnter actions (welcome email, etc.) — not run via TransitionExecutor.
        $enterResult = $this->actionRunner->runForStage(
            $entryStage,
            JourneyStageAction::TRIGGER_ON_ENTER,
            $target,
            $record,
            $journey,
            $actor,
        );

        if (!$enterResult['ok']) {
            $error = (string) ($enterResult['error'] ?? 'on_enter_failed');
            $this->log->error(
                "JourneyEnrollmentService: OnEnter failed journey={$journey->getId()} " .
                "record={$record->getId()}: {$error}"
            );
            $record->set([
                'status' => JourneyRecord::STATUS_FAILED,
                'exitReason' => substr($error, 0, 100),
                'claimedAt' => null,
            ]);
            $this->entityManager->saveEntity($record, [
                SaveOption::SILENT => true,
                self::SKIP_OPT => true,
            ]);

            $j = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, (string) $journey->getId());
            if ($j) {
                $j->set('activeCount', max(0, (int) $j->get('activeCount') - 1));
                $this->entityManager->saveEntity($j, [
                    SaveOption::SILENT => true,
                    self::SKIP_OPT => true,
                ]);
            }

            return ['ok' => false, 'reason' => 'on_enter_failed', 'error' => $error];
        }

        return ['ok' => true];
    }

    /**
     * Manually remove a person from the journey (status → Exited). Keeps history/logs.
     * Does not run stage OnExit actions (explicit operator pull-out, same as SLA exit).
     *
     * Allowed from Active, Processing, or Paused only.
     *
     * @throws NotFound
     * @throws BadRequest
     */
    public function exitRecord(
        string $recordId,
        ?string $actorUserId = null,
        string $reason = 'manual',
    ): Entity {
        $record = $this->entityManager->getEntityById(JourneyRecord::ENTITY_TYPE, $recordId);

        if (!$record) {
            throw new NotFound("JourneyRecord {$recordId} not found.");
        }

        $status = (string) $record->get('status');
        $exitable = [
            JourneyRecord::STATUS_ACTIVE,
            JourneyRecord::STATUS_PROCESSING,
            JourneyRecord::STATUS_PAUSED,
        ];

        if (!in_array($status, $exitable, true)) {
            throw new BadRequest("Cannot exit JourneyRecord in status {$status}.");
        }

        $exitReason = substr(trim($reason) !== '' ? trim($reason) : 'manual', 0, 100);
        $journeyId = (string) ($record->get('journeyId') ?? '');
        $journey = $journeyId !== ''
            ? $this->entityManager->getEntityById(Journey::ENTITY_TYPE, $journeyId)
            : null;

        $fromStageId = $record->get('currentStageId')
            ? (string) $record->get('currentStageId')
            : null;

        $record->set([
            'status' => JourneyRecord::STATUS_EXITED,
            'claimedAt' => null,
            'exitReason' => $exitReason,
        ]);
        $this->entityManager->saveEntity($record, [
            SaveOption::SILENT => true,
            self::SKIP_OPT => true,
        ]);

        $log = $this->entityManager->getNewEntity(JourneyRecordLog::ENTITY_TYPE);
        $log->set([
            'recordId' => $record->getId(),
            'journeyId' => $journeyId !== '' ? $journeyId : null,
            'fromStageId' => $fromStageId,
            'toStageId' => null,
            'transitionId' => null,
            'firedBy' => JourneyRecordLog::FIRED_BY_USER,
            'eventPayload' => [
                'action' => 'exit',
                'reason' => $exitReason,
                'previousStatus' => $status,
            ],
            'actorId' => $actorUserId ?: null,
            'tenantId' => $record->get('tenantId') ?: ($journey?->get('tenantId')),
            'teamsIds' => $journey?->get('teamsIds') ?: [],
        ]);
        $this->entityManager->saveEntity($log, [
            SaveOption::SILENT => true,
            self::SKIP_OPT => true,
        ]);

        if ($journey) {
            $journey->set('activeCount', max(0, (int) $journey->get('activeCount') - 1));
            $journey->set('exitedCount', (int) $journey->get('exitedCount') + 1);
            $this->entityManager->saveEntity($journey, [
                SaveOption::SILENT => true,
                self::SKIP_OPT => true,
            ]);
        }

        $tenantId = (string) ($record->get('tenantId') ?: $journey?->get('tenantId') ?: '');

        if ($tenantId !== '') {
            $this->lifecycleEmitter->emit($tenantId, JourneyLifecycleEmitter::CODE_EXITED, [
                'contactId' => $record->get('targetType') === 'Contact' ? $record->get('targetId') : null,
                'parentType' => $record->get('targetType'),
                'parentId' => $record->get('targetId'),
                'properties' => [
                    'journeyId' => $journeyId !== '' ? $journeyId : null,
                    'recordId' => $record->getId(),
                    'reason' => $exitReason,
                    'previousStatus' => $status,
                    'fromStageId' => $fromStageId,
                    'actorUserId' => $actorUserId,
                ],
                'detail' => $journey?->get('name'),
            ]);
        }

        $fresh = $this->entityManager->getEntityById(JourneyRecord::ENTITY_TYPE, $recordId);

        return $fresh ?? $record;
    }
}
