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
 * ACL-aware fetcher of conversation × calendar-day × kind counts.
 *
 * Rows without a resolvable conversationId are excluded (nothing to invoice
 * against); they remain visible in the token/ops reports on ChatwootAiAgentRun.
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
        $tenantExpr = Expr::ifNull(
            Expr::column('tenantId'),
            self::NO_TENANT_KEY
        );

        $queryBuilder
            ->where(Expr::isNotNull(Expr::column('conversationId')))
            ->select(Expr::column('conversationId'), 'conversationId')
            ->select($dayExpr, 'dayBucket')
            ->select($tenantExpr, 'tenantId')
            ->select(Expr::column('kind'), 'kind')
            ->select(Expr::create('COUNT:id'), 'cnt')
            ->group(Expr::column('conversationId'))
            ->group($dayExpr)
            ->group($tenantExpr)
            ->group(Expr::column('kind'))
            ->limit(0, self::MAX_KIND_GROUPS);

        $sth = $this->entityManager->getQueryExecutor()->execute($queryBuilder->build());

        /** @var array<string, array{conversationId: string, dayBucket: string, tenantId: ?string, customer: int, nonCustomer: int}> $merged */
        $merged = [];

        foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $conversationId = (string) ($row['conversationId'] ?? '');
            $dayBucket = $row['dayBucket'] !== null ? (string) $row['dayBucket'] : '-';
            $tenantRaw = $row['tenantId'] !== null ? (string) $row['tenantId'] : self::NO_TENANT_KEY;
            $kind = (string) ($row['kind'] ?? '');
            $cnt = (int) ($row['cnt'] ?? 0);

            if ($conversationId === '' || $cnt <= 0) {
                continue;
            }

            $key = $conversationId . "\0" . $dayBucket . "\0" . $tenantRaw;

            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'conversationId' => $conversationId,
                    'dayBucket' => $dayBucket,
                    'tenantId' => $tenantRaw,
                    'customer' => 0,
                    'nonCustomer' => 0,
                ];
            }

            if ($kind === Pricing::CUSTOMER_MESSAGE_KIND) {
                $merged[$key]['customer'] += $cnt;
            } else {
                $merged[$key]['nonCustomer'] += $cnt;
            }
        }

        $grains = [];

        foreach ($merged as $item) {
            $grains[] = new ConversationDayGrain(
                $item['conversationId'],
                $item['dayBucket'],
                $item['tenantId'],
                $item['customer'],
                $item['nonCustomer'],
            );
        }

        return $grains;
    }

    /**
     * Distinct conversation ids for a (day[, tenant]) bucket under ACL —
     * used by drill-down sub-reports.
     *
     * @return list<string>
     */
    public function fetchConversationIdsForBucket(
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
            ->where(Expr::isNotNull(Expr::column('conversationId')))
            ->select(Expr::column('conversationId'), 'conversationId')
            ->group(Expr::column('conversationId'))
            ->limit(0, $limit);

        $sth = $this->entityManager->getQueryExecutor()->execute($queryBuilder->build());

        $ids = [];

        foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['conversationId'])) {
                $ids[] = (string) $row['conversationId'];
            }
        }

        return $ids;
    }
}
