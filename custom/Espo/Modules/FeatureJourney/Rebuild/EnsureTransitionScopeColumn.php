<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Rebuild;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Espo\Core\Utils\Database\Helper;
use Espo\Core\Utils\Database\Schema\RebuildAction;
use Espo\Core\Utils\Log;

/**
 * Bootstraps the scope column before the main schema diff and ORM-backed rebuild actions.
 */
class EnsureTransitionScopeColumn implements RebuildAction
{
    private const TABLE = 'journey_transition';
    private const COLUMN = 'scope';
    private const SOURCE_STAGE_COLUMN = 'from_stage_id';

    public function __construct(
        private Helper $helper,
        private Log $log,
    ) {
    }

    public function process(DbalSchema $oldSchema, DbalSchema $newSchema): void
    {
        if (!$oldSchema->hasTable(self::TABLE) || !$newSchema->hasTable(self::TABLE)) {
            return;
        }

        $oldTable = $oldSchema->getTable(self::TABLE);
        $newTable = $newSchema->getTable(self::TABLE);

        if (!$newTable->hasColumn(self::COLUMN)) {
            return;
        }

        $connection = $this->helper->getDbalConnection();
        $platform = $connection->getDatabasePlatform();
        $targetColumn = $newTable->getColumn(self::COLUMN);

        if (!$oldTable->hasColumn(self::COLUMN)) {
            $connection->executeStatement(sprintf(
                'ALTER TABLE %s ADD %s',
                $oldTable->getQuotedName($platform),
                $platform->getColumnDeclarationSQL(
                    $targetColumn->getQuotedName($platform),
                    $targetColumn->toArray(),
                ),
            ));

            // SchemaManager captured the old schema before this action. Keep its snapshot
            // synchronized so the following comparator does not emit a duplicate ADD COLUMN.
            $this->addColumnToSchemaSnapshot($oldTable, $targetColumn);
            $this->log->info('FeatureJourney: added journey_transition.scope before schema rebuild.');
        }

        if (!$oldTable->hasColumn(self::SOURCE_STAGE_COLUMN)) {
            return;
        }

        $scope = $oldTable->getColumn(self::COLUMN)->getQuotedName($platform);
        $fromStageId = $oldTable->getColumn(self::SOURCE_STAGE_COLUMN)->getQuotedName($platform);

        $count = $connection->executeStatement(sprintf(
            "UPDATE %s
             SET %s = CASE
                 WHEN %s IS NOT NULL AND %s <> '' THEN 'stage'
                 ELSE 'enrollment'
             END
             WHERE %s IS NULL
                OR %s = ''
                OR (%s IS NOT NULL AND %s <> '' AND %s <> 'stage')",
            $oldTable->getQuotedName($platform),
            $scope,
            $fromStageId,
            $fromStageId,
            $scope,
            $scope,
            $fromStageId,
            $fromStageId,
            $scope,
        ));

        if ($count > 0) {
            $this->log->info("FeatureJourney: backfilled {$count} transition scope(s) before schema rebuild.");
        }
    }

    private function addColumnToSchemaSnapshot(
        \Doctrine\DBAL\Schema\Table $table,
        Column $column,
    ): void {
        $options = $column->toArray();
        unset($options['name'], $options['type']);

        $table->addColumn(
            $column->getName(),
            $column->getType()->getName(),
            $options,
        );
    }
}
