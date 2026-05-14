<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Seeds globally shared SidenavConfig records for common CRM scopes.
 * Upsert: creates if not exists, merges missing seed items into existing
 * records while preserving user customizations across rebuilds.
 */
class SeedSidenavConfig implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('Global: Seeding SidenavConfig...');

        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
                  $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        $configs = [
            [
                'id' => 'contact',
                'name' => 'Pessoas',
                'order' => 20,
                'iconClass' => 'ti ti-address-book',
                'tabList' => ['Contact'],
            ],
            [
                'id' => 'opportunity',
                'name' => 'Oportunidades',
                'order' => 30,
                'iconClass' => 'ti ti-coin-filled',
                'tabList' => ['Opportunity'],
            ],
            [
                'id' => 'activities',
                'name' => 'Atividades',
                'order' => 40,
                'iconClass' => 'ti ti-checklist',
                'tabList' => [
                    (object) [
                        'type' => 'url',
                        'text' => '$Activities',
                        'url' => '?navbar=activities#Activities',
                        'iconClass' => 'ti ti-activity',
                    ],
                ],
            ],
            [
                'id' => 'agenda',
                'name' => 'Agenda',
                'order' => 50,
                'iconClass' => 'ti ti-calendar',
                'tabList' => [
                    (object) [
                        'type' => 'url',
                        'text' => '$Calendar',
                        'url' => '?navbar=calendar#Calendar',
                        'iconClass' => 'ti ti-calendar',
                    ],
                ],
            ],
        ];

        foreach ($configs as $config) {
            $this->seedConfig($config, $toHash);
        }
    }

    private function seedConfig(array $config, bool $toHash): void
    {
        $configId = $this->prepareId($config['id'], $toHash);
        $existing = $this->findExistingConfigIncludingDeleted($configId);

        $data = [
            'name' => $config['name'],
            'order' => $config['order'],
            'iconClass' => $config['iconClass'],
            'tabList' => $config['tabList'],
            'isGloballyShared' => true,
        ];

        try {
            if ($existing) {
                $this->restoreIfDeleted($existing);
                $existing->set($this->prepareExistingData($existing, $data));
                $this->entityManager->saveEntity($existing, [
                    'modifiedById' => 'system',
                    'skipWorkflow' => true,
                ]);
                $this->log->info("Global: Upserted SidenavConfig '{$config['name']}' (ID: '{$configId}')");
            } else {
                $data['id'] = $configId;
                $data['isDefault'] = false;
                $data['isDisabled'] = false;
                $this->entityManager->createEntity('SidenavConfig', $data, [
                    'createdById' => 'system',
                    'skipWorkflow' => true,
                ]);
                $this->log->info("Global: Created SidenavConfig '{$config['name']}' (ID: '{$configId}')");
            }
        } catch (\Throwable $e) {
            $this->log->error("Global: Failed to upsert SidenavConfig '{$config['name']}': " . $e->getMessage());

            $existing = $this->findExistingConfigIncludingDeleted($configId);

            if ($existing) {
                try {
                    $this->restoreIfDeleted($existing);
                    $existing->set($this->prepareExistingData($existing, $data));
                    $this->entityManager->saveEntity($existing, [
                        'modifiedById' => 'system',
                        'skipWorkflow' => true,
                    ]);
                    $this->log->info(
                        "Global: Recovered SidenavConfig upsert after conflict (ID: '{$configId}')"
                    );
                } catch (\Throwable $retryError) {
                    $this->log->error(
                        "Global: Retry upsert failed for SidenavConfig '{$configId}': " .
                        $retryError->getMessage()
                    );
                }
            }
        }
    }

    private function prepareExistingData(Entity $existing, array $data): array
    {
        return [
            'name' => $existing->get('name') ?: $data['name'],
            'order' => $existing->get('order') ?? $data['order'],
            'iconClass' => $existing->get('iconClass') ?: $data['iconClass'],
            'tabList' => $this->upsertTabList($existing->get('tabList'), $data['tabList']),
            'isGloballyShared' => $existing->get('isGloballyShared') ?? $data['isGloballyShared'],
        ];
    }

    private function upsertTabList($existingTabList, array $seedTabList): array
    {
        $result = is_array($existingTabList) ? array_values($existingTabList) : [];
        $indexMap = [];

        foreach ($result as $index => $item) {
            $key = $this->getTabItemKey($item);

            if ($key !== null) {
                $indexMap[$key] = $index;
            }
        }

        foreach ($seedTabList as $seedItem) {
            $key = $this->getTabItemKey($seedItem);

            if ($key !== null && array_key_exists($key, $indexMap)) {
                $index = $indexMap[$key];
                $result[$index] = $this->mergeTabItem($result[$index], $seedItem);

                continue;
            }

            $result[] = $seedItem;

            if ($key !== null) {
                $indexMap[$key] = count($result) - 1;
            }
        }

        return array_values($result);
    }

    private function mergeTabItem($existingItem, $seedItem)
    {
        if (is_string($existingItem) || is_string($seedItem)) {
            return $existingItem;
        }

        $existingArray = (array) $existingItem;
        $seedArray = (array) $seedItem;
        $merged = array_replace($seedArray, $existingArray);

        return is_object($existingItem) ? (object) $merged : $merged;
    }

    private function getTabItemKey($item): ?string
    {
        if (is_string($item)) {
            return 'scope:' . $item;
        }

        if (!is_array($item) && !is_object($item)) {
            return null;
        }

        $item = (array) $item;
        $type = $item['type'] ?? 'item';

        if (!empty($item['id']) && is_string($item['id'])) {
            return $type . ':id:' . $item['id'];
        }

        if ($type === 'divider' && !empty($item['text']) && is_string($item['text'])) {
            return 'divider:' . $item['text'];
        }

        if ($type === 'url' && !empty($item['url']) && is_string($item['url'])) {
            return 'url:' . $item['url'];
        }

        if (!empty($item['text']) && is_string($item['text'])) {
            return $type . ':text:' . $item['text'];
        }

        return $type . ':hash:' . md5((string) json_encode($item));
    }

    private function findExistingConfigIncludingDeleted(string $configId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('SidenavConfig')
            ->where(['id' => $configId])
            ->withDeleted()
            ->build();

        return $this->entityManager
            ->getRDBRepository('SidenavConfig')
            ->clone($query)
            ->findOne();
    }

    private function restoreIfDeleted(Entity $existing): void
    {
        if (!$existing->get('deleted')) {
            return;
        }

        $this->entityManager
            ->getRDBRepository('SidenavConfig')
            ->restoreDeleted($existing->getId());
    }

    private function prepareId(string $id, bool $toHash): string
    {
        if ($toHash) {
            return md5($id);
        }

        return $id;
    }
}
