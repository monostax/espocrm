<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Entities\User;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * Runs Advanced List/Grid reports into a formula-friendly payload blob.
 */
class ReportPayloadBuilder
{
    private const MAX_ROWS = 2000;

    public function __construct(
        private EntityManager $entityManager,
        private InjectableFactory $injectableFactory,
        private TenantGuard $tenantGuard,
    ) {}

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function build(
        array $params,
        ?string $tenantId,
        ?User $actor = null,
        bool $crossTenant = false,
    ): array {
        $reportId = trim((string) ($params['reportId'] ?? ''));
        if ($reportId === '') {
            throw new Error('runReport requires reportId.');
        }

        $serviceClass = 'Espo\\Modules\\Advanced\\Tools\\Report\\Service';
        if (!class_exists($serviceClass)) {
            throw new Error('Report service unavailable (Advanced module).');
        }

        /** @var Entity|null $report */
        $report = $this->entityManager->getEntityById('Report', $reportId);
        if (!$report) {
            throw new Error("Report '{$reportId}' not found.");
        }

        $reportType = (string) ($report->get('type') ?? '');
        $mode = strtolower(trim((string) ($params['mode'] ?? 'auto')));
        if ($mode === '' || $mode === 'auto') {
            $mode = strtolower($reportType) === 'grid' ? 'grid' : 'list';
        }
        if (!in_array($mode, ['list', 'grid'], true)) {
            throw new Error("runReport mode must be auto|list|grid.");
        }

        $maxRows = (int) ($params['maxRows'] ?? 500);
        $maxRows = max(1, min(self::MAX_ROWS, $maxRows));

        $timezone = trim((string) ($params['timezone'] ?? 'UTC'));
        if ($timezone === '') {
            $timezone = 'UTC';
        }

        $period = trim((string) ($params['period'] ?? 'none'));
        if ($period === '') {
            $period = 'none';
        }

        $periodField = trim((string) ($params['periodField'] ?? ''));
        $periodMeta = $this->resolvePeriod($period, $timezone);

        // When true: restrict Opportunity (or any entity with id) rows to those linked
        // via ChatwootConversationOpportunity to a conversation that had at least one
        // ChatwootAiAgentRun in the resolved period (runAt window). Period bounds then
        // drive AI-run discovery only — they are NOT applied as opportunity date filters.
        $scopeAiAgentConversations = $this->truthy($params['scopeAiAgentConversations'] ?? false);

        $extraWhere = $this->normalizeWhereList($params['where'] ?? null);

        // Always AND tenant when a tenant is known — params cannot opt out.
        // Fail closed when unresolved unless this is an instance-admin crossTenant
        // automation (see CrossTenantAccess), which may only run unscoped when the
        // item itself has no tenantId. forEach-Tenant digests still pass tenantId
        // on each item and must stay scoped even if Automation.crossTenant=true.
        $tenantId = is_string($tenantId) ? trim($tenantId) : $tenantId;
        if ($tenantId === '') {
            $tenantId = null;
        }

        if ($tenantId !== null) {
            $extraWhere[] = [
                'type' => 'equals',
                'attribute' => 'tenantId',
                'value' => $tenantId,
            ];
        } elseif (!$crossTenant) {
            $tenantId = $this->tenantGuard->assertTenantScope($tenantId, 'runReport');

            $extraWhere[] = [
                'type' => 'equals',
                'attribute' => 'tenantId',
                'value' => $tenantId,
            ];
        }

        if ($scopeAiAgentConversations) {
            if (!$periodMeta) {
                throw new Error(
                    'runReport scopeAiAgentConversations requires a concrete period ' .
                    '(previousDay|previousWeek|…).'
                );
            }

            $oppIds = $this->fetchOpportunityIdsLinkedToAiRunsInPeriod(
                $tenantId,
                $periodMeta['start'],
                $periodMeta['end'],
                $crossTenant
            );

            if ($oppIds === []) {
                $extraWhere[] = [
                    'type' => 'equals',
                    'attribute' => 'id',
                    'value' => '__none__',
                ];
            } else {
                $extraWhere[] = [
                    'type' => 'in',
                    'attribute' => 'id',
                    'value' => $oppIds,
                ];
            }
        } elseif ($periodMeta && $periodField !== '') {
            $extraWhere[] = [
                'type' => 'greaterThanOrEquals',
                'attribute' => $periodField,
                'value' => $periodMeta['start'],
            ];
            $extraWhere[] = [
                'type' => 'lessThan',
                'attribute' => $periodField,
                'value' => $periodMeta['end'],
            ];
        }

        $whereItem = $this->toWhereItem($extraWhere);

        try {
            $service = $this->injectableFactory->create($serviceClass);

            if ($mode === 'grid') {
                $result = $service->runGrid($reportId, $whereItem, $actor);
                $blob = $this->gridToBlob($result);
            } else {
                $searchRaw = ['maxSize' => $maxRows];
                if ($extraWhere !== []) {
                    $searchRaw['where'] = [
                        [
                            'type' => 'and',
                            'value' => $extraWhere,
                        ],
                    ];
                }
                $result = $service->runList(
                    $reportId,
                    SearchParams::fromRaw($searchRaw),
                    $actor
                );
                $blob = $this->listToBlob($result, $maxRows);
            }
        } catch (Throwable $e) {
            throw new Error('runReport failed: ' . $e->getMessage());
        }

        $blob['reportId'] = $reportId;
        $blob['reportName'] = (string) ($report->get('name') ?? '');
        $blob['reportType'] = $reportType;
        $blob['mode'] = $mode;
        $blob['tenantId'] = $tenantId;
        $blob['period'] = $periodMeta;
        $blob['periodField'] = $scopeAiAgentConversations
            ? 'runAt'
            : ($periodField !== '' ? $periodField : null);
        $blob['scopeAiAgentConversations'] = $scopeAiAgentConversations;
        $blob['ranAt'] = gmdate('c');

        return $blob;
    }

