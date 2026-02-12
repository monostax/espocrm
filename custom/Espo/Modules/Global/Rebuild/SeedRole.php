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
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed/update system roles with static IDs.
 * Runs automatically during system rebuild.
 */
class SeedRole implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('Global Module: Starting to seed/update system roles...');

        // Check if UUID mode is enabled
        $toHash = $this->metadata->get(['app', 'recordId', 'type']) === 'uuid4' ||
                  $this->metadata->get(['app', 'recordId', 'dbType']) === 'uuid';

        // Define all roles to be seeded
        $roles = $this->getRoleDefinitions();

        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($roles as $roleConfig) {
            $result = $this->seedRole($roleConfig, $toHash);
            
            if ($result === 'created') {
                $createdCount++;
            } elseif ($result === 'updated') {
                $updatedCount++;
            } else {
                $skippedCount++;
            }
        }

        $this->log->info(
            "Global Module: Role seeding complete. " .
            "Created: {$createdCount}, Updated: {$updatedCount}, Skipped: {$skippedCount}"
        );
    }

    /**
     * Get the base tenant role configuration.
     * Used as a foundation for tenant and tenant-admin roles.
     */
    protected function getTenantBaseConfig(): array
    {
        return [
            'assignmentPermission' => 'team',
            'userPermission' => 'team',
            'messagePermission' => 'team',
            'portalPermission' => 'not-set',
            'groupEmailAccountPermission' => 'team',
            'exportPermission' => 'not-set',
            'massUpdatePermission' => 'not-set',
            'dataPrivacyPermission' => 'not-set',
            'followerManagementPermission' => 'team',
            'auditPermission' => 'not-set',
            'mentionPermission' => 'team',
            'userCalendarPermission' => 'team',
            'data' => [
                'Import' => true,
                'ExternalAccount' => true,
                'Appointment' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'CredentialHistory' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'CredentialType' => [
                    'create' => 'no',
                    'read' => 'all',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'Credential' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'no',
                    'delete' => 'no',
                ],
                'OAuthProvider' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'OAuthAccount' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'ChatwootAccount' => [
                        'create' => 'no',
                        'read' => 'team',
                        'edit' => 'no',
                        'delete' => 'no',
                        'stream' => 'no',
                    ],
                'ChatwootConversation' => [
                    'create' => 'no',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'ChatwootAgent' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'ChatwootInboxIntegration' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'User' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],

                'Calendar' => true,
                'Contact' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                    'stream' => 'team',
                ],
                'Case' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'own',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'Task' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'own',
                    'delete' => 'own',
                    'stream' => 'team',
                ],
                'Opportunity' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                    'stream' => 'team',
                ],
                'Funnel' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],
                'OpportunityStage' => [
                    'create' => 'yes',
                    'read' => 'team',
                    'edit' => 'team',
                    'delete' => 'team',
                ],

            ],
            'fieldData' => [
                'Email' => (object)[],
                'IncomingWebhook' => (object)[],
                'Team' => (object)[],
                'Credential' => (object)[],
                'CredentialHistory' => (object)[],
                'CredentialType' => (object)[],
                'OAuthAccount' => (object)[],
                'OAuthProvider' => (object)[],
                'User' => (object)[
                    'userName' => (object)['read' => 'yes', 'edit' => 'yes'],
                    'password' => (object)['read' => 'yes', 'edit' => 'yes'],
                    'emailAddress' => (object)['read' => 'yes', 'edit' => 'yes'],
                    'isActive' => (object)['read' => 'yes', 'edit' => 'yes'],
                    'teams' => (object)['read' => 'yes', 'edit' => 'yes'],
                    'defaultTeam' => (object)['read' => 'yes', 'edit' => 'yes'],
                ],
                'Account' => (object)[],
                'Call' => (object)[],
                'Campaign' => (object)[],
                'ChatwootAccount' => (object)[
                    'apiKey' => (object)['read' => 'no', 'edit' => 'no'],
                ],
                'Case' => (object)[],
                'Contact' => (object)[],
                'DocumentFolder' => (object)[],
                'Document' => (object)[],
                'KnowledgeBaseArticle' => (object)[],
                'KnowledgeBaseCategory' => (object)[],
                'Lead' => (object)[],
                'Meeting' => (object)[],
                'Opportunity' => (object)[],
                'TargetListCategory' => (object)[],
                'TargetList' => (object)[],
                'Task' => (object)[],
                'Activities' => (object)[],
                'Funnel' => (object)[],
                'OpportunityStage' => (object)[],

                'ChatwootPlatform' => (object)[],
                'ChatwootTeam' => (object)[],
                'ChatwootUser' => (object)[],
                'ChatwootAccountWebhook' => (object)[],
                'ChatwootContact' => (object)[],
                'ChatwootContactInbox' => (object)[],
                'ChatwootConversation' => (object)[],
                'ChatwootInbox' => (object)[],
                'ChatwootMessage' => (object)[],
                'ChatwootSyncState' => (object)[],

            ],
        ];
    }

    /**
     * Define all roles to be seeded.
     * Add new roles here with their configuration.
     */
    protected function getRoleDefinitions(): array
    {
        $tenantBase = $this->getTenantBaseConfig();

        return [

            [
                'staticId' => 'tenant-b2b',
                'name' => 'tenant-b2b',
                'data' => [
                    'Account' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                        'stream' => 'team',
                ]],
            ],
            // Tenant role - base role for tenant users
            [
                'staticId' => 'tenant',
                'name' => 'tenant',
                ...$tenantBase,
            ],
            // Tenant Admin role - inherits from tenant, can manage users in their team
            [
                'staticId' => 'tenant-admin',
                'name' => 'tenant-admin',
                ...$tenantBase,
                'data' => [
                    ...$tenantBase['data'],
                    'ChatwootAgent' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                        'stream' => 'team',
                    ],
                    '#KnowledgeBaseCategory' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                        'stream' => 'team',
                    ],
                    '#KnowledgeBaseArticle' => [
                        'create' => 'yes',
                        'read' => 'team',
                        'edit' => 'team',
                        'delete' => 'team',
                        'stream' => 'team',
                    ],
                ],
                'fieldData' => [
                    ...$tenantBase['fieldData']
                ]
            ],
            // Tenant User API role - inherits from tenant, adds Webhook access
            [
                'staticId' => 'tenant-user-api',
                'name' => 'tenant-user-api',
                ...$tenantBase,
                'data' => [
                    ...$tenantBase['data'],
                    'Webhook' => true,
                ],
            ]
            // Add more roles here as needed
            // Example:
            // [
            //     'staticId' => 'manager',
            //     'name' => 'manager',
            //     'assignmentPermission' => 'all',
            //     ...
            // ],
        ];
    }

    /**
     * Seed or update a single role.
     * Returns 'created', 'updated', or 'skipped'.
     */
    protected function seedRole(array $roleConfig, bool $toHash): string
    {
        $staticId = $roleConfig['staticId'];
        $roleId = $this->prepareId($staticId, $toHash);
        
        // First, try to restore any soft-deleted role with this ID
        $this->restoreSoftDeletedRole($roleId);

        // Prepare role data (remove staticId from config as it's not a DB field)
        $roleData = $roleConfig;
        unset($roleData['staticId']);
        $roleData['id'] = $roleId;

        // Check if role already exists by ID (should be active after restoration)
        $existingRole = $this->entityManager->getEntityById('Role', $roleId);

        if ($existingRole) {
            // Update existing role
            try {
                $existingRole->set($roleData);
                
                $this->entityManager->saveEntity($existingRole, [
                    'modifiedById' => 'system',
                    'skipWorkflow' => true,
                ]);
                
                $this->log->info("Global Module: Updated role '{$roleData['name']}' (ID: '{$roleId}')");
                return 'updated';
            } catch (\Exception $e) {
                $this->log->error("Global Module: Failed to update role '{$roleData['name']}': " . $e->getMessage());
                return 'skipped';
            }
        } else {
            // Create new role
            try {
                $this->entityManager->createEntity('Role', $roleData, [
                    'createdById' => 'system',
                    'skipWorkflow' => true,
                ]);
                $this->log->info("Global Module: Created role '{$roleData['name']}' (ID: '{$roleId}')");
                return 'created';
            } catch (\Exception $e) {
                $this->log->error("Global Module: Failed to create role '{$roleData['name']}': " . $e->getMessage());
                return 'skipped';
            }
        }
    }

    /**
     * Restore soft-deleted role with the given ID using raw SQL.
     */
    protected function restoreSoftDeletedRole(string $roleId): void
    {
        try {
            $pdo = $this->entityManager->getPDO();
            $sql = "UPDATE `role` SET `deleted` = 0 WHERE `id` = :id AND `deleted` = 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $roleId]);
            
            if ($stmt->rowCount() > 0) {
                $this->log->info("Global Module: Restored soft-deleted Tenant role with ID '{$roleId}'");
            }
        } catch (\Exception $e) {
            $this->log->debug("Global Module: Could not restore soft-deleted role (might not exist): " . $e->getMessage());
        }
    }

    /**
     * Prepare ID for entity.
     * If UUID mode is enabled, returns MD5 hash of the ID.
     * Otherwise, returns the ID as-is.
     */
    protected function prepareId(string $id, bool $toHash): string
    {
        if ($toHash) {
            return md5($id);
        }

        return $id;
    }
}
