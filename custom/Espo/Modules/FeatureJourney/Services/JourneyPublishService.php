<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureJourney\Entities\Journey as JourneyEntity;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Entities\JourneyStage;
use Espo\Modules\FeatureJourney\Entities\JourneyStageAction;
use Espo\Modules\FeatureJourney\Entities\JourneyTransition;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Review & Publish: validation + audience/side-effect preview + honest activate report.
 */
class JourneyPublishService
{
    private const LARGE_AUDIENCE_WARN = 500;

    /** @var list<string> */
    private const HIGH_IMPACT_TYPES = [
        'sendEmail',
        'sendWhatsAppMessage',
        'sendWhatsAppTemplate',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private JourneyEnrollmentService $enrollmentService,
        private Metadata $metadata,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function review(string $journeyId): array
    {
        $journey = $this->entityManager->getEntityById(JourneyEntity::ENTITY_TYPE, $journeyId);
        if (!$journey) {
            throw new NotFound("Journey {$journeyId} not found.");
        }

        $issues = [];
        $status = (string) ($journey->get('status') ?? '');

        if (!in_array($status, [JourneyEntity::STATUS_DRAFT, JourneyEntity::STATUS_PAUSED], true)) {
            $issues[] = $this->issue(
                'error',
                'invalid_status',
                "Cannot activate journey in status {$status}."
            );
        }

        $stages = $this->entityManager
            ->getRDBRepository(JourneyStage::ENTITY_TYPE)
            ->where(['journeyId' => $journeyId])
            ->order('order', 'ASC')
            ->find();

        $stageMap = [];
        $entryCount = 0;
        $successCount = 0;
        $exitCount = 0;
        $activeStageCount = 0;
        $entryStageIds = [];
        $stagesWithMaxDuration = [];

        foreach ($stages as $stage) {
            $id = (string) $stage->getId();
            $stageMap[$id] = $stage;
            $isActive = (bool) $stage->get('isActive');
            if ($isActive) {
                $activeStageCount++;
            }

            $type = (string) ($stage->get('stageType') ?? '');
            if ($type === JourneyStage::TYPE_ENTRY && $isActive) {
                $entryCount++;
                $entryStageIds[] = $id;
            }
            if ($type === JourneyStage::TYPE_SUCCESS && $isActive) {
                $successCount++;
            }
            if ($type === JourneyStage::TYPE_EXIT && $isActive) {
                $exitCount++;
            }

            $maxDuration = $stage->get('maxDuration');
            if ($isActive && is_string($maxDuration) && trim($maxDuration) !== '') {
                $stagesWithMaxDuration[] = (string) ($stage->get('name') ?: $id);
            }
        }

        if ($entryCount === 0) {
            $issues[] = $this->issue(
                'error',
                'no_entry_stage',
                'Journey requires at least one active Entry stage before activation.'
            );
        }

        if ($successCount === 0 && $exitCount === 0) {
            $issues[] = $this->issue(
                'warning',
                'no_terminal_stage',
                'No active Success or Exit stage. People may stay in the journey indefinitely.'
            );
        }

        if ($stagesWithMaxDuration !== []) {
            $issues[] = $this->issue(
                'warning',
                'max_duration_silent_exit',
                'These steps have a max time in step and will auto-exit people silently: ' .
                implode(', ', array_slice($stagesWithMaxDuration, 0, 8)) .
                (count($stagesWithMaxDuration) > 8 ? '…' : '') . '.',
                ['stages' => $stagesWithMaxDuration]
            );
        }

        $transitions = $this->entityManager
            ->getRDBRepository(JourneyTransition::ENTITY_TYPE)
            ->where([
                'journeyId' => $journeyId,
                'isActive' => true,
            ])
            ->find();

        $outboundFromEntry = 0;
        $activeTransitionCount = 0;
        foreach ($transitions as $transition) {
            $activeTransitionCount++;
            $fromId = $transition->get('fromStageId');
            if ($fromId && in_array((string) $fromId, $entryStageIds, true)) {
                $outboundFromEntry++;
            }
            if ($fromId === null || $fromId === '') {
                // enrollment transitions
                $outboundFromEntry++;
            }
        }

        $actions = $this->loadActiveActions(array_keys($stageMap));
        $onEnterOnEntry = 0;
        foreach ($actions as $action) {
            $stageId = (string) ($action->get('stageId') ?? '');
            if (
                in_array($stageId, $entryStageIds, true) &&
                $action->get('trigger') === JourneyStageAction::TRIGGER_ON_ENTER
            ) {
                $onEnterOnEntry++;
            }
        }

        if ($entryCount > 0 && $outboundFromEntry === 0 && $onEnterOnEntry === 0) {
            $issues[] = $this->issue(
                'warning',
                'entry_dead_end',
                'Entry step has no OnEnter actions and no outgoing connections.'
            );
        }

        if ($activeStageCount > 0 && $activeTransitionCount === 0) {
            $issues[] = $this->issue(
                'warning',
                'no_transitions',
                'No active connections. People will enter but not move between steps.'
            );
        }

        foreach ($this->validateActions($actions, $stageMap) as $issue) {
            $issues[] = $issue;
        }

        $breakdown = $this->enrollmentService->resolveAudienceDetailed($journey);
        /** @var list<array{targetType: string, targetId: string}> $audience */
        $audience = $breakdown['audience'];
        $eligibleCount = count($audience);

        $alreadyActive = $this->countAlreadyActive($journeyId, $audience);
        $reEnrollBlocked = 0;
        if (!$journey->get('allowReEnrollment')) {
            $reEnrollBlocked = $this->countPriorEnrollments($journeyId, $audience);
        }

        $wouldEnroll = max(0, $eligibleCount - $alreadyActive - $reEnrollBlocked);

        $continuous = (bool) $journey->get('continuousEnrollment');

        if ($eligibleCount === 0) {
            if ($continuous) {
                $issues[] = $this->issue(
                    'warning',
                    'empty_audience_continuous',
                    'No one is in the audience yet. Auto-add is on, so people can join later when lists update.'
                );
            } else {
                $issues[] = $this->issue(
                    'error',
                    'empty_audience',
                    'Audience is empty. Add include lists or people, or turn on auto-add new people.'
                );
            }
        }

        if ($wouldEnroll >= self::LARGE_AUDIENCE_WARN) {
            $issues[] = $this->issue(
                'warning',
                'large_audience',
                "About {$wouldEnroll} people would enroll now. Activation runs OnEnter actions for each immediately.",
                ['wouldEnroll' => $wouldEnroll]
            );
        }

        $sideEffects = $this->buildSideEffects($actions, $stageMap);
        $highImpactCount = 0;
        foreach ($sideEffects as $group) {
            if (in_array((string) ($group['type'] ?? ''), self::HIGH_IMPACT_TYPES, true)) {
                $highImpactCount += (int) ($group['count'] ?? 0);
            }
        }

        if ($highImpactCount > 0 && $wouldEnroll > 0) {
            $issues[] = $this->issue(
                'warning',
                'immediate_sends',
                "On turn-on, about {$wouldEnroll} people may receive entry actions " .
                "({$highImpactCount} email/WhatsApp action(s) in the flow).",
                [
                    'wouldEnroll' => $wouldEnroll,
                    'highImpactActions' => $highImpactCount,
                ]
            );
        }

        $hasError = false;
        foreach ($issues as $issue) {
            if (($issue['level'] ?? '') === 'error') {
                $hasError = true;
                break;
            }
        }

        $canActivate = !$hasError &&
            in_array($status, [JourneyEntity::STATUS_DRAFT, JourneyEntity::STATUS_PAUSED], true) &&
            $entryCount > 0;

        return [
            'canActivate' => $canActivate,
            'issues' => $issues,
            'audience' => [
                'targetEntityType' => $breakdown['targetEntityType'],
                'eligibleCount' => $eligibleCount,
                'includeListCount' => $breakdown['includeListCount'],
                'includeListsUsed' => $breakdown['includeListsUsed'],
                'manualCount' => $breakdown['manualCount'],
                'excludedCount' => $breakdown['excludedCount'],
                'excludeListsUsed' => $breakdown['excludeListsUsed'],
                'alreadyActive' => $alreadyActive,
                'reEnrollBlocked' => $reEnrollBlocked,
                'wouldEnroll' => $wouldEnroll,
                'continuousEnrollment' => $continuous,
                'allowReEnrollment' => (bool) $journey->get('allowReEnrollment'),
            ],
            'sideEffects' => $sideEffects,
            'stages' => [
                'entryCount' => $entryCount,
                'successCount' => $successCount,
                'exitCount' => $exitCount,
                'activeStageCount' => $activeStageCount,
                'activeTransitionCount' => $activeTransitionCount,
                'activeActionCount' => count($actions),
            ],
        ];
    }

    /**
     * @return array{
     *     journey: object,
     *     enrollment: array<string, mixed>,
     *     review: array<string, mixed>
     * }
     */
    public function activateWithReport(string $journeyId): array
    {
        $journey = $this->entityManager->getEntityById(JourneyEntity::ENTITY_TYPE, $journeyId);
        if (!$journey) {
            throw new NotFound("Journey {$journeyId} not found.");
        }

        $review = $this->review($journeyId);
        if (!(bool) ($review['canActivate'] ?? false)) {
            $messages = [];
            foreach ($review['issues'] as $issue) {
                if (($issue['level'] ?? '') === 'error') {
                    $messages[] = (string) ($issue['message'] ?? $issue['code'] ?? 'error');
                }
            }
            throw new BadRequest(
                $messages !== []
                    ? implode(' ', $messages)
                    : 'Journey cannot be activated.'
            );
        }

        $status = (string) $journey->get('status');
        if (!in_array($status, [JourneyEntity::STATUS_DRAFT, JourneyEntity::STATUS_PAUSED], true)) {
            throw new BadRequest("Cannot activate journey in status {$status}.");
        }

        $journey->set([
            'status' => JourneyEntity::STATUS_ACTIVE,
            'activatedAt' => $journey->get('activatedAt') ?: date('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($journey);

        $enrollment = $this->enrollmentService->enrollAudienceDetailed($journeyId);

        $fresh = $this->entityManager->getEntityById(JourneyEntity::ENTITY_TYPE, $journeyId);

        return [
            'journey' => (object) ($fresh?->getValueMap() ?? $journey->getValueMap()),
            'enrollment' => $enrollment,
            'review' => $review,
        ];
    }

    /**
     * @param list<string|int> $stageIds
     * @return list<Entity>
     */
    private function loadActiveActions(array $stageIds): array
    {
        if ($stageIds === []) {
            return [];
        }

        $collection = $this->entityManager
            ->getRDBRepository(JourneyStageAction::ENTITY_TYPE)
            ->where([
                'stageId' => array_values(array_map('strval', $stageIds)),
                'isActive' => true,
            ])
            ->order('order', 'ASC')
            ->find();

        $list = [];
        foreach ($collection as $action) {
            $list[] = $action;
        }

        return $list;
    }

    /**
     * @param list<Entity> $actions
     * @param array<string, Entity> $stageMap
     * @return list<array{level: string, code: string, message: string, context?: array<string, mixed>}>
     */
    private function validateActions(array $actions, array $stageMap): array
    {
        $issues = [];

        foreach ($actions as $action) {
            $type = (string) ($action->get('type') ?? '');
            $name = (string) ($action->get('name') ?: $type);
            $stageId = (string) ($action->get('stageId') ?? '');
            $stageName = isset($stageMap[$stageId])
                ? (string) ($stageMap[$stageId]->get('name') ?: $stageId)
                : $stageId;

            $meta = $this->metadata->get(['app', 'journeyActionTypes', 'types', $type]);
            if (!is_array($meta)) {
                $issues[] = $this->issue(
                    'error',
                    'unknown_action_type',
                    "Action \"{$name}\" on step \"{$stageName}\" has unknown type \"{$type}\".",
                    ['actionId' => $action->getId(), 'type' => $type]
                );
                continue;
            }

            $className = $meta['implementationClassName'] ?? null;
            if (!$className || !class_exists((string) $className)) {
                $issues[] = $this->issue(
                    'error',
                    'action_implementation_missing',
                    "Action \"{$name}\" on step \"{$stageName}\" is not available (implementation missing).",
                    ['actionId' => $action->getId(), 'type' => $type]
                );
            }

            $params = $action->get('params');
            if ($params instanceof \stdClass) {
                $params = (array) $params;
            }
            if (!is_array($params)) {
                $params = [];
            }

            if ($type === 'sendEmail') {
                $inboundEmailId = (string) ($params['inboundEmailId'] ?? '');
                $emailAccountId = (string) ($params['emailAccountId'] ?? '');
                if ($inboundEmailId !== '' && $emailAccountId !== '') {
                    $issues[] = $this->issue(
                        'error',
                        'send_email_both_accounts',
                        "Email action \"{$name}\" on \"{$stageName}\" has both group and personal account set.",
                        ['actionId' => $action->getId()]
                    );
                } elseif ($inboundEmailId === '' && $emailAccountId === '') {
                    $issues[] = $this->issue(
                        'error',
                        'send_email_no_account',
                        "Email action \"{$name}\" on \"{$stageName}\" needs a group or personal email account " .
                        '(system SMTP not allowed).',
                        ['actionId' => $action->getId()]
                    );
                }
            }

            if ($type === 'sendWhatsAppMessage') {
                $inboxId = (string) ($params['chatwootInboxId'] ?? '');
                $body = trim((string) ($params['body'] ?? ''));
                $bodyFormula = $this->paramFormulaScript($params, 'body');
                if ($inboxId === '') {
                    $issues[] = $this->issue(
                        'error',
                        'wa_message_no_inbox',
                        "WhatsApp message \"{$name}\" on \"{$stageName}\" needs a WhatsApp inbox.",
                        ['actionId' => $action->getId()]
                    );
                }
                if ($body === '' && $bodyFormula === '') {
                    $issues[] = $this->issue(
                        'error',
                        'wa_message_no_body',
                        "WhatsApp message \"{$name}\" on \"{$stageName}\" needs a message body " .
                        '(static text or dynamic formula).',
                        ['actionId' => $action->getId()]
                    );
                }
            }

            if ($type === 'sendWhatsAppTemplate') {
                $inboxId = (string) ($params['chatwootInboxId'] ?? '');
                $templateName = trim((string) ($params['templateName'] ?? ''));
                if ($inboxId === '') {
                    $issues[] = $this->issue(
                        'error',
                        'wa_template_no_inbox',
                        "WhatsApp template \"{$name}\" on \"{$stageName}\" needs a Cloud/Coexistence inbox.",
                        ['actionId' => $action->getId()]
                    );
                }
                if ($templateName === '') {
                    $issues[] = $this->issue(
                        'error',
                        'wa_template_no_name',
                        "WhatsApp template \"{$name}\" on \"{$stageName}\" needs a template name.",
                        ['actionId' => $action->getId()]
                    );
                }
            }

            if ($type === 'runScript') {
                $scriptClass = (string) ($params['className'] ?? '');
                if ($scriptClass === '') {
                    $issues[] = $this->issue(
                        'error',
                        'run_script_no_class',
                        "Script action \"{$name}\" on \"{$stageName}\" needs params.className.",
                        ['actionId' => $action->getId()]
                    );
                }
            }

            if ($type === 'createTask') {
                $taskName = trim((string) ($params['name'] ?? ''));
                if ($taskName === '') {
                    $issues[] = $this->issue(
                        'warning',
                        'task_no_name',
                        "Task action \"{$name}\" on \"{$stageName}\" has no task name.",
                        ['actionId' => $action->getId()]
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @param list<Entity> $actions
     * @param array<string, Entity> $stageMap
     * @return list<array<string, mixed>>
     */
    private function buildSideEffects(array $actions, array $stageMap): array
    {
        $groups = [];

        foreach ($actions as $action) {
            $type = (string) ($action->get('type') ?? 'unknown');
            if (!isset($groups[$type])) {
                $groups[$type] = [
                    'type' => $type,
                    'count' => 0,
                    'highImpact' => in_array($type, self::HIGH_IMPACT_TYPES, true),
                    'items' => [],
                ];
            }

            $stageId = (string) ($action->get('stageId') ?? '');
            $stageName = isset($stageMap[$stageId])
                ? (string) ($stageMap[$stageId]->get('name') ?: $stageId)
                : $stageId;

            $params = $action->get('params');
            if ($params instanceof \stdClass) {
                $params = (array) $params;
            }
            if (!is_array($params)) {
                $params = [];
            }

            $groups[$type]['count']++;
            if (count($groups[$type]['items']) < 20) {
                $groups[$type]['items'][] = [
                    'actionId' => $action->getId(),
                    'actionName' => (string) ($action->get('name') ?: $type),
                    'stageId' => $stageId,
                    'stageName' => $stageName,
                    'trigger' => (string) ($action->get('trigger') ?? ''),
                    'summary' => $this->summarizeAction($type, $params),
                ];
            }
        }

        $list = array_values($groups);
        usort(
            $list,
            static function (array $a, array $b): int {
                if (($a['highImpact'] ?? false) !== ($b['highImpact'] ?? false)) {
                    return ($a['highImpact'] ?? false) ? -1 : 1;
                }

                return ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0));
            }
        );

        return $list;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function summarizeAction(string $type, array $params): string
    {
        return match ($type) {
            'sendEmail' => $this->firstNonEmpty([
                (string) ($params['subject'] ?? ''),
                (string) ($params['templateName'] ?? ''),
                !empty($params['inboundEmailId']) ? 'group account' : '',
                !empty($params['emailAccountId']) ? 'personal account' : '',
            ]) ?: 'email',
            'sendWhatsAppMessage' => $this->truncate(
                trim((string) ($params['body'] ?? ''))
                    ?: $this->paramFormulaScript($params, 'body'),
                60
            ) ?: 'WhatsApp message',
            'sendWhatsAppTemplate' => (string) ($params['templateName'] ?? 'WhatsApp template'),
            'createTask' => (string) ($params['name'] ?? 'task'),
            'notifyUser' => $this->truncate(trim((string) ($params['message'] ?? '')), 60) ?: 'notify user',
            'updateTarget' => $this->summarizeUpdateTarget($params),
            'recordTrackingEvent' => (string) ($params['code'] ?? 'tracking event'),
            'executeFormula' => 'formula',
            'runScript' => (string) ($params['className'] ?? 'script'),
            'triggerWorkflow' => (string) ($params['workflowId'] ?? 'workflow'),
            'startBpmnProcess' => (string) ($params['flowchartId'] ?? 'BPMN'),
            default => $type,
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function summarizeUpdateTarget(array $params): string
    {
        $fields = $params['fields'] ?? null;
        if ($fields instanceof \stdClass) {
            $fields = (array) $fields;
        }
        if (!is_array($fields) || $fields === []) {
            return 'update fields';
        }

        $keys = array_keys($fields);

        return 'update ' . implode(', ', array_slice($keys, 0, 5)) .
            (count($keys) > 5 ? '…' : '');
    }

    /**
     * @param list<string> $parts
     */
    private function firstNonEmpty(array $parts): string
    {
        foreach ($parts as $part) {
            if (trim($part) !== '') {
                return trim($part);
            }
        }

        return '';
    }

    private function truncate(string $value, int $max): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max - 1) . '…';
    }

    /**
     * @param list<array{targetType: string, targetId: string}> $audience
     */
    private function countAlreadyActive(string $journeyId, array $audience): int
    {
        if ($audience === []) {
            return 0;
        }

        $byType = [];
        foreach ($audience as $row) {
            $byType[$row['targetType']][] = $row['targetId'];
        }

        $count = 0;
        foreach ($byType as $targetType => $ids) {
            $ids = array_values(array_unique($ids));
            foreach (array_chunk($ids, 500) as $chunk) {
                $count += $this->entityManager
                    ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
                    ->where([
                        'journeyId' => $journeyId,
                        'targetType' => $targetType,
                        'targetId' => $chunk,
                        'status' => [
                            JourneyRecord::STATUS_ACTIVE,
                            JourneyRecord::STATUS_PAUSED,
                            JourneyRecord::STATUS_PROCESSING,
                        ],
                    ])
                    ->count();
            }
        }

        return $count;
    }

    /**
     * People who already have a prior record and cannot re-enroll.
     *
     * @param list<array{targetType: string, targetId: string}> $audience
     */
    private function countPriorEnrollments(string $journeyId, array $audience): int
    {
        if ($audience === []) {
            return 0;
        }

        $byType = [];
        foreach ($audience as $row) {
            $byType[$row['targetType']][] = $row['targetId'];
        }

        // Prior terminal/history records only (not currently active/paused/processing).
        $blockedKeys = [];
        foreach ($byType as $targetType => $ids) {
            $ids = array_values(array_unique($ids));
            foreach (array_chunk($ids, 500) as $chunk) {
                $rows = $this->entityManager
                    ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
                    ->where([
                        'journeyId' => $journeyId,
                        'targetType' => $targetType,
                        'targetId' => $chunk,
                        'status!=' => [
                            JourneyRecord::STATUS_ACTIVE,
                            JourneyRecord::STATUS_PAUSED,
                            JourneyRecord::STATUS_PROCESSING,
                        ],
                    ])
                    ->select(['targetId'])
                    ->find();

                foreach ($rows as $row) {
                    $blockedKeys[$targetType . ':' . (string) $row->get('targetId')] = true;
                }
            }
        }

        return count($blockedKeys);
    }

    /**
     * @param array<string, mixed>|null $context
     * @return array{level: string, code: string, message: string, context?: array<string, mixed>}
     */
    private function issue(string $level, string $code, string $message, ?array $context = null): array
    {
        $row = [
            'level' => $level,
            'code' => $code,
            'message' => $message,
        ];
        if ($context !== null) {
            $row['context'] = $context;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function paramFormulaScript(array $params, string $key): string
    {
        $formulas = $params['paramFormulas'] ?? null;
        if ($formulas instanceof \stdClass) {
            $formulas = (array) $formulas;
        }
        if (!is_array($formulas)) {
            return '';
        }

        $script = $formulas[$key] ?? null;

        return is_string($script) ? trim($script) : '';
    }
}
