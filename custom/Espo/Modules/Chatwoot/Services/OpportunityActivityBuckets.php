<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;

/** SQL equivalent of OpportunityActivitySummary::summarize, without exporting IDs or activity rows. */
class OpportunityActivityBuckets
{
    public const KEYS = ['overdue', 'noNextAction', 'today', 'tomorrow', 'upcoming', 'noDate'];

    public function __construct(private SelectBuilderFactory $selectBuilderFactory, private Acl $acl) {}

    public function apply(SelectBuilder $query, Select $scope, DateTimeImmutable $now): Expr
    {
        $ranks = [];
        foreach (['Meeting' => 'planned', 'Call' => 'planned', 'Task' => 'actual'] as $type => $filter) {
            if (!$this->acl->checkScope($type, 'read')) {
                continue;
            }
            if (!$this->acl->checkField($type, 'dateEnd') ||
                ($type !== 'Call' && !$this->acl->checkField($type, 'dateEndDate'))) {
                throw new Forbidden();
            }
            // Only the selected pending step can contribute a deadline. Grouping
            // keeps ACL join multiplicity from duplicating the opportunity.
            $activities = $this->selectBuilderFactory->create()->from($type)
                ->withPrimaryFilter($filter)->withStrictAccessControl()->buildQueryBuilder()
                ->where(['parentType' => 'Opportunity', 'parentId=s' => $scope])
                ->join('Opportunity', 'nextActionOpportunity', ['nextActionOpportunity.id:' => 'parentId'])
                ->where(Expr::and(
                    Expr::equal(Expr::column('id'), Expr::column('nextActionOpportunity.nextActionId')),
                    Expr::equal(Expr::column('nextActionOpportunity.nextActionType'), $type),
                ))
                ->select(['parentId'])->select(Expr::min($this->rank($type, $now)), 'bucketRank')
                ->group('parentId')->order([])->limit(null, null)->build();
            $alias = 'summary' . $type;
            $query->leftJoin($activities, $alias,
                Expr::equal(Expr::alias($alias . '.parentId'), Expr::column('id')));
            $ranks[] = Expr::alias($alias . '.bucketRank');
        }
        // The ID/type reference selects at most one activity across all types.
        // A missing or unreadable pending step belongs only to noNextAction.
        $rank = $ranks ? Expr::coalesce(...[...$ranks, Expr::value(1)]) : Expr::value(1);
        // Closed opportunities do not need a next step, but selected pending steps still count.
        $query->where(Expr::or(
            Expr::notEqual($rank, 1),
            Expr::notIn(Expr::ifNull(Expr::column('status'), ''), ['Won', 'Lost']),
        ));
        $map = [];
        foreach (self::KEYS as $index => $key) {
            array_push($map, $index, $key);
        }
        return Expr::map($rank, ...$map);
    }

    private function rank(string $type, DateTimeImmutable $now): Expr
    {
        $utc = new DateTimeZone('UTC');
        $tomorrow = $now->setTime(0, 0)->modify('+1 day');
        $dayAfter = $tomorrow->modify('+1 day');
        $timestamp = Expr::column('dateEnd');
        $rank = Expr::switch(
            Expr::isNull($timestamp), 5,
            Expr::less($timestamp, $now->setTimezone($utc)->format('Y-m-d H:i:s')), 0,
            Expr::less($timestamp, $tomorrow->setTimezone($utc)->format('Y-m-d H:i:s')), 2,
            Expr::less($timestamp, $dayAfter->setTimezone($utc)->format('Y-m-d H:i:s')), 3,
            4,
        );
        if ($type === 'Call') {
            return $rank;
        }
        // Date-only deadlines stay on time for the entire local day, including DST days.
        $date = Expr::column('dateEndDate');
        return Expr::switch(
            Expr::isNull($date), $rank,
            Expr::less($date, $now->format('Y-m-d')), 0,
            Expr::equal($date, $now->format('Y-m-d')), 2,
            Expr::equal($date, $tomorrow->format('Y-m-d')), 3,
            4,
        );
    }
}
