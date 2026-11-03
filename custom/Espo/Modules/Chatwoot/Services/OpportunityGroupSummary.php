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
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityEventAccess;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;
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
        'followupStatus' => ['followupStatus', 'followupStatus'],
        'sourceChannel' => ['sourceChannel', 'sourceChannel'],
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
        private OpportunityInboxFilter $inboxes,
        private Config $config,
        private OpportunityEventAccess $eventAccess,
        private User $user,
    ) {}

    public function get(Request $request): array
    {
        $groupBy = $request->getQueryParam('groupBy');
        $groupings = [...array_keys(self::FIELDS), 'none', 'readStatus', 'activity', 'inbox', 'followupUsed', 'followupReply'];
        if (!in_array($groupBy, $groupings, true)) {
            throw new BadRequest('A workspace and valid grouping are required.');
        }
        $scope = $this->buildScope($request);
        if ($groupBy === 'inbox') {
            return $this->inboxGroups($request, $scope);
        }
        if ($groupBy === 'followupReply') {
            return $this->followupReplyGroups($request, $scope);
        }
        $field = self::FIELDS[$groupBy][1] ?? ($groupBy === 'followupUsed' ? 'followupLifetimeCount' : null);
        if ($field && !$this->acl->checkField('Opportunity', $field)) {
            throw new Forbidden();
        }
        // Each group can carry its Open/Won/Lost split, so one request serves a status table.
        $split = $request->getQueryParam('statusSplit') === 'true';
        if ($split && !$this->acl->checkField('Opportunity', 'status')) {
            throw new Forbidden();
        }

        // A semi-join prevents team/link filter joins from multiplying either COUNT or SUM.
        $query = SelectBuilder::create()->from('Opportunity')->where(['id=s' => $scope]);
        $bucket = $this->applyActivityFilter($query, $scope, $request, $groupBy === 'activity');
        $key = match ($groupBy) {
            'none' => Expr::value('all'),
            'readStatus' => $this->readStatus($query, $scope),
            'activity' => Expr::concat('activity:', $bucket),
            'followupStatus' => Expr::if(
                Expr::equal(Expr::column('followupStatus'), 'FollowupActive'),
                'followupStatus:FollowupActive',
                'followupStatus:FollowupInactive',
            ),
            // The lifetime count never resets, unlike the per-cycle followupCount.
            'followupUsed' => Expr::if(
                Expr::greaterOrEqual(Expr::ifNull(Expr::column('followupLifetimeCount'), 0), 1),
                'followupUsed:yes',
                'followupUsed:no',
            ),
            default => Expr::concat($groupBy . ':', Expr::ifNull(Expr::column(self::FIELDS[$groupBy][0]), '')),
        };
        $canReadAmount = $this->acl->checkField('Opportunity', 'amount');
        $query->select([])->select($key, 'groupKey')
            ->select(Expr::count(Expr::column('id')), 'count')
            ->select($canReadAmount ? Expr::sum($this->baseAmount()) : Expr::value(null), 'amount')
            ->group(Expr::alias('groupKey'));
        if ($split) {
            $query->select(Expr::column('status'), 'groupStatus')->group(Expr::alias('groupStatus'));
        }

        $groups = [];
        $rows = $this->entityManager->getQueryExecutor()->execute($query->build());
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $this->addRow(
                $groups,
                $row['groupKey'],
                (int) $row['count'],
                $canReadAmount ? round((float) ($row['amount'] ?? 0), 2) : null,
                $split ? ($row['groupStatus'] ?? '') : null,
            );
        }
        return $this->response($groups, $canReadAmount);
    }

    /**
     * Fold one aggregate row into its group; with a status, also into the group's
     * per-status split.
     *
     * @param array<string, array<string, mixed>> $groups
     */
    private function addRow(array &$groups, string $key, int $count, ?float $amount, ?string $status): void
    {
        $groups[$key] = ($groups[$key] ?? []) + ['count' => 0, 'amount' => $amount === null ? null : 0.0];
        $groups[$key]['count'] += $count;
        if ($amount !== null) {
            $groups[$key]['amount'] = round($groups[$key]['amount'] + $amount, 2);
        }
        if ($status !== null) {
            $groups[$key]['statuses'][$status] = ['count' => $count, 'amount' => $amount];
        }
    }

    /** @param array<string, array<string, mixed>> $groups */
    private function response(array $groups, bool $canReadAmount): array
    {
        foreach ($groups as $key => $group) {
            if (isset($group['statuses'])) {
                $groups[$key]['statuses'] = (object) $group['statuses'];
            }
        }
        return [
            'currency' => $canReadAmount ? $this->currency->getBaseCurrency() : null,
            'groups' => (object) $groups,
        ];
    }

    /**
     * Inbox totals split by status. Inbox membership comes from the conversations an
     * opportunity is linked to, so one opportunity can count in several inboxes.
     */
    private function inboxGroups(Request $request, Select $scope): array
    {
        if (!$this->acl->checkField('Opportunity', 'status')) {
            throw new Forbidden();
        }
        $query = SelectBuilder::create()->from('Opportunity')->where(['id=s' => $scope]);
        $this->applyActivityFilter($query, $scope, $request);
        $canReadAmount = $this->acl->checkField('Opportunity', 'amount');
        $rows = $this->inboxes->summary(
            $query,
            $request->getQueryParam('chatwootAccountId'),
            $canReadAmount ? $this->baseAmount() : null,
        );

        $groups = [];
        foreach ($rows as $row) {
            $key = 'inbox:' . $row['id'];
            $groups[$key] ??= ['name' => $row['name']];
            $this->addRow($groups, $key, $row['count'], $row['amount'], $row['status'] ?? '');
        }
        return $this->response($groups, $canReadAmount);
    }

    /**
     * Automatic follow-up outcome, split by status: no follow-up, followed up
     * without a reply, or the customer replied within 24 hours of a follow-up.
     * Comparing followed-up and untouched opportunities misleads (follow-ups go
     * to leads that went quiet; handoffs to humans stop them), so this measures
     * the follow-up's own funnel instead. Only follow-ups sent since customer
     * messages are recorded count, so a missing reply always means no reply.
     * Replies are message events the user may read, as in the timeline.
     */
    private function followupReplyGroups(Request $request, Select $scope): array
    {
        if (!$this->acl->checkField('Opportunity', 'followupLifetimeCount') ||
            !$this->acl->checkField('Opportunity', 'status') ||
            !$this->acl->checkScope('ChatwootConversation', 'read')) {
            throw new Forbidden();
        }
        $since = (string) $this->config->get(OpportunityMessageEvents::STARTED_AT);
        $sent = ['kind' => 'followup-trigger', 'sentFollowupMessage' => true, 'runAt>=' => $since];
        // Each derived table has one row per opportunity, so neither multiplies.
        $sends = SelectBuilder::create()->from('ChatwootAiAgentRun', 'followupRun')
            ->select('opportunityId')->select(Expr::count(Expr::column('id')), 'followups')
            ->where($sent)->where(['opportunityId=s' => $scope])->group('opportunityId')->build();
        $sinceSend = Expr::subtract(
            Expr::unixTimestamp(Expr::column('createdAt')),
            Expr::unixTimestamp(Expr::column('followupRun.runAt')),
        );
        $replies = SelectBuilder::create()->from('Note', 'reply')
            ->select('parentId')->group('parentId')
            ->join('ChatwootAiAgentRun', 'followupRun', [
                'followupRun.opportunityId:' => 'reply.parentId',
                'followupRun.deleted' => false,
                ...array_combine(array_map(fn ($key) => "followupRun.$key", array_keys($sent)), $sent),
            ])
            ->where([
                'parentType' => 'Opportunity',
                'parentId=s' => $scope,
                'type' => OpportunityStreamEvents::MESSAGE_RECEIVED,
            ])
            ->where($this->eventAccess->where($this->user))
            ->where(Cond::and(Cond::greater($sinceSend, 0), Cond::lessOrEqual($sinceSend, 86400)))
            ->build();

        $query = SelectBuilder::create()->from('Opportunity')->where(['id=s' => $scope])
            ->leftJoin($sends, 'followupSends', Expr::equal(Expr::alias('followupSends.opportunityId'), Expr::column('id')))
            ->leftJoin($replies, 'followupReplies', Expr::equal(Expr::alias('followupReplies.parentId'), Expr::column('id')));
        $this->applyActivityFilter($query, $scope, $request);
        $canReadAmount = $this->acl->checkField('Opportunity', 'amount');
        $query->select([])
            ->select(Expr::if(
                Expr::isNotNull(Expr::alias('followupReplies.parentId')),
                'followupReply:replied',
                Expr::if(Expr::isNotNull(Expr::alias('followupSends.opportunityId')), 'followupReply:noReply', 'followupReply:none'),
            ), 'groupKey')
            ->select(Expr::column('status'), 'groupStatus')
            ->select(Expr::count(Expr::column('id')), 'count')
            ->select($canReadAmount ? Expr::sum($this->baseAmount()) : Expr::value(null), 'amount')
            ->select(Expr::sum(Expr::ifNull(Expr::alias('followupSends.followups'), 0)), 'followups')
            ->group(Expr::alias('groupKey'))->group(Expr::alias('groupStatus'));

        $groups = [];
        $rows = $this->entityManager->getQueryExecutor()->execute($query->build());
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $key = $row['groupKey'];
            $this->addRow($groups, $key, (int) $row['count'],
                $canReadAmount ? round((float) ($row['amount'] ?? 0), 2) : null, $row['groupStatus'] ?? '');
            $groups[$key]['followups'] = ($groups[$key]['followups'] ?? 0) + (int) $row['followups'];
        }
        return ['since' => $since] + $this->response($groups, $canReadAmount);
    }

    /** Shared by group and spreadsheet summaries so both describe the same full view. */
    public function buildScope(Request $request): Select
    {
        $accountId = filter_var($request->getQueryParam('chatwootAccountId'), FILTER_VALIDATE_INT);
        if (!$accountId || $accountId < 1) {
            throw new BadRequest('A workspace is required.');
        }
        // The existing workspace gate checks active user, account ACL, tenant membership,
        // and ambiguous cross-platform IDs. It does not require permission to post.
        $workspace = $this->workspaceAccess->workspace($accountId);
        if (!$this->acl->checkScope('Opportunity', 'read')) {
            throw new Forbidden();
        }
        $params = $this->searchParamsFetcher->fetch($request)
            ->withSelect(['id'])->withOrderBy(null)->withOffset(null)->withMaxSize(null);
        return $this->selectBuilderFactory->create()->from('Opportunity')
            ->withSearchParams($params)->withStrictAccessControl()->buildQueryBuilder()
            // A mandatory AND, independent of client filters and admin ACL bypass.
            ->where(['tenantId' => $workspace->get('tenantId')])
            ->select(['id'])->order([])->limit(null, null)->build();
    }

    public function applyActivityFilter(SelectBuilder $query, Select $scope, Request $request, bool $grouped = false): ?Expr
    {
        $activity = $request->getQueryParam('activity');
        if ($activity === 'noActivities') {
            $activity = 'noNextAction';
        }
        $bucket = null;
        if ($grouped || $activity) {
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
                $query->where(Expr::in($bucket, $activity === 'today' ? ['overdue', 'today'] : [$activity]));
            }
        }

        return $bucket;
    }

    private function readStatus(SelectBuilder $query, Select $scope): Expr
    {
        $unread = SelectBuilder::create()->clone($scope);
        $this->readStates->applyListFilter($unread, onlyUnread: true);
        $query->leftJoin($unread->distinct()->build(), 'summaryUnread',
            Expr::equal(Expr::alias('summaryUnread.id'), Expr::column('id')));
        return Expr::if(Expr::isNull(Expr::alias('summaryUnread.id')), 'read', 'unread');
    }

    public function baseAmount(): Expr
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
