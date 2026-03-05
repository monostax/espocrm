<?php

namespace Espo\Modules\FeatureIntegrationSimplesAgenda\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * One-time migration: convert dataNascimento, dataCadastro, dataAtualizacao
 * from DD/MM/YYYY varchar strings to YYYY-MM-DD date format.
 *
 * Safe to re-run: only touches rows that still contain a '/' character.
 */
class MigrateDateFieldsToDate implements RebuildAction
{
    private const TABLE = 'simples_agenda_cliente';

    private const DATE_COLUMNS = [
        'data_nascimento',
        'data_cadastro',
        'data_atualizacao',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $pdo = $this->entityManager->getPDO();

        foreach (self::DATE_COLUMNS as $column) {
            $count = $pdo->exec(
                "UPDATE `" . self::TABLE . "` " .
                "SET `{$column}` = DATE_FORMAT(STR_TO_DATE(`{$column}`, '%d/%m/%Y'), '%Y-%m-%d') " .
                "WHERE `{$column}` IS NOT NULL AND `{$column}` LIKE '%/%'"
            );

            $this->log->info("MigrateDateFieldsToDate: Converted {$count} rows in column {$column}");
        }
    }
}
