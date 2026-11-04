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

namespace Espo\Modules\FeatureSelfService\Tools\Workspace;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Whether a self-service workspace may send and use AI.
 *
 * A trial that ended without a paid plan freezes the workspace: channels stay
 * connected and incoming messages keep arriving, but nothing is sent and the
 * AI stops. Chatwoot learns it through the account's `workspace_access`
 * custom attribute, pushed here and retried until it lands, because Chatwoot
 * refuses outgoing messages on its own side. Never Chatwoot's `suspended`
 * status: it drops incoming WhatsApp events.
 */
class WorkspaceAccess
{
    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'pastDue';
    public const STATUS_FROZEN = 'frozen';

    public const ACCESS_ACTIVE = 'active';
    public const ACCESS_FROZEN = 'frozen';

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log,
    ) {}

    public static function isFrozen(Entity $tenant): bool
    {
        return $tenant->get('plan') && $tenant->get('subscriptionStatus') === self::STATUS_FROZEN;
    }

    /**
     * Freeze trials whose 7 days are over. Returns how many were frozen.
     */
    public function freezeEndedTrials(?string $now = null): int
    {
        $now ??= gmdate('Y-m-d H:i:s');
        $frozen = 0;

        foreach ($this->entityManager->getRDBRepository('Tenant')
            ->where([
                'plan!=' => '',
                'subscriptionStatus' => self::STATUS_TRIAL,
                'trialEndsAt!=' => null,
                'trialEndsAt<=' => $now,
            ])
            ->find() as $tenant) {
            $this->setStatus($tenant, self::STATUS_FROZEN);
            $this->log->info("SelfService: trial of tenant {$tenant->getId()} ended; workspace frozen.");
            $frozen++;
        }

        return $frozen;
    }

    public function setStatus(Entity $tenant, string $status): void
    {
        $tenant->set('subscriptionStatus', $status);
        $tenant->set('frozenAt', $status === self::STATUS_FROZEN ? gmdate('Y-m-d H:i:s') : null);
        $this->entityManager->saveEntity($tenant, ['silent' => true]);
    }

    /**
     * Push the access of every self-service workspace whose Chatwoot accounts
     * don't have it yet. Returns how many tenants were brought in line.
     */
    public function syncChatwoot(): int
    {
        $synced = 0;

        foreach ($this->entityManager->getRDBRepository('Tenant')
            ->where(['plan!=' => ''])
            ->find() as $tenant) {
            $access = self::isFrozen($tenant) ? self::ACCESS_FROZEN : self::ACCESS_ACTIVE;

            if ($tenant->get('chatwootAccess') === $access) {
                continue;
            }

            if ($this->pushToChatwoot($tenant, $access)) {
                $tenant->set('chatwootAccess', $access);
                $this->entityManager->saveEntity($tenant, ['silent' => true, 'skipHooks' => true]);
                $synced++;
            }
        }

        return $synced;
    }

    private function pushToChatwoot(Entity $tenant, string $access): bool
    {
        $ok = true;

        foreach ($this->entityManager->getRDBRepository('ChatwootAccount')
            ->where(['tenantId' => $tenant->getId()])
            ->find() as $account) {
            $platform = $this->entityManager->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));
            $chatwootAccountId = (int) $account->get('chatwootAccountId');

            if (!$platform || $chatwootAccountId <= 0) {
                continue;
            }

            try {
                // The Platform API merges custom_attributes, so this only sets our key.
                $this->apiClient->updateAccount(
                    (string) $platform->get('backendUrl'),
                    (string) $platform->get('accessToken'),
                    $chatwootAccountId,
                    ['custom_attributes' => ['workspace_access' => $access]]
                );
            } catch (Throwable $e) {
                $ok = false;
                $this->log->warning(
                    "SelfService: could not set Chatwoot account {$chatwootAccountId} to {$access}: " . $e->getMessage()
                );
            }
        }

        return $ok;
    }
}
