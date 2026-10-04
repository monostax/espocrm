<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\ORM\EntityManager;
use PDO;
use stdClass;

/** Replace the former Task status without firing activity/automation hooks. Safe to rerun. */
class MigrateTaskPlannedStatus implements RebuildAction
{
    public function __construct(private EntityManager $entityManager) {}

    public function process(): void
    {
        $pdo = $this->entityManager->getPDO();
        // Include soft-deleted tasks so restoring one cannot resurrect the old status.
        $pdo->exec("UPDATE task SET status = 'Planned' WHERE status = 'Not Started'");

        if ($this->entityManager->getDefs()->hasEntity('JourneyStageAction')) {
            $this->migrateActions('journey_stage_action', 'params', "type = 'createTask'", true);
        }
        if ($this->entityManager->getDefs()->hasEntity('Automation')) {
            $this->migrateActions('automation', 'definition', '1 = 1', false);
        }
    }

    private function migrateActions(string $table, string $column, string $where, bool $paramsOnly): void
    {
        $pdo = $this->entityManager->getPDO();
        $rows = $pdo->query("SELECT id, {$column} FROM {$table} WHERE {$where} AND {$column} LIKE '%Not Started%'")
            ->fetchAll(PDO::FETCH_ASSOC);
        $update = $pdo->prepare("UPDATE {$table} SET {$column} = :value WHERE id = :id");
        foreach ($rows as $row) {
            $value = json_decode($row[$column], false, 512, JSON_THROW_ON_ERROR);
            $action = $paramsOnly ? (object) ['type' => 'createTask', 'params' => $value] : $value;
            if (!$this->migrateActionTree($action)) continue;
            $update->execute([
                'id' => $row['id'],
                'value' => json_encode($paramsOnly ? $action->params : $action, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    private function migrateActionTree(mixed $node): bool
    {
        if (!is_array($node) && !$node instanceof stdClass) return false;
        $changed = false;
        if ($node instanceof stdClass && ($node->type ?? null) === 'createTask' &&
            ($node->params->status ?? null) === 'Not Started') {
            $node->params->status = 'Planned';
            $changed = true;
        }
        foreach ($node as $child) {
            if ($this->migrateActionTree($child)) $changed = true;
        }
        return $changed;
    }
}
