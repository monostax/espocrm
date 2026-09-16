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

        $summary = self::summarize([], $now, $includeIds);
        foreach (['Meeting' => 'planned', 'Call' => 'planned', 'Task' => 'actual'] as $type => $filter) {
            if (!$this->acl->checkScope($type, 'read')) {
                continue;
            }
            if (!$this->acl->checkField($type, 'dateEnd') ||
                ($type !== 'Call' && !$this->acl->checkField($type, 'dateEndDate'))) {
                throw new Forbidden();
            }
            $fields = ['dateEnd'];
            if ($type !== 'Call') {
                $fields[] = 'dateEndDate';
            }
            if ($includeIds) {
                $fields[] = 'parentId';
            }
            $query = $this->selectBuilderFactory->create()->from($type)
                ->withPrimaryFilter($filter)->withStrictAccessControl()->buildQueryBuilder()
                ->where(['parentType' => 'Opportunity', 'parentId=s' => $scope->build()])
                ->select([...$fields, ['COUNT_DISTINCT:id', 'count']])->group($fields)->order([])->build();
            $rows = $this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC);
            foreach (self::summarize($rows, $now, $includeIds) as $key => $group) {
                $summary[$key]['count'] += $group['count'];
                if ($includeIds) {
                    $summary[$key]['opportunityIds'] = array_values(array_unique([
                        ...$summary[$key]['opportunityIds'], ...$group['opportunityIds'],
                    ]));
                }
            }
        }
        return $summary;
    }

    /** Calendar boundaries match the client: Monday weeks, exclusive ends, date-only deadlines until midnight. */
    public static function summarize(array $rows, DateTimeImmutable $now, bool $includeIds): array
    {
        $day = $now->setTime(0, 0);
        $week = $day->modify('-' . ((int) $day->format('N') - 1) . ' days');
        $month = $day->modify('first day of this month');
        $ranges = [
            'today' => [$day, $day->modify('+1 day')],
            'tomorrow' => [$day->modify('+1 day'), $day->modify('+2 days')],
            'thisWeek' => [$week, $week->modify('+1 week')],
            'nextWeek' => [$week->modify('+1 week'), $week->modify('+2 weeks')],
            'thisMonth' => [$month, $month->modify('+1 month')],
            'nextMonth' => [$month->modify('+1 month'), $month->modify('+2 months')],
        ];
        $groups = array_fill_keys(['overdue', ...array_keys($ranges), 'noDate'],
            $includeIds ? ['count' => 0, 'opportunityIds' => []] : ['count' => 0]);
        foreach ($rows as $row) {
            $timestamp = !empty($row['dateEnd'])
                ? new DateTimeImmutable($row['dateEnd'], new DateTimeZone('UTC')) : null;
            $date = !empty($row['dateEndDate'])
                ? $row['dateEndDate'] : $timestamp?->setTimezone($now->getTimezone())->format('Y-m-d');
            $overdue = !empty($row['dateEndDate'])
                ? $date < $day->format('Y-m-d') : ($timestamp !== null && $timestamp < $now);
            $keys = $overdue ? ['overdue'] : [];
            if ($date === null) {
                $keys[] = 'noDate';
            } else {
                foreach ($ranges as $key => [$start, $end]) {
                    if ($date >= $start->format('Y-m-d') && $date < $end->format('Y-m-d')) {
                        $keys[] = $key;
                    }
                }
            }
            foreach ($keys as $key) {
                $groups[$key]['count'] += (int) $row['count'];
                if ($includeIds) {
                    $groups[$key]['opportunityIds'][$row['parentId']] = $row['parentId'];
                }
            }
        }
        if ($includeIds) {
            foreach ($groups as &$group) {
                $group['opportunityIds'] = array_values($group['opportunityIds']);
            }
        }
        return $groups;
    }
}
