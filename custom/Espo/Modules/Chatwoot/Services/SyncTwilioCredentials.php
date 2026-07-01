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

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Mirrors Twilio VoIP credentials from a Chatwoot inbox into a CRM
 * `Credential` row of type `twilio`.
 *
 * The CRM needs these credentials server-side to proxy Twilio call
 * recordings (see FeatureVoip\Controllers\VoipRecording) without ever
 * exposing the secret to the browser. Chatwoot's normal inbox JSON redacts
 * the API key secret / auth token, so we fetch them from the privileged
 * `voip_calls/credential_config` endpoint (administrator API key required).
 *
 * The `apiKeySecret` / `authToken` fields are encrypted at rest by the
 * FeatureCredential\Hooks\Credential\EncryptConfigFields BeforeSave hook, so
 * we intentionally save with normal hooks (no 'silent' flag).
 */
class SyncTwilioCredentials
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    /**
     * Sync the Twilio credential for a single Chatwoot inbox.
     *
     * @param array<string, mixed> $chatwootInbox Inbox payload from listInboxes
     * @param array<string> $teamsIds Team IDs to assign to the Credential
     */
    public function syncForInbox(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        array $chatwootInbox,
        array $teamsIds = []
    ): void {
        if (empty($chatwootInbox['voip_enabled'])) {
            return;
        }

        $inboxId = $chatwootInbox['id'] ?? null;
        if (!$inboxId) {
            return;
        }

        try {
            $config = $this->apiClient->getInboxVoipCredentialConfig(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                (int) $inboxId
            );
        } catch (\Throwable $e) {
            $this->log->warning(
                "SyncTwilioCredentials: Failed to fetch VoIP credential config for inbox {$inboxId}: "
                . $e->getMessage()
            );
            return;
        }

        // 404 (endpoint not deployed / inbox missing) or disabled remotely.
        if ($config === null || empty($config['voip_enabled'])) {
            return;
        }

        $accountSid = $config['account_sid'] ?? null;
        if (!$accountSid) {
            $this->log->warning(
                "SyncTwilioCredentials: Inbox {$inboxId} has VoIP enabled but no account_sid; skipping."
            );
            return;
        }

        $this->upsertCredential($config, (int) $inboxId, $teamsIds);
    }

    /**
     * Create or update a `twilio` Credential keyed by Twilio Account SID.
     *
     * @param array<string, mixed> $config Non-redacted VoIP credential config
     * @param array<string> $teamsIds
     */
    private function upsertCredential(array $config, int $inboxId, array $teamsIds): void
    {
        $type = $this->entityManager
            ->getRDBRepository('CredentialType')
            ->where(['code' => 'twilio'])
            ->findOne();

        if (!$type) {
            $this->log->warning(
                'SyncTwilioCredentials: CredentialType "twilio" not found; run rebuild. Skipping.'
            );
            return;
        }

        $accountSid = (string) $config['account_sid'];
        $fromNumber = $config['from_number'] ?? null;

        $configData = array_filter([
            'accountSid' => $accountSid,
            'apiKeySid' => $config['api_key_sid'] ?? null,
            'apiKeySecret' => $config['api_key_secret'] ?? null,
            'authToken' => $config['auth_token'] ?? null,
            'fromNumber' => $fromNumber,
        ], static fn ($v) => $v !== null && $v !== '');

        // Match an existing credential on this type + Twilio Account SID. The
        // config JSON is stored (with secrets encrypted), so we cannot query
        // inside it directly; instead we key on a deterministic name.
        $name = 'Twilio VoIP ' . ($fromNumber ?: $accountSid);

        $existing = $this->entityManager
            ->getRDBRepository('Credential')
            ->where([
                'credentialTypeId' => $type->getId(),
                'name' => $name,
            ])
            ->findOne();

        if ($existing) {
            $existing->set('config', json_encode($configData));
            $existing->set('isActive', true);
            if (!empty($teamsIds)) {
                $existing->set('teamsIds', $teamsIds);
            }
            $this->entityManager->saveEntity($existing);

            $this->log->debug(
                "SyncTwilioCredentials: Updated Twilio Credential {$existing->getId()} for inbox {$inboxId}"
            );
            return;
        }

        $credential = $this->entityManager->getEntity('Credential');
        $credential->set([
            'name' => $name,
            'credentialTypeId' => $type->getId(),
            'config' => json_encode($configData),
            'isActive' => true,
            'description' => "Synced from Chatwoot inbox #{$inboxId} VoIP settings.",
        ]);

        if (!empty($teamsIds)) {
            $credential->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($credential);

        $this->log->info(
            "SyncTwilioCredentials: Created Twilio Credential {$credential->getId()} for inbox {$inboxId}"
        );
    }
}
