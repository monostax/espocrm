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

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Tools\Offboarding;

use Espo\Core\Utils\Metadata;

/**
 * Which records belong to a tenant, read from metadata so a new module's
 * tenant-scoped entity is offboarded without being listed here.
 *
 * - Tenant-scoped: any entity type with a belongsTo link to Tenant.
 * - Kept: billing and usage history (the FeatureCredits module, AI billing
 *   rates, reservations, AI runs and reporting events). Their rows stay,
 *   still pointing at the removed tenant's id.
 * - Handled by their own steps: ChatwootAccount (its Chatwoot account and
 *   mirror), Team, User, Tenant, and the offboarding record itself.
 */
class TenantDataMap
{
    public const RETAINED_MODULES = ['FeatureCredits'];

    public const RETAINED_TYPES = [
        'TenantAiBillingRate',
        'AiUsageReservation',
        'ChatwootAiAgentRun',
        'ChatwootReportingEvent',
        // Retired AI identities stay, so their emails are never issued again.
        'ChatwootMachineIdentity',
    ];

    private const OWN_STEPS = ['ChatwootAccount', 'Team', 'User', 'Tenant', Offboarder::ENTITY_TYPE];

    /** Removed first: they hold most rows, and removing them empties the rest. */
    private const CHATWOOT_HEAVY_FIRST = ['ChatwootConversation', 'ChatwootContact'];

    public function __construct(private Metadata $metadata) {}

    public function isRetained(string $entityType): bool
    {
        return in_array($entityType, self::RETAINED_TYPES, true)
            || in_array($this->metadata->get(['scopes', $entityType, 'module']), self::RETAINED_MODULES, true);
    }

    /**
     * Tenant-scoped entity types to remove, children before the types they
     * belong to (Initiative before InitiativeStage before InitiativeType,
     * whose remove hooks refuse while children exist).
     *
     * @return list<array{entityType: string, column: string}>
     */
    public function tenantScopedTypes(): array
    {
        $columns = [];
        $belongsTo = [];

        foreach ($this->entityTypes() as $entityType) {
            if (in_array($entityType, self::OWN_STEPS, true) || $this->isRetained($entityType)) {
                continue;
            }

            foreach ($this->links($entityType) as $link => $defs) {
                if (($defs['type'] ?? null) === 'belongsTo' && ($defs['entity'] ?? null) === 'Tenant') {
                    $columns[$entityType] ??= $link . 'Id';
                }
            }
        }

        foreach (array_keys($columns) as $entityType) {
            foreach ($this->links($entityType) as $defs) {
                $parent = $defs['entity'] ?? null;

                if (($defs['type'] ?? null) === 'belongsTo' && is_string($parent) && isset($columns[$parent])) {
                    $belongsTo[$entityType][] = $parent;
                }
            }
        }

        return array_map(
            fn (string $entityType) => ['entityType' => $entityType, 'column' => $columns[$entityType]],
            self::childrenFirst(array_keys($columns), $belongsTo),
        );
    }

    /**
     * The local mirror under a ChatwootAccount (its `cascadeDelete` links),
     * with the largest tables first.
     *
     * @return list<array{entityType: string, column: string}>
     */
    public function chatwootAccountChildren(): array
    {
        $result = [];

        foreach ($this->metadata->get(['entityDefs', 'ChatwootAccount', 'cascadeDelete', 'links']) ?? [] as $link) {
            $defs = $this->metadata->get(['entityDefs', 'ChatwootAccount', 'links', $link]) ?? [];
            $entityType = $defs['entity'] ?? null;

            if (!is_string($entityType) || ($defs['type'] ?? null) !== 'hasMany') {
                continue;
            }

            $column = $this->foreignKey('ChatwootAccount', (string) $link, $entityType, $defs);

            if ($column) {
                $result[$entityType] = ['entityType' => $entityType, 'column' => $column];
            }
        }

        $heavy = [];

        foreach (self::CHATWOOT_HEAVY_FIRST as $entityType) {
            if (isset($result[$entityType])) {
                $heavy[] = $result[$entityType];
            }
        }

        return array_merge($heavy, array_values(array_diff_key($result, array_flip(self::CHATWOOT_HEAVY_FIRST))));
    }

    /**
     * Order types so each comes before every type it belongs to. Cycles are
     * broken arbitrarily but deterministically.
     *
     * @param list<string> $types
     * @param array<string, list<string>> $belongsTo child type → parent types
     * @return list<string>
     */
    public static function childrenFirst(array $types, array $belongsTo): array
    {
        sort($types);
        $children = [];

        foreach ($belongsTo as $child => $parents) {
            foreach ($parents as $parent) {
                if ($parent !== $child) {
                    $children[$parent][] = $child;
                }
            }
        }

        $ordered = [];
        $seen = [];

        $visit = function (string $type) use (&$visit, &$ordered, &$seen, $children): void {
            if (isset($seen[$type])) {
                return;
            }

            $seen[$type] = true;
            $own = $children[$type] ?? [];
            sort($own);

            foreach ($own as $child) {
                $visit($child);
            }

            $ordered[] = $type;
        };

        foreach ($types as $type) {
            $visit($type);
        }

        return array_values(array_intersect($ordered, $types));
    }

    /**
     * @param array<string, mixed> $linkDefs
     */
    private function foreignKey(string $entityType, string $link, string $foreignType, array $linkDefs): ?string
    {
        if (isset($linkDefs['foreignKey'])) {
            return (string) $linkDefs['foreignKey'];
        }

        foreach ($this->links($foreignType) as $foreignLink => $defs) {
            if (
                ($defs['type'] ?? null) === 'belongsTo' &&
                ($defs['entity'] ?? null) === $entityType &&
                ($defs['foreign'] ?? null) === $link
            ) {
                return $foreignLink . 'Id';
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function entityTypes(): array
    {
        $types = [];

        foreach ($this->metadata->get(['scopes']) ?? [] as $scope => $defs) {
            // Virtual entities (AgentMode, AgentSkill) have no table: their
            // rows live elsewhere and are not removed here.
            if (
                empty($defs['entity']) ||
                !empty($defs['isVirtual']) ||
                !$this->metadata->get(['entityDefs', $scope]) ||
                $this->metadata->get(['entityDefs', $scope, 'skipRebuild'])
            ) {
                continue;
            }

            $types[] = (string) $scope;
        }

        return $types;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function links(string $entityType): array
    {
        return $this->metadata->get(['entityDefs', $entityType, 'links']) ?? [];
    }
}
