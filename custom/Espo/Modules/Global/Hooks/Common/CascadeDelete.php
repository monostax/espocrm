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

namespace Espo\Modules\Global\Hooks\Common;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Log;

/**
 * Generic cascade delete hook that reads configuration from entity metadata.
 * 
 * Automatically handles deletion of child entities and junction table cleanup
 * when any entity is deleted, based on the `cascadeDelete` configuration in entityDefs.
 * 
 * Example metadata configuration:
 * ```json
 * {
 *     "cascadeDelete": {
 *         "links": ["conversations", "messages"],
 *         "junctionTables": [
 *             {"table": "EntityRelation", "column": "entityId"}
 *         ]
 *     }
 * }
 * ```
 */
class CascadeDelete
{
    /**
     * Run early (order 5) so it executes before entity-specific cleanup hooks
     * that may need to access related data.
     */
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Log $log
    ) {}

    /**
     * Before removing an entity, cascade delete related entities and clean up junction tables.
     *
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        // Skip if this is a silent remove (internal operation / cascade from parent)
        if (!empty($options['silent'])) {
            return;
        }

        $entityType = $entity->getEntityType();
        $config = $this->metadata->get(['entityDefs', $entityType, 'cascadeDelete']);

        if (!$config) {
            return;
        }

        $entityId = $entity->getId();
        $this->log->debug("CascadeDelete: Processing cascade delete for {$entityType} {$entityId}");

        // Delete linked entities (hasMany/hasOne relationships)
        $links = $config['links'] ?? [];
        foreach ($links as $link) {
            $this->deleteLinkedEntities($entity, $link);
        }

        // Clean up junction tables
        $junctionTables = $config['junctionTables'] ?? [];
        foreach ($junctionTables as $junction) {
            $this->cleanJunctionTable($entity, $junction);
        }

        // Clean up EntityTeam records for this entity
        $this->cleanEntityTeam($entity);

        $this->log->debug("CascadeDelete: Completed cascade delete for {$entityType} {$entityId}");
    }

    /**
     * Delete all entities linked via a hasMany or hasOne relationship.
     * 
     * Uses removeEntity() which triggers the cascade delete hook on child entities,
     * enabling recursive multi-level cascade.
     *
     * @param Entity $entity
     * @param string $link
     */
    private function deleteLinkedEntities(Entity $entity, string $link): void
    {
        $entityType = $entity->getEntityType();
        $entityId = $entity->getId();

        // Get link definition from metadata
        $linkDefs = $this->metadata->get(['entityDefs', $entityType, 'links', $link]);

        if (!$linkDefs) {
            $this->log->warning("CascadeDelete: Link '{$link}' not found in {$entityType} metadata");
            return;
        }

        $linkType = $linkDefs['type'] ?? null;
        $foreignEntityType = $linkDefs['entity'] ?? null;

        if (!$foreignEntityType) {
            $this->log->warning("CascadeDelete: Link '{$link}' in {$entityType} has no entity defined");
            return;
        }

        if ($linkType === 'hasMany') {
            $this->deleteHasManyLinkedEntities($entity, $link, $foreignEntityType);
        } elseif ($linkType === 'hasOne') {
            $this->deleteHasOneLinkedEntity($entity, $link, $foreignEntityType);
        } else {
            $this->log->debug("CascadeDelete: Skipping link '{$link}' with type '{$linkType}' (only hasMany/hasOne supported)");
        }
    }

    /**
     * Delete all entities in a hasMany relationship.
     *
     * @param Entity $entity
     * @param string $link
     * @param string $foreignEntityType
     */
    private function deleteHasManyLinkedEntities(Entity $entity, string $link, string $foreignEntityType): void
    {
        $entityType = $entity->getEntityType();
        $entityId = $entity->getId();
        $linkDefs = $this->metadata->get(['entityDefs', $entityType, 'links', $link]);
        $foreignKey = $linkDefs['foreign'] ?? null;

        // Determine the foreign key column name
        // Priority: 1) explicit foreignKey, 2) lookup belongsTo on foreign entity, 3) convention
        $foreignKeyColumn = $linkDefs['foreignKey'] ?? null;

        if (!$foreignKeyColumn) {
            // Try to find the belongsTo link on the foreign entity (most accurate method)
            $foreignLinks = $this->metadata->get(['entityDefs', $foreignEntityType, 'links']) ?? [];
            foreach ($foreignLinks as $fLink => $fLinkDefs) {
                if (
                    ($fLinkDefs['type'] ?? null) === 'belongsTo' &&
                    ($fLinkDefs['entity'] ?? null) === $entityType &&
                    ($fLinkDefs['foreign'] ?? null) === $link
                ) {
                    $foreignKeyColumn = $fLink . 'Id';
                    break;
                }
            }
        }

        if (!$foreignKeyColumn && $foreignKey) {
            // Last resort convention: foreignKey column is parentEntityTypeId (e.g., inboxId)
            // This may not be accurate if the link name differs from entity type
            $foreignKeyColumn = lcfirst($entityType) . 'Id';
        }

        if (!$foreignKeyColumn) {
            $this->log->warning("CascadeDelete: Could not determine foreign key for link '{$link}' in {$entityType}");
            return;
        }

        try {
            $linkedEntities = $this->entityManager->getRDBRepository($foreignEntityType)
                ->where([$foreignKeyColumn => $entityId])
                ->find();

            $count = 0;
            foreach ($linkedEntities as $linkedEntity) {
                // Use silent option to prevent:
                // 1. External API calls (already handled at top level)
                // 2. Duplicate cascade processing (we're already cascading)
                // But we still want to trigger cascade delete on children, so we use a special option
                $this->entityManager->removeEntity($linkedEntity, ['cascadeParent' => true]);
                $count++;
            }

            if ($count > 0) {
                $this->log->info("CascadeDelete: Deleted {$count} {$foreignEntityType} record(s) via link '{$link}'");
            }
        } catch (\Exception $e) {
            $this->log->error("CascadeDelete: Failed to delete linked {$foreignEntityType} via '{$link}': " . $e->getMessage());
        }
    }

    /**
     * Delete a single entity in a hasOne relationship.
     *
     * @param Entity $entity
     * @param string $link
     * @param string $foreignEntityType
     */
    private function deleteHasOneLinkedEntity(Entity $entity, string $link, string $foreignEntityType): void
    {
        $entityType = $entity->getEntityType();
        $entityId = $entity->getId();
        $linkDefs = $this->metadata->get(['entityDefs', $entityType, 'links', $link]);

        // Determine the foreign key column name
        // Priority: 1) explicit foreignKey, 2) lookup belongsTo on foreign entity
        $foreignKeyColumn = $linkDefs['foreignKey'] ?? null;

        if (!$foreignKeyColumn) {
            // For hasOne, the foreign entity has a belongsTo link back
            // The foreign key column is on the foreign entity
            $foreignLinks = $this->metadata->get(['entityDefs', $foreignEntityType, 'links']) ?? [];
            foreach ($foreignLinks as $fLink => $fLinkDefs) {
                if (
                    ($fLinkDefs['type'] ?? null) === 'belongsTo' &&
                    ($fLinkDefs['entity'] ?? null) === $entityType &&
                    ($fLinkDefs['foreign'] ?? null) === $link
                ) {
                    $foreignKeyColumn = $fLink . 'Id';
                    break;
                }
            }
        }

        if (!$foreignKeyColumn) {
            $this->log->warning("CascadeDelete: Could not determine foreign key for hasOne link '{$link}' in {$entityType}");
            return;
        }

        try {
            $linkedEntity = $this->entityManager->getRDBRepository($foreignEntityType)
                ->where([$foreignKeyColumn => $entityId])
                ->findOne();

            if ($linkedEntity) {
                $this->entityManager->removeEntity($linkedEntity, ['cascadeParent' => true]);
                $this->log->info("CascadeDelete: Deleted {$foreignEntityType} record via hasOne link '{$link}'");
            }
        } catch (\Exception $e) {
            $this->log->error("CascadeDelete: Failed to delete linked {$foreignEntityType} via hasOne '{$link}': " . $e->getMessage());
        }
    }

    /**
     * Clean up records in a junction table.
     *
     * @param Entity $entity
     * @param array{table: string, column: string} $junction
     */
    private function cleanJunctionTable(Entity $entity, array $junction): void
    {
        $table = $junction['table'] ?? null;
        $column = $junction['column'] ?? null;

        if (!$table || !$column) {
            $this->log->warning("CascadeDelete: Junction table config missing 'table' or 'column'");
            return;
        }

        $entityId = $entity->getId();

        try {
            $deleteQuery = $this->entityManager->getQueryBuilder()
                ->delete()
                ->from($table)
                ->where([$column => $entityId])
                ->build();

            $this->entityManager->getQueryExecutor()->execute($deleteQuery);
            $this->log->debug("CascadeDelete: Cleaned junction table '{$table}' for column '{$column}'");
        } catch (\Exception $e) {
            // Junction table might not exist or have different structure
            $this->log->debug("CascadeDelete: Could not clean junction table '{$table}': " . $e->getMessage());
        }
    }

    /**
     * Clean up EntityTeam records for the deleted entity.
     *
     * @param Entity $entity
     */
    private function cleanEntityTeam(Entity $entity): void
    {
        $entityType = $entity->getEntityType();
        $entityId = $entity->getId();

        try {
            $deleteQuery = $this->entityManager->getQueryBuilder()
                ->delete()
                ->from('EntityTeam')
                ->where([
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                ])
                ->build();

            $this->entityManager->getQueryExecutor()->execute($deleteQuery);
        } catch (\Exception $e) {
            $this->log->debug("CascadeDelete: Could not clean EntityTeam records: " . $e->getMessage());
        }
    }
}
