<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Tools\Opportunity;

use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\DateTime\Clock;
use Espo\ORM\EntityManager;
use stdClass;

class StageHistory
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private Clock $clock,
    ) {}

    public function get(string $id, int $offset = 0): stdClass
    {
        $opportunity = $this->entityManager->getEntityById('Opportunity', $id);
        if (!$opportunity) {
            throw new NotFound();
        }
        // Re-evaluate the parent's current ACL on every request, including after team/owner changes.
        if (!$this->acl->checkScope('Opportunity', 'read') || !$this->acl->checkEntityRead($opportunity)) {
            throw new Forbidden();
        }
        foreach (['stageHistory', 'opportunityStage', 'funnel', 'status',
            'stageEnteredAt', 'stageTargetTimeSeconds', 'stageDueAt'] as $field) {
            if (!$this->acl->checkField('Opportunity', $field, 'read')) {
                throw new Forbidden();
            }
        }

        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $rows = $this->entityManager->getRDBRepository('OpportunityStageHistory')
            ->where(['opportunityId' => $id])->order('sequence', 'DESC')->sth()->find();
        $list = [];
        $summary = [];
        $total = 0;
        $partial = false;
        // Stream the immutable ledger; only retain the requested page and one total per stage.
        foreach ($rows as $row) {
            $data = $row->getValueMap();
            $active = $row->get('kind') === 'Visit' && !$row->get('exitedAt');
            $elapsed = $active ? StageTiming::secondsBetween($row->get('enteredAt'), $now) :
                (int) $row->get('durationSeconds');
            $data->elapsedSeconds = $elapsed;
            $data->active = $active;
            $data->overdueSeconds = $row->get('targetTimeSeconds') === null ? null :
                max(0, $elapsed - $row->get('targetTimeSeconds'));
            if ($total >= $offset && count($list) < 50) {
                $list[] = $data;
            }
            $total++;
            $partial = $partial || (bool) $row->get('isPartial');
            if ($row->get('kind') !== 'Visit') {
                continue;
            }
            $key = $row->get('funnelId') . ':' . $row->get('stageId');
            $summary[$key] ??= [
                'stageId' => $row->get('stageId'),
                'stageName' => $row->get('stageName'),
                'funnelName' => $row->get('funnelName'),
                'visits' => 0,
                'elapsedSeconds' => 0,
                'isPartial' => false,
            ];
            $summary[$key]['visits']++;
            $summary[$key]['elapsedSeconds'] += $elapsed;
            $summary[$key]['isPartial'] = $summary[$key]['isPartial'] || (bool) $row->get('isPartial');
        }

        return (object) [
            'list' => $list,
            'total' => $total,
            'summary' => array_values($summary),
            'asOf' => $now,
            'trackingStartedAt' => $opportunity->get('stageTrackingStartedAt'),
            'isPartial' => $partial,
        ];
    }
}
