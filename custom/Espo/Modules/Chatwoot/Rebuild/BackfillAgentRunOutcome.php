<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\UpdateBuilder;

/**
 * Copies run.outcome out of modelUsage into the stored runOutcome column.
 * New rows get it from the backend writer; this covers rows persisted before
 * the column existed. Only rows with v1 usage can carry an outcome, so once
 * filled each rebuild re-reads just the few v1 runs that never had one.
 */
class BackfillAgentRunOutcome implements RebuildAction
{
    public function __construct(private EntityManager $entityManager) {}

    public function process(): void
    {
        $query = UpdateBuilder::create()
            ->in('ChatwootAiAgentRun')
            ->set(['runOutcome' => Expr::create('AI_RUN_OUTCOME:modelUsage')])
            ->where(['runOutcome' => null, 'usageMetricsVersion!=' => null])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);
    }
}
