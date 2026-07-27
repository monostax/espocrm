<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Assign target via Round-Robin or Least-Busy within a tenant team only.
 * Params: assignmentRule (Round-Robin|Least-Busy), targetTeamId, optional targetUserPosition.
 * listReportId is rejected (reports are not tenant-scoped).
 */
class ApplyAssignmentRule implements Action
{
    private const ROUND_ROBIN = 'Espo\\Modules\\Advanced\\Business\\Workflow\\AssignmentRules\\RoundRobin';
    private const LEAST_BUSY = 'Espo\\Modules\\Advanced\\Business\\Workflow\\AssignmentRules\\LeastBusy';

    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
        private InjectableFactory $injectableFactory,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('ApplyAssignmentRule: missing tenantId.');
        }

        $target = $context->target;
        $this->tenantGuard->assertEntityTenant($target, $tenantId, 'target');

        if (!$target->hasAttribute('assignedUserId')) {
            throw new Error('ApplyAssignmentRule: target has no assignedUserId.');
        }

        $teamId = (string) ($context->params['targetTeamId'] ?? $context->params['teamId'] ?? '');
        if ($teamId === '') {
            throw new Error('ApplyAssignmentRule: targetTeamId is required.');
        }

        $this->tenantGuard->assertTeamInTenant($teamId, $tenantId, 'assignment-team');

        if (!empty($context->params['listReportId'])) {
            throw new Error('ApplyAssignmentRule: listReportId is not allowed (not tenant-scoped).');
        }

        $rule = (string) ($context->params['assignmentRule'] ?? 'Round-Robin');
        $position = isset($context->params['targetUserPosition'])
            ? (string) $context->params['targetUserPosition']
            : null;
        if ($position === '') {
            $position = null;
        }

        $attributes = $this->resolveAssignment($target, $context, $teamId, $rule, $position);

        $userId = (string) ($attributes['assignedUserId'] ?? '');
        if ($userId === '') {
            throw new Error('ApplyAssignmentRule: rule returned no user.');
        }

        $this->tenantGuard->assertUserInTenant($userId, $tenantId, 'assignee');

        $target->set('assignedUserId', $userId);
        if (isset($attributes['teamsIds']) && is_array($attributes['teamsIds'])) {
            // Only keep teams already in tenant; never trust free-form.
            $tenantTeams = $this->tenantGuard->getTenantTeamIds($tenantId);
            $keep = array_values(array_intersect(
                array_map('strval', $attributes['teamsIds']),
                $tenantTeams
            ));
            if ($keep !== [] && ($target->hasRelation('teams') || $target->hasAttribute('teamsIds'))) {
                $target->set('teamsIds', $keep);
            }
        }

        $this->entityManager->saveEntity($target, [
            SaveOption::SILENT => false,
            'skipJourneyDispatch' => true,
            'modifiedById' => 'system',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAssignment(
        Entity $target,
        ActionContext $context,
        string $teamId,
        string $rule,
        ?string $position,
    ): array {
        $normalized = str_replace([' ', '_'], '-', $rule);
        $journeyKey = 'journey:' . (string) $context->journey->getId();
        $actionId = substr(hash('sha256', $journeyKey . '|' . $teamId . '|' . $normalized), 0, 24);

        if (strcasecmp($normalized, 'Round-Robin') === 0 || strcasecmp($rule, 'RoundRobin') === 0) {
            if (!class_exists(self::ROUND_ROBIN)) {
                return $this->fallbackFirstTeamUser($teamId, $position);
            }

            $impl = $this->injectableFactory->createWith(self::ROUND_ROBIN, [
                'entityType' => $target->getEntityType(),
                'actionId' => $actionId,
                'workflowId' => $journeyKey,
                'flowchartId' => null,
            ]);

            return $impl->getAssignmentAttributes($target, $teamId, $position, null);
        }

        if (strcasecmp($normalized, 'Least-Busy') === 0 || strcasecmp($rule, 'LeastBusy') === 0) {
            if (!class_exists(self::LEAST_BUSY)) {
                return $this->fallbackFirstTeamUser($teamId, $position);
            }

            $impl = $this->injectableFactory->createWith(self::LEAST_BUSY, [
                'entityType' => $target->getEntityType(),
            ]);

            return $impl->getAssignmentAttributes($target, $teamId, $position, null);
        }

        throw new Error("ApplyAssignmentRule: unsupported rule '{$rule}'.");
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackFirstTeamUser(string $teamId, ?string $position): array
    {
        $team = $this->entityManager->getEntityById('Team', $teamId);
        if (!$team) {
            throw new Error('ApplyAssignmentRule: team not found.');
        }

        $where = ['isActive' => true];
        if ($position) {
            $where['@relation.role'] = $position;
        }

        $user = $this->entityManager
            ->getRDBRepository('Team')
            ->getRelation($team, 'users')
            ->where($where)
            ->order('userName')
            ->findOne();

        if (!$user) {
            throw new Error('ApplyAssignmentRule: no eligible team user.');
        }

        return ['assignedUserId' => $user->getId()];
    }
}
