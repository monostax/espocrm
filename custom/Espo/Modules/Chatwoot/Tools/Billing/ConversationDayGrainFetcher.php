<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Tools\Billing;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use RuntimeException;

/**
 * ACL-aware fetcher of conversation/opportunity × calendar-day × kind counts.
 *
 * Opportunity mentions are billed against their opportunity even without a
 * conversation. Other runs still require a resolvable conversation.
 */
final class ConversationDayGrainFetcher
{
    private const ENTITY_TYPE = 'ChatwootAiAgentRun';
    private const NO_TENANT_KEY = '__no_tenant__';

    /** Soft ceiling on kind-level groups before PHP merge. */
    private const MAX_KIND_GROUPS = 50000;

    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private DayExpression $dayExpression,
    ) {}

    public static function noTenantKey(): string
    {
        return self::NO_TENANT_KEY;
    }

    private function scopeIdExpression(): Expr
    {
        return Expr::if(
            Expr::equal(Expr::column('kind'), 'opportunity-mention'),
            Expr::column('opportunityId'),
            Expr::column('conversationId'),
        );
    }

    private function scopeTypeExpression(): Expr
    {
        return Expr::if(Expr::equal(Expr::column('kind'), 'opportunity-mention'), 'opportunity', 'conversation');
    }

    /**
     * @return list<ConversationDayGrain>
     */
    public function fetch(?WhereItem $where, ?User $user): array
    {
        $searchParams = SearchParams::create();

        if ($where) {
            $searchParams = $searchParams->withWhere($where);
        }

        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->from(self::ENTITY_TYPE)
            ->withStrictAccessControl()
            ->withSearchParams($searchParams);

        if ($user) {
            $selectBuilder->forUser($user);
        }

        try {
            $queryBuilder = $selectBuilder->buildQueryBuilder();
        } catch (BadRequest|Forbidden $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        $dayExpr = Expr::create($this->dayExpression->build('runAt'));
        $tenantExpr = Expr::column('tenantId');
        $scopeId = $this->scopeIdExpression();
        $scopeType = $this->scopeTypeExpression();

        $queryBuilder
            ->where(Expr::isNotNull($scopeId))
            ->where(Cond::notEqual($scopeId, ''))
            ->select($scopeId, 'scopeId')
            ->select($scopeType, 'scopeType')
            ->select($dayExpr, 'dayBucket')
            ->select($tenantExpr, 'tenantId')
            ->select(Expr::column('kind'), 'kind')
            ->select(Expr::create('COUNT:id'), 'cnt')
            ->group($scopeId)
            ->group($scopeType)
            ->group($dayExpr)
            ->group($tenantExpr)
            ->group(Expr::column('kind'))
            ->limit(0, self::MAX_KIND_GROUPS);

        $sth = $this->entityManager->getQueryExecutor()->execute($queryBuilder->build());

        return ConversationDayGrain::fromGroupedCounts($sth->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Billed run ids for a (day[, tenant]) bucket under ACL. Drill-downs show
     * the actual engagements and their conversation/opportunity/source links.
     *
     * @return list<string>
     */
    public function fetchRunIdsForBucket(
        SearchParams $searchParams,
        ?User $user,
        ?string $day,
        ?string $tenantId = null,
        int $limit = 200,
    ): array {
        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->from(self::ENTITY_TYPE)
            ->withStrictAccessControl()
            ->withSearchParams($searchParams);

        if ($user) {
            $selectBuilder->forUser($user);
        }

        try {
            $queryBuilder = $selectBuilder->buildQueryBuilder();
        } catch (BadRequest|Forbidden $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        if ($tenantId !== null) {
            if ($tenantId === self::NO_TENANT_KEY) {
                $queryBuilder->where(Expr::isNull(Expr::column('tenantId')));
            } else {
                $queryBuilder->where(['tenantId' => $tenantId]);
            }
        }

        if ($day !== null && $day !== '-') {
            $queryBuilder->where(Cond::equal(
                Expr::create($this->dayExpression->build('runAt')),
                Expr::value($day)
            ));
        }

        $queryBuilder
            ->where(Expr::isNotNull($this->scopeIdExpression()))
            ->where(Cond::notEqual($this->scopeIdExpression(), ''))
            ->select(Expr::column('id'), 'id')
            ->order('runAt', 'DESC')
            ->limit(0, $limit);

        $sth = $this->entityManager->getQueryExecutor()->execute($queryBuilder->build());

        $ids = [];

        foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['id'])) {
                $ids[] = (string) $row['id'];
            }
        }

        return $ids;
    }
}
