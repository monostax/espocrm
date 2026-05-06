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

namespace Espo\Modules\FeatureClinicaBase\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Seeds a "Clínica" SidenavConfig record with a clinical-workflow-focused
 * tabList. Upsert: creates if not exists, merges missing seed items into
 * existing records while preserving user customizations across rebuilds.
 *
 * Tenant-admins assign the config to their team(s) via
 * Configurations > Sidenav Configs.
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
        $this->log->info('FeatureClinicaBase: Seeding SidenavConfig...');

        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
                  $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        $configId = $this->prepareId('feature-clinica', $toHash);

        $existing = $this->findExistingConfigIncludingDeleted($configId);

        $data = [
            'name' => 'Menu Clínica',
            'order' => 10,
            'iconClass' => 'fas fa-heartbeat',
            'tabList' => $this->getTabList(),
        ];

        try {
            if ($existing) {
                $this->restoreIfDeleted($existing);
                $existing->set($this->prepareExistingData($existing, $data));
                $this->entityManager->saveEntity($existing, [
                    'modifiedById' => 'system',
                    'skipWorkflow' => true,
                ]);
                $this->log->info("FeatureClinicaBase: Upserted SidenavConfig 'Clínica' (ID: '{$configId}')");
            } else {
                $data['id'] = $configId;
                $data['isDefault'] = false;
                $data['isDisabled'] = false;
                $this->entityManager->createEntity('SidenavConfig', $data, [
                    'createdById' => 'system',
                    'skipWorkflow' => true,
                ]);
                $this->log->info("FeatureClinicaBase: Created SidenavConfig 'Clínica' (ID: '{$configId}')");
            }
        } catch (\Throwable $e) {
            $this->log->error("FeatureClinicaBase: Failed to upsert SidenavConfig: " . $e->getMessage());

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
                        "FeatureClinicaBase: Recovered SidenavConfig upsert after conflict (ID: '{$configId}')"
                    );
                } catch (\Throwable $retryError) {
                    $this->log->error(
                        "FeatureClinicaBase: Retry upsert failed for SidenavConfig '{$configId}': " .
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

    private function getTabList(): array
    {
        return [
            (object) [
                'type' => 'url',
                'text' => '$Calendar',
                'url' => '#Calendar',
                'iconClass' => 'ti ti-calendar',
                'color' => null,
                'aclScope' => null,
                'onlyAdmin' => false,
                'id' => 'sidenav-calendar',
            ],

            (object) [
                'type' => 'divider',
                'text' => '$Pacientes',
            ],
            'FeatureClinicaBasePaciente',
            (object) [
                'type' => 'divider',
                'text' => '$CRM',
            ],
            'Contact',
            'Opportunity',

            (object) [
                'type' => 'divider',
                'text' => '$Conversations',
                'id' => 'sidenav-conversations',
            ],
            (object) [
                'type' => 'url',
                'text' => '$OpenConversations',
                'url' => '#ChatwootConversation/list/primaryFilter=open',
                'iconClass' => 'ti ti-circle-dashed',
                'color' => null,
                'aclScope' => 'ChatwootConversation',
                'onlyAdmin' => false,
                'id' => 'sidenav-conversations-open',
            ],
            (object) [
                'type' => 'url',
                'text' => '$PendingConversations',
                'url' => '#ChatwootConversation/list/primaryFilter=pending',
                'iconClass' => 'ti ti-circle-half-2',
                'color' => null,
                'aclScope' => 'ChatwootConversation',
                'onlyAdmin' => false,
                'id' => 'sidenav-conversations-pending',
            ],
            (object) [
                'type' => 'url',
                'text' => '$SnoozedConversations',
                'url' => '#ChatwootConversation/list/primaryFilter=snoozed',
                'iconClass' => 'ti ti-bell-off',
                'color' => null,
                'aclScope' => 'ChatwootConversation',
                'onlyAdmin' => false,
                'id' => 'sidenav-conversations-snoozed',
            ],
            (object) [
                'type' => 'url',
                'text' => '$ResolvedConversations',
                'url' => '#ChatwootConversation/list/primaryFilter=resolved',
                'iconClass' => 'ti ti-circle-check-filled',
                'color' => null,
                'aclScope' => 'ChatwootConversation',
                'onlyAdmin' => false,
                'id' => 'sidenav-conversations-resolved',
            ],

            (object) [
                'type' => 'divider',
                'text' => '$Clínica Nas Nuvens',
            ],
            'FeatureIntegrationClinicaNasNuvensPaciente',
            'FeatureIntegrationClinicaNasNuvensAgendamento',
            'FeatureIntegrationClinicaNasNuvensFaturamento',
            'FeatureIntegrationClinicaNasNuvensProfissional',
            (object) [
                'text' => '$Outros',
                'itemList' => [
                    'FeatureIntegrationClinicaNasNuvensAgendamentoProcedimento',
                ],
            ],

            (object) [
                'type' => 'divider',
                'text' => '$Activities',
            ],
            'Task',
            'Appointment',
            'Call',
            'Meeting',
        ];
    }

    private function prepareId(string $id, bool $toHash): string
    {
        if ($toHash) {
            return md5($id);
        }

        return $id;
    }
}
