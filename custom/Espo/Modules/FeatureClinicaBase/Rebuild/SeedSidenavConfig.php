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
use Espo\ORM\EntityManager;

/**
 * Seeds a "Clínica" SidenavConfig record with a clinical-workflow-focused
 * tabList. Upsert: creates if not exists, updates seed-controlled fields
 * (tabList, name, order, iconClass) while preserving user customizations
 * (teams, isDefault, isDisabled) across rebuilds.
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

        $existing = $this->entityManager->getEntityById('SidenavConfig', $configId);

        $data = [
            'name' => 'Menu Clínica',
            'order' => 10,
            'iconClass' => 'fas fa-heartbeat',
            'tabList' => $this->getTabList(),
        ];

        try {
            if ($existing) {
                $existing->set($data);
                $this->entityManager->saveEntity($existing, [
                    'modifiedById' => 'system',
                    'skipWorkflow' => true,
                ]);
                $this->log->info("FeatureClinicaBase: Updated SidenavConfig 'Clínica' (ID: '{$configId}')");
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
        } catch (\Exception $e) {
            $this->log->error("FeatureClinicaBase: Failed to upsert SidenavConfig: " . $e->getMessage());
        }
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