    /**
     * Distinct Opportunity ids linked (m2m chatwootConversationOpportunity) to any
     * ChatwootConversation that had a ChatwootAiAgentRun with runAt in [start, end).
     *
     * @return list<string>
     */
    private function fetchOpportunityIdsLinkedToAiRunsInPeriod(
        ?string $tenantId,
        string $start,
        string $end,
        bool $crossTenant,
    ): array {
        $pdo = $this->entityManager->getPDO();

        $sql = <<<'SQL'
SELECT DISTINCT j.opportunity_id AS opp_id
FROM chatwoot_ai_agent_run r
INNER JOIN chatwoot_conversation_opportunity j
    ON j.chatwoot_conversation_id = r.conversation_id
    AND j.deleted = 0
WHERE r.deleted = 0
    AND r.conversation_id IS NOT NULL
    AND j.opportunity_id IS NOT NULL
    AND r.run_at >= :start
    AND r.run_at < :end
SQL;

        $bind = [
            'start' => $start,
            'end' => $end,
        ];

        if ($tenantId !== null && $tenantId !== '') {
            $sql .= ' AND r.tenant_id = :tenantId';
            $bind['tenantId'] = $tenantId;
        } elseif (!$crossTenant) {
            throw new Error('runReport scopeAiAgentConversations: tenant required.');
        }

        $sql .= ' LIMIT 5000';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        $ids = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $id = trim((string) ($row['opp_id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));

            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @return array{label: string, start: string, end: string, timezone: string}|null
     */
    public function resolvePeriod(string $period, string $timezone): ?array
    {
        $period = strtolower(trim($period));
        if ($period === '' || $period === 'none' || $period === 'all') {
            return null;
        }

        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new Error("Invalid timezone '{$timezone}'.");
        }

        $now = new DateTimeImmutable('now', $tz);

        switch ($period) {
            case 'currentday':
            case 'today':
                $start = $now->setTime(0, 0, 0);
                $end = $start->add(new DateInterval('P1D'));
                $label = 'today';
                break;

            case 'previousday':
            case 'yesterday':
                $end = $now->setTime(0, 0, 0);
                $start = $end->sub(new DateInterval('P1D'));
                $label = 'previousDay';
                break;

            case 'currentweek':
            case 'thisweek':
                // ISO week: Monday 00:00 → next Monday 00:00
                $n = (int) $now->format('N');
                $start = $now->setTime(0, 0, 0)->sub(new DateInterval('P' . ($n - 1) . 'D'));
                $end = $start->add(new DateInterval('P7D'));
                $label = 'currentWeek';
                break;

            case 'previousweek':
            case 'lastweek':
                $n = (int) $now->format('N');
                $start = $now->setTime(0, 0, 0)
                    ->sub(new DateInterval('P' . ($n - 1) . 'D'))
                    ->sub(new DateInterval('P7D'));
                $end = $start->add(new DateInterval('P7D'));
                $label = 'previousWeek';
                break;

            case 'currentmonth':
            case 'thismonth':
                $start = $now->modify('first day of this month')->setTime(0, 0, 0);
                $end = $start->add(new DateInterval('P1M'));
                $label = 'currentMonth';
                break;

            case 'previousmonth':
            case 'lastmonth':
                $start = $now->modify('first day of last month')->setTime(0, 0, 0);
                $end = $start->add(new DateInterval('P1M'));
                $label = 'previousMonth';
                break;

            default:
                throw new Error(
                    "Unknown period '{$period}'. Use none|currentDay|previousDay|currentWeek|previousWeek|currentMonth|previousMonth."
                );
        }

        return [
            'label' => $label,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
            'timezone' => $timezone,
        ];
    }

    /**
     * @param mixed $where
     * @return list<array<string, mixed>>
     */
    private function normalizeWhereList(mixed $where): array
    {
        if ($where instanceof stdClass) {
            $where = json_decode(json_encode($where) ?: '[]', true);
        }

        if ($where === null || $where === '' || $where === []) {
            return [];
        }

        if (!is_array($where)) {
            throw new Error('runReport where must be a JSON array of Espo where items.');
        }

        // Single item object {type, attribute, value}
        if (isset($where['type']) && is_string($where['type'])) {
            return [$where];
        }

        $out = [];
        foreach ($where as $item) {
            if ($item instanceof stdClass) {
                $item = json_decode(json_encode($item) ?: '{}', true);
            }
            if (is_array($item) && isset($item['type'])) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function toWhereItem(array $items): ?WhereItem
    {
        if ($items === []) {
            return null;
        }

        if (count($items) === 1) {
            return WhereItem::fromRaw($items[0]);
        }

        return WhereItem::fromRaw([
            'type' => 'and',
            'value' => $items,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listToBlob(object $result, int $maxRows): array
    {
        $rows = [];
        $collection = method_exists($result, 'getCollection') ? $result->getCollection() : [];
        $n = 0;
        foreach ($collection as $ent) {
            if (!$ent instanceof Entity) {
                continue;
            }
            $map = method_exists($ent, 'getValueMap')
                ? json_decode(json_encode($ent->getValueMap()) ?: '{}', true)
                : [
                    'id' => $ent->getId(),
                    'name' => $ent->get('name'),
                ];
            if (!is_array($map)) {
                $map = ['id' => $ent->getId()];
            }
            $map['_entityType'] = $ent->getEntityType();
            $rows[] = $map;
            $n++;
            if ($n >= $maxRows) {
                break;
            }
        }

        $total = method_exists($result, 'getTotal') ? (int) $result->getTotal() : count($rows);
        $columns = method_exists($result, 'getColumns') ? $result->getColumns() : null;

        return [
            'type' => 'List',
            'rows' => $rows,
            'total' => $total,
            'count' => count($rows),
            'columns' => $columns,
            'sums' => new stdClass(),
            'totals' => (object) [
                'count' => count($rows),
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gridToBlob(object $result): array
    {
        $raw = method_exists($result, 'toRaw')
            ? $result->toRaw()
            : (object) [];

        $rawArr = json_decode(json_encode($raw) ?: '{}', true) ?: [];
        $sums = $rawArr['sums'] ?? [];
        if (!is_array($sums)) {
            $sums = [];
        }

        return [
            'type' => 'Grid',
            'rows' => [],
            'total' => null,
            'count' => null,
            'columns' => $rawArr['columnList'] ?? [],
            'groupByList' => $rawArr['groupByList'] ?? [],
            'sums' => $sums,
            'totals' => $sums,
            'reportData' => $rawArr['reportData'] ?? new stdClass(),
            'grouping' => $rawArr['grouping'] ?? [],
            'columnNameMap' => $rawArr['columnNameMap'] ?? null,
            'groupValueMap' => $rawArr['groupValueMap'] ?? null,
            'chartDataList' => $rawArr['chartDataList'] ?? null,
            'depth' => $rawArr['depth'] ?? null,
            'entityType' => $rawArr['entityType'] ?? null,
            'raw' => $rawArr,
        ];
    }
}
