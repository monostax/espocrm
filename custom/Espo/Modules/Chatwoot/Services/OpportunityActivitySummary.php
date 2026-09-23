<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\SelectBuilder;

class OpportunityActivitySummary
{
    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private SearchParamsFetcher $searchParamsFetcher,
        private UserTenantResolver $tenants,
        private User $user,
        private Acl $acl,
    ) {}

    public function get(Request $request): array
    {
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) ||
            !$this->acl->checkScope('Opportunity', 'read')) {
            throw new Forbidden();
        }
        try {
            $zone = new DateTimeZone($request->getQueryParam('timeZone') ?: 'UTC');
        } catch (\Exception) {
            throw new BadRequest('Invalid time zone.');
        }
        $now = new DateTimeImmutable('now', $zone);
        $includeIds = $request->getQueryParam('includeIds') !== 'false';
        $scope = $this->selectBuilderFactory->create()->from('Opportunity')
            ->withSearchParams($this->searchParamsFetcher->fetch($request))
            ->withStrictAccessControl()->buildQueryBuilder()
            ->select('id')->order([])->limit(null, null);
        if (!$this->user->isAdmin()) {
            $scope->where(['tenantId' => $this->tenants->resolveTenantIds($this->user)]);
        }

        $opportunities = $this->entityManager->getQueryExecutor()->execute(
            SelectBuilder::create()->clone($scope->build())
                ->select(['id', 'status', 'nextActionId', 'nextActionType'])->distinct()->build()
        )->fetchAll(\PDO::FETCH_ASSOC);
        $rows = [];
        foreach (['Meeting' => 'planned', 'Call' => 'planned', 'Task' => 'actual'] as $type => $filter) {
            if (!$this->acl->checkScope($type, 'read')) {
                continue;
            }
            if (!$this->acl->checkField($type, 'dateEnd') ||
                ($type !== 'Call' && !$this->acl->checkField($type, 'dateEndDate'))) {
                throw new Forbidden();
            }
            $fields = ['id', 'parentId', 'dateEnd'];
            if ($type !== 'Call') {
                $fields[] = 'dateEndDate';
            }
            $query = $this->selectBuilderFactory->create()->from($type)
                ->withPrimaryFilter($filter)->withStrictAccessControl()->buildQueryBuilder()
                ->where(['parentType' => 'Opportunity', 'parentId=s' => $scope->build()])
                ->join('Opportunity', 'nextActionOpportunity', ['nextActionOpportunity.id:' => 'parentId'])
                ->where(Expr::and(
                    Expr::equal(Expr::column('id'), Expr::column('nextActionOpportunity.nextActionId')),
                    Expr::equal(Expr::column('nextActionOpportunity.nextActionType'), $type),
                ))
                ->select($fields)->distinct()->order([])->build();
            foreach ($this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $rows[] = [...$row, 'entityType' => $type];
            }
        }
        return self::summarize($rows, $now, $includeIds, $opportunities);
    }

    /** Exclusive next-step buckets; date-only deadlines remain on time through the user's local day. */
    public static function summarize(
        array $rows,
        DateTimeImmutable $now,
        bool $includeIds,
        array $opportunities = [],
    ): array {
        $today = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $opportunitiesById = array_column($opportunities, null, 'id');
        $opportunityIds = array_keys(array_filter(
            $opportunitiesById,
            fn (array $opportunity) => !in_array($opportunity['status'] ?? null, ['Won', 'Lost'], true),
        ));
        $groups = array_fill_keys(OpportunityActivityBuckets::KEYS, []);
        $groups['noNextAction'] = array_combine($opportunityIds, $opportunityIds);
        foreach ($rows as $row) {
            $opportunity = $opportunitiesById[$row['parentId']] ?? null;
            if (empty($row['id']) || $row['id'] !== ($opportunity['nextActionId'] ?? null) ||
                ($row['entityType'] ?? null) !== ($opportunity['nextActionType'] ?? null)) {
                continue;
            }
            unset($groups['noNextAction'][$row['parentId']]);
            $timestamp = !empty($row['dateEnd'])
                ? new DateTimeImmutable($row['dateEnd'], new DateTimeZone('UTC')) : null;
            $date = !empty($row['dateEndDate'])
                ? $row['dateEndDate'] : $timestamp?->setTimezone($now->getTimezone())->format('Y-m-d');
            $overdue = !empty($row['dateEndDate'])
                ? $date < $today : ($timestamp !== null && $timestamp < $now);
            $key = match (true) {
                $overdue => 'overdue',
                $date === null => 'noDate',
                $date === $today => 'today',
                $date === $tomorrow => 'tomorrow',
                default => 'upcoming',
            };
            $groups[$key][$row['parentId']] = $row['parentId'];
        }

        $summary = [];
        foreach ($groups as $key => $ids) {
            $summary[$key] = ['count' => count($ids)];
            if ($includeIds) {
                $summary[$key]['opportunityIds'] = array_values($ids);
            }
        }
        return $summary;
    }
}
