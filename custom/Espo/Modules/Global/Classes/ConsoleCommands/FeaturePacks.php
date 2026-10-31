<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Classes\Utils\FeatureVerticalChecker;
use Espo\ORM\EntityManager;

/**
 * List or change the feature packs (app.featureVerticals) of tenants.
 *
 * A pack is switched on by attaching its role to the tenant's base user team.
 *
 * Usage:
 *   php command.php feature-packs
 *       List every tenant with its packs.
 *   php command.php feature-packs --tenant=<tenantId> --add=calls,meetings --remove=sms
 *       Show what would change (dry run).
 *   php command.php feature-packs --tenant=<tenantId> --add=calls --apply
 *       Apply the change.
 */
class FeaturePacks implements Command
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private FeatureVerticalChecker $checker,
    ) {}

    public function run(Params $params, IO $io): void
    {
        $tenantId = trim((string) $params->getOption('tenant'));

        if ($tenantId === '') {
            $this->listTenants($io);

            return;
        }

        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);

        if (!$tenant) {
            $io->writeLine("Tenant {$tenantId} not found.");
            $io->setExitStatus(1);

            return;
        }

        $baseTeamId = $tenant->get('baseUserTeamId');
        $team = is_string($baseTeamId) && $baseTeamId !== ''
            ? $this->entityManager->getEntityById('Team', $baseTeamId)
            : null;

        if (!$team) {
            $io->writeLine("Tenant {$tenantId} has no base user team.");
            $io->setExitStatus(1);

            return;
        }

        $verticals = $this->metadata->get(['app', 'featureVerticals']) ?? [];
        $add = $this->parseList($params->getOption('add'));
        $remove = $this->parseList($params->getOption('remove'));

        foreach ([...$add, ...$remove] as $name) {
            if (!isset($verticals[$name]['roleStaticId'])) {
                $io->writeLine("Unknown pack '{$name}'. Known: " . implode(', ', array_keys($verticals)));
                $io->setExitStatus(1);

                return;
            }
        }

        $current = $this->checker->getTenantVerticals($tenantId);
        $io->writeLine(sprintf('%s (%s), base team "%s"', $tenant->get('name'), $tenantId, $team->get('name')));
        $io->writeLine('  current: ' . ($current ? implode(', ', $current) : '(none)'));

        $toAdd = array_values(array_diff($add, $current));
        $toRemove = array_values(array_intersect($remove, $current));

        if ($toAdd === [] && $toRemove === []) {
            $io->writeLine('  nothing to change');

            return;
        }

        $io->writeLine('  add: ' . ($toAdd ? implode(', ', $toAdd) : '-'));
        $io->writeLine('  remove: ' . ($toRemove ? implode(', ', $toRemove) : '-'));

        if (!$params->hasFlag('apply')) {
            $io->writeLine('  dry run; pass --apply to write');

            return;
        }

        $relation = $this->entityManager->getRDBRepository('Team')->getRelation($team, 'roles');

        foreach ($toAdd as $name) {
            $relation->relateById($this->roleId($verticals[$name]['roleStaticId']));
        }

        foreach ($toRemove as $name) {
            $relation->unrelateById($this->roleId($verticals[$name]['roleStaticId']));
        }

        $after = $this->checker->getTenantVerticals($tenantId);
        $io->writeLine('  now: ' . ($after ? implode(', ', $after) : '(none)'));
    }

    private function listTenants(IO $io): void
    {
        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->order('createdAt')
            ->find();

        foreach ($tenants as $tenant) {
            $packs = $this->checker->getTenantVerticals((string) $tenant->getId());
            $io->writeLine(sprintf(
                '%s  %-40s %s',
                $tenant->getId(),
                mb_substr((string) $tenant->get('name'), 0, 40),
                $packs ? implode(', ', $packs) : '-'
            ));
        }
    }

    /**
     * @return string[]
     */
    private function parseList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));
    }

    private function roleId(string $staticId): string
    {
        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
            $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        return $toHash ? md5($staticId) : $staticId;
    }
}
