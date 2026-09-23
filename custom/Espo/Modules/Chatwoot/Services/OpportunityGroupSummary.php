<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Currency\ConfigDataProvider;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;
use PDO;

/** One aggregate over the selected workspace's readable opportunities, never hydrated records. */
class OpportunityGroupSummary
{
    private const FIELDS = [
        'stage' => ['opportunityStageId', 'opportunityStage'],
        'funnel' => ['funnelId', 'funnel'],
        'assignee' => ['assignedUserId', 'assignedUser'],
        'status' => ['status', 'status'],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private SearchParamsFetcher $searchParamsFetcher,
        private OpportunityBulkPostAccess $workspaceAccess,
        private OpportunityReadStateService $readStates,
        private OpportunityActivityBuckets $activityBuckets,
        private ConfigDataProvider $currency,
        private Acl $acl,
    ) {}

    public function get(Request $request): array
    {
        $accountId = filter_var($request->getQueryParam('chatwootAccountId'), FILTER_VALIDATE_INT);
        $groupBy = $request->getQueryParam('groupBy');
        if (!$accountId || $accountId < 1 ||
            !in_array($groupBy, [...array_keys(self::FIELDS), 'none', 'readStatus', 'activity'], true)) {
            throw new BadRequest('A workspace and valid grouping are required.');
        }
        // The existing workspace gate checks active user, account ACL, tenant membership,
        // and ambiguous cross-platform IDs. It does not require permission to post.
        $workspace = $this->workspaceAccess->workspace($accountId);
        if (!$this->acl->checkScope('Opportunity', 'read')) {
            throw new Forbidden();
        }
        if (isset(self::FIELDS[$groupBy]) && !$this->acl->checkField('Opportunity', self::FIELDS[$groupBy][1])) {
            throw new Forbidden();
        }

        $params = $this->searchParamsFetcher->fetch($request)
            ->withSelect(['id'])->withOrderBy(null)->withOffset(null)->withMaxSize(null);
        $scope = $this->selectBuilderFactory->create()->from('Opportunity')
            ->withSearchParams($params)->withStrictAccessControl()->buildQueryBuilder()
            // A mandatory AND, independent of client filters and admin ACL bypass.
            ->where(['tenantId' => $workspace->get('tenantId')])
            ->select(['id'])->order([])->limit(null, null)->build();

        // A semi-join prevents team/link filter joins from multiplying either COUNT or SUM.
        $query = SelectBuilder::create()->from('Opportunity')->where(['id=s' => $scope]);
        $activity = $request->getQueryParam('activity');
        if ($activity === 'noActivities') {
            $activity = 'noNextAction';
        }
        $bucket = null;
        if ($groupBy === 'activity' || $activity) {
            if ($activity && !in_array($activity, OpportunityActivityBuckets::KEYS, true)) {
                throw new BadRequest('Invalid activity bucket.');
            }
            try {
                $zone = new DateTimeZone($request->getQueryParam('timeZone') ?: 'UTC');
            } catch (\Exception) {
                throw new BadRequest('Invalid time zone.');
            }
            $bucket = $this->activityBuckets->apply($query, $scope, new DateTimeImmutable('now', $zone));
            if ($activity) {
                $query->where(Expr::equal($bucket, $activity));
            }
        }

        $key = match ($groupBy) {
            'none' => Expr::value('all'),
            'readStatus' => $this->readStatus($query, $scope),
            'activity' => Expr::concat('activity:', $bucket),
            default => Expr::concat($groupBy . ':', Expr::ifNull(Expr::column(self::FIELDS[$groupBy][0]), '')),
        };
        $canReadAmount = $this->acl->checkField('Opportunity', 'amount');
        $query->select([])->select($key, 'groupKey')
            ->select(Expr::count(Expr::column('id')), 'count')
            ->select($canReadAmount ? Expr::sum($this->baseAmount()) : Expr::value(null), 'amount')
            ->group(Expr::alias('groupKey'));

        $groups = [];
        $rows = $this->entityManager->getQueryExecutor()->execute($query->build());
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $groups[$row['groupKey']] = [
                'count' => (int) $row['count'],
                'amount' => $canReadAmount ? round((float) ($row['amount'] ?? 0), 2) : null,
            ];
        }
        return [
            'currency' => $canReadAmount ? $this->currency->getBaseCurrency() : null,
            'groups' => (object) $groups,
        ];
    }

    private function readStatus(SelectBuilder $query, Select $scope): Expr
    {
        $unread = SelectBuilder::create()->clone($scope);
        $this->readStates->applyListFilter($unread, onlyUnread: true);
        $query->leftJoin($unread->distinct()->build(), 'summaryUnread',
            Expr::equal(Expr::alias('summaryUnread.id'), Expr::column('id')));
        return Expr::if(Expr::isNull(Expr::alias('summaryUnread.id')), 'read', 'unread');
    }

    private function baseAmount(): Expr
    {
        // amountConverted is in defaultCurrency, which can differ from baseCurrency.
        // Compile the configured currency->base rates into SQL constants: no rate/API
        // query per opportunity, and no dependency on the currency join mode.
        $rates = [];
        foreach ($this->currency->getCurrencyRates()->toAssoc() as $code => $rate) {
            array_push($rates, $code, $rate);
        }
        return Expr::multiply(Expr::column('amount'), Expr::map(Expr::column('amountCurrency'), ...[...$rates, 0]));
    }
}
