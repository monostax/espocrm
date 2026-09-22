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

        $opportunityIds = $this->entityManager->getQueryExecutor()->execute($scope->build())->fetchAll(\PDO::FETCH_COLUMN);
        $rows = [];
        foreach (['Meeting' => 'planned', 'Call' => 'planned', 'Task' => 'actual'] as $type => $filter) {
            if (!$this->acl->checkScope($type, 'read')) {
                continue;
            }
            if (!$this->acl->checkField($type, 'dateEnd') ||
                ($type !== 'Call' && !$this->acl->checkField($type, 'dateEndDate'))) {
                throw new Forbidden();
            }
            $fields = ['parentId', 'dateEnd'];
            if ($type !== 'Call') {
                $fields[] = 'dateEndDate';
            }
            $query = $this->selectBuilderFactory->create()->from($type)
                ->withPrimaryFilter($filter)->withStrictAccessControl()->buildQueryBuilder()
                ->where(['parentType' => 'Opportunity', 'parentId=s' => $scope->build()])
                ->select($fields)->distinct()->order([])->build();
            array_push($rows, ...$this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC));
        }
        return self::summarize($rows, $now, $includeIds, $opportunityIds);
    }

    /** Exclusive opportunity buckets; date-only deadlines remain on time through the user's local day. */
    public static function summarize(
        array $rows,
        DateTimeImmutable $now,
        bool $includeIds,
        array $opportunityIds = [],
    ): array {
        $today = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $groups = array_fill_keys(['overdue', 'today', 'tomorrow', 'upcoming', 'noDate', 'noActivities'], []);
        // Scoped opportunities without a pending activity remain in the last bucket.
        $groups['noActivities'] = array_combine($opportunityIds, $opportunityIds);
        foreach ($rows as $row) {
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

        // Prioritize the earliest pending deadline across all activity types.
        // Undated activities only qualify when no dated activity takes priority.
        $seen = [];
        $summary = [];
        foreach ($groups as $key => $ids) {
            $ids = array_diff_key($ids, $seen);
            $seen += $ids;
            $summary[$key] = ['count' => count($ids)];
            if ($includeIds) {
                $summary[$key]['opportunityIds'] = array_values($ids);
            }
        }
        return $summary;
    }
}
