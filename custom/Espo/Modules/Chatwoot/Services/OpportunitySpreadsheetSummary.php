<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Currency\ConfigDataProvider;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\SearchParams;
use Espo\Modules\Chatwoot\Classes\Select\Opportunity\StreamActivity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\SelectBuilder;
use PDO;

class OpportunitySpreadsheetSummary
{
    // Client column -> value attribute, field ACL, value type.
    private const FIELDS = [
        'name' => ['name', 'name', 'text'],
        'accountName' => ['accountName', 'account', 'text'],
        'contactName' => ['contactName', 'contact', 'text'],
        'funnelName' => ['funnelName', 'funnel', 'text'],
        'opportunityStageId' => ['opportunityStageName', 'opportunityStage', 'text'],
        'status' => ['status', 'status', 'text'],
        'assignedUserId' => ['assignedUserName', 'assignedUser', 'text'],
        'amount' => ['amount', 'amount', 'number'],
        'amountCurrency' => ['amountCurrency', 'amount', 'text'],
        'probability' => ['probability', 'probability', 'number'],
        'closeDate' => ['closeDate', 'closeDate', 'date'],
        'createdAt' => ['createdAt', 'createdAt', 'date'],
        'streamUpdatedAt' => ['streamUpdatedAt', 'chatwootStreamUpdatedAt', 'date'],
        'description' => ['description', 'description', 'text'],
    ];
    private const COUNTS = ['count', 'filled', 'empty', 'unique'];
    private const NUMERIC = ['sum', 'avg', 'min', 'max'];

    public function __construct(
        private EntityManager $entityManager,
        private OpportunityGroupSummary $summaryQuery,
        private ConfigDataProvider $currency,
        private StreamActivity $streamActivity,
        private Acl $acl,
    ) {}

    public function get(Request $request): array
    {
        $calculations = json_decode($request->getQueryParam('calculations') ?? '{}', true);
        if (!is_array($calculations) || count($calculations) > count(self::FIELDS)) {
            throw new BadRequest('Invalid summary calculations.');
        }
        foreach ($calculations as $field => $operation) {
            $definition = self::FIELDS[$field] ?? null;
            if (!$definition || !in_array($operation, [
                ...self::COUNTS, ...($definition[2] === 'number' ? self::NUMERIC : []),
            ], true)) {
                throw new BadRequest('Unsupported summary calculation.');
            }
        }

        $scope = $this->summaryQuery->buildScope($request);
        $query = SelectBuilder::create()->from('Opportunity')->where(['id=s' => $scope]);
        $this->summaryQuery->applyActivityFilter($query, $scope, $request);
        if (isset($calculations['streamUpdatedAt']) &&
            $calculations['streamUpdatedAt'] !== 'count' &&
            $this->acl->checkField('Opportunity', 'chatwootStreamUpdatedAt')) {
            $this->streamActivity->apply($query, SearchParams::fromRaw(['orderBy' => 'chatwootStreamUpdatedAt']));
        }
        $query->select(['id'])->order([]);
        $select = ['COUNT(id) AS total'];
        $allowed = [];
        foreach ($calculations as $field => $operation) {
            [$attribute, $aclField, $type] = self::FIELDS[$field];
            if (!$this->acl->checkField('Opportunity', $aclField)) {
                continue;
            }
            // Generated aliases and fixed SQL operations only; client strings never enter SQL.
            $alias = 'v' . count($allowed);
            $value = Expr::column($attribute);
            if ($field === 'streamUpdatedAt') {
                $value = Expr::create('COALESCE:(chatwootLatestEntry.createdAt,modifiedAt,createdAt)');
            } elseif ($field === 'amount' && in_array($operation, self::NUMERIC, true)) {
                $value = $this->summaryQuery->baseAmount();
            } elseif ($type === 'text') {
                $value = Expr::nullIf(Expr::trim($value), '');
            }
            $query->select($operation === 'count' ? Expr::value(1) : $value, $alias);
            $aggregate = match ($operation) {
                'count' => 'COUNT(id)',
                'filled' => "COUNT($alias)",
                'empty' => "COUNT(id) - COUNT($alias)",
                'unique' => "COUNT(DISTINCT $alias)",
                'sum' => "COALESCE(SUM($alias), 0)",
                'avg' => "AVG($alias)",
                'min' => "MIN($alias)",
                'max' => "MAX($alias)",
            };
            $select[] = "$aggregate AS $alias";
            $allowed[$field] = $alias;
        }
        // Keep COUNT(DISTINCT ...) portable without hydrating rows or running one query per column.
        $sql = $this->entityManager->getQueryComposer()->compose($query->build());
        $row = $this->entityManager->getSqlExecutor()
            ->execute('SELECT ' . implode(', ', $select) . " FROM ($sql) spreadsheet")
            ->fetch(PDO::FETCH_ASSOC);
        $values = [];
        foreach ($calculations as $field => $operation) {
            $value = isset($allowed[$field]) ? $row[$allowed[$field]] : null;
            $values[$field] = $value === null ? null : (
                in_array($operation, self::COUNTS, true) ? (int) $value : (float) $value
            );
        }
        return [
            'total' => (int) $row['total'],
            'currency' => $this->acl->checkField('Opportunity', 'amount') ? $this->currency->getBaseCurrency() : null,
            'values' => (object) $values,
            'unavailable' => array_values(array_diff(array_keys($calculations), array_keys($allowed))),
        ];
    }
}
