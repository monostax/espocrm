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

namespace Espo\Modules\FeatureClinica\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Seeds a "Clínica" SidenavConfig record with a clinical-workflow-focused
 * tabList. Create-only: skips if the record already exists so that
 * tenant-admin customizations are preserved across rebuilds.
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
        $this->log->info('FeatureClinica: Seeding SidenavConfig...');

        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
                  $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        $configId = $this->prepareId('sidenav-clinica', $toHash);

        $existing = $this->entityManager->getEntityById('SidenavConfig', $configId);

        if ($existing) {
            $this->log->debug("FeatureClinica: SidenavConfig '{$configId}' already exists, skipping.");
            return;
        }

        try {
            $this->entityManager->createEntity('SidenavConfig', [
                'id' => $configId,
                'name' => 'Clínica',
                'order' => 10,
                'iconClass' => 'fas fa-heartbeat',
                'isDefault' => false,
                'isDisabled' => false,
                'tabList' => $this->getTabList(),
            ], [
                'createdById' => 'system',
                'skipWorkflow' => true,
            ]);

            $this->log->info("FeatureClinica: Created SidenavConfig 'Clínica' (ID: '{$configId}')");
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $this->log->debug("FeatureClinica: SidenavConfig 'Clínica' already exists (caught duplicate), skipping.");
            } else {
                $this->log->error("FeatureClinica: Failed to create SidenavConfig: " . $e->getMessage());
            }
        }
    }

    private function getTabList(): array
    {
        return [
            'Home',

            (object) [
                'type' => 'url',
                'text' => '$Calendar',
                'url' => '#Calendar',
                'iconClass' => 'ti ti-calendar',
                'color' => null,
                'aclScope' => null,
                'onlyAdmin' => false,
                'id' => '906879',
            ],
            (object) [
                'type' => 'url',
                'text' => '$Activities',
                'url' => '#Activities',
                'iconClass' => 'ti ti-checklist',
                'color' => null,
                'aclScope' => null,
                'onlyAdmin' => false,
                'id' => '906883',
            ],

            (object) [
                'type' => 'divider',
                'text' => '$Clínica',
            ],
            'Paciente',
            'Atendimento',
            'Jornada',
            'Prontuario',

            (object) [
                'type' => 'divider',
                'text' => '$CRM',
            ],
            'Contact',
            'Opportunity',

            (object) [
                'type' => 'divider',
                'text' => '$Conversations',
                'id' => '853524',
            ],
            (object) [
                'type' => 'url',
                'text' => '$OpenConversations',
                'url' => '#ChatwootConversation/list/primaryFilter=open',
                'iconClass' => 'ti ti-circle-dashed',
                'color' => null,
                'aclScope' => 'ChatwootConversation',
                'onlyAdmin' => false,
                'id' => '853526',
            ],
            (object) [
                'type' => 'url',
                'text' => '$PendingConversations',
                'url' => '#ChatwootConversation/list/primaryFilter=pending',
                'iconClass' => 'ti ti-circle-half-2',
                'color' => null,
                'aclScope' => 'ChatwootConversation',
                'onlyAdmin' => false,
                'id' => '853527',
            ],
            (object) [
                'type' => 'url',
                'text' => '$SnoozedConversations',
                'url' => '#ChatwootConversation/list/primaryFilter=snoozed',
                'iconClass' => 'ti ti-bell-off',
                'color' => null,
                'aclScope' => 'ChatwootConversation',
                'onlyAdmin' => false,
                'id' => '853529',
            ],
            (object) [
                'type' => 'url',
                'text' => '$ResolvedConversations',
                'url' => '#ChatwootConversation/list/primaryFilter=resolved',
                'iconClass' => 'ti ti-circle-check-filled',
                'color' => null,
                'aclScope' => 'ChatwootConversation',
                'onlyAdmin' => false,
                'id' => '853528',
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
