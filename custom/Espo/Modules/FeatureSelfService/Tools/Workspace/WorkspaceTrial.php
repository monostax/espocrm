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

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The self-service trial of a workspace (a Tenant).
 *
 * Provisioning sets `plan` monostax (the one plan), `subscriptionStatus` trial and
 * `onboardingStep` connectWhatsapp. The 7 days start when the first WhatsApp
 * channel is connected (an ACTIVE inbox integration). A number that brings its
 * chat history (QR, Meta coexistence) then holds onboarding at importHistory
 * until its newest window is in (Chatwoot reports phase 1 or an end), with
 * time limits so a silent import never locks anyone out. Tenants with no plan
 * are not managed by self-service: no onboarding gate, no trial.
 */
class WorkspaceTrial
{
    public const TRIAL_DAYS = 7;

    public const STEP_CONNECT_WHATSAPP = 'connectWhatsapp';
    public const STEP_IMPORT_HISTORY = 'importHistory';
    public const STEP_DONE = 'done';

    /** How long a connected number may take to start reporting its import. */
    public const IMPORT_START_WAIT = 600;
    /** Onboarding never waits on the import longer than this. */
    public const IMPORT_MAX_WAIT = 2700;

    private const WHATSAPP_CHANNELS = ['whatsappQrcode', 'whatsappCloudApi', 'whatsappCoexistence'];
    /** Channels whose connection brings the chat history in. */
    private const HISTORY_CHANNELS = ['whatsappQrcode', 'whatsappCoexistence'];

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * Values for a new self-service workspace.
     *
     * @return array<string, string>
     */
    public static function initialValues(): array
    {
        return [
            'plan' => 'monostax',
            'subscriptionStatus' => 'trial',
            'onboardingStep' => self::STEP_CONNECT_WHATSAPP,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function state(Entity $tenant): array
    {
        if (!$tenant->get('plan')) {
            return ['selfService' => false];
        }

        return [
            'selfService' => true,
            'plan' => $tenant->get('plan'),
            'status' => $tenant->get('subscriptionStatus'),
            'onboardingStep' => $tenant->get('onboardingStep'),
            'trialStartsAt' => $tenant->get('trialStartsAt'),
            'trialEndsAt' => $tenant->get('trialEndsAt'),
            // Frozen because the trial ran out (not an overdue invoice).
            'trialEnded' => $tenant->get('subscriptionStatus') === 'frozen' && !$tenant->get('paidThrough') &&
                $tenant->get('trialEndsAt') && $tenant->get('trialEndsAt') <= gmdate('Y-m-d H:i:s'),
            'seats' => $tenant->get('seats') ? (int) $tenant->get('seats') : null,
            'paidThrough' => $tenant->get('paidThrough'),
        ];
    }

    public function isConnecting(Entity $tenant): bool
    {
        return $tenant->get('onboardingStep') === self::STEP_CONNECT_WHATSAPP;
    }

    public function isImportingHistory(Entity $tenant): bool
    {
        return $tenant->get('onboardingStep') === self::STEP_IMPORT_HISTORY;
    }

    /**
     * Finish onboarding and start the trial once a WhatsApp channel of the
     * workspace is connected. Returns whether it did.
     */
    public function startIfConnected(Entity $tenant): bool
    {
        if (!$this->isConnecting($tenant) || !$this->hasConnectedWhatsapp((string) $tenant->getId())) {
            return false;
        }

        $now = time();

        $tenant->set([
            'onboardingStep' => $this->historyImports((string) $tenant->getId()) === []
                ? self::STEP_DONE
                : self::STEP_IMPORT_HISTORY,
            'channelConnectedAt' => gmdate('Y-m-d H:i:s', $now),
            'trialStartsAt' => gmdate('Y-m-d H:i:s', $now),
            'trialEndsAt' => gmdate('Y-m-d H:i:s', $now + self::TRIAL_DAYS * 86400),
        ]);
        $this->entityManager->saveEntity($tenant, ['silent' => true]);

        return true;
    }

    /**
     * Open the workspace once the connected numbers' newest history is in (or
     * the waits ran out). Expects the inboxes' import state freshly synced
     * from Chatwoot. Returns whether it did.
     */
    public function finishImportIfSettled(Entity $tenant, ?int $now = null): bool
    {
        if (!$this->isImportingHistory($tenant)) {
            return false;
        }

        $connectedAt = strtotime($tenant->get('channelConnectedAt') . ' UTC') ?: 0;
        $elapsed = ($now ?? time()) - $connectedAt;

        if (!self::importSettled($this->historyImports((string) $tenant->getId()), $elapsed)) {
            return false;
        }

        $tenant->set('onboardingStep', self::STEP_DONE);
        $this->entityManager->saveEntity($tenant, ['silent' => true]);

        return true;
    }

    /**
     * Whether onboarding may stop waiting: every importing number is past its
     * newest window (phase 1+, as Meta and the QR import both number them) or
     * ended (completed, declined, failed); a number that has not reported yet
     * gets IMPORT_START_WAIT; nothing waits past IMPORT_MAX_WAIT.
     *
     * @param list<array{status: ?string, phase: ?int}> $imports
     */
    public static function importSettled(array $imports, int $elapsed): bool
    {
        if ($elapsed >= self::IMPORT_MAX_WAIT) {
            return true;
        }

        foreach ($imports as $import) {
            $status = $import['status'] ?: null;

            if ($status === 'importing' && (int) $import['phase'] < 1) {
                return false;
            }

            if (in_array($status, [null, 'waiting'], true) && $elapsed < self::IMPORT_START_WAIT) {
                return false;
            }
        }

        return true;
    }

    public static function isWhatsappChannel(?string $channelType): bool
    {
        return in_array($channelType, self::WHATSAPP_CHANNELS, true);
    }

    /**
     * The import state Chatwoot reported (mirrored on ChatwootInbox) for each
     * connected number of the workspace that brings its history.
     *
     * @return list<array{status: ?string, phase: ?int}>
     */
    private function historyImports(string $tenantId): array
    {
        $accountIds = $this->accountIds($tenantId);

        if ($accountIds === []) {
            return [];
        }

        $imports = [];

        foreach ($this->entityManager->getRDBRepository('ChatwootInboxIntegration')->select(['id'])
            ->where([
                'chatwootAccountId' => $accountIds,
                'channelType' => self::HISTORY_CHANNELS,
                'status' => 'ACTIVE',
            ])->find() as $integration) {
            $inbox = $this->entityManager->getRDBRepository('ChatwootInbox')
                ->select(['historyImportStatus', 'historyImportPhase'])
                ->where(['chatwootInboxIntegrationId' => $integration->getId()])
                ->findOne();

            $imports[] = [
                'status' => $inbox?->get('historyImportStatus') ?: null,
                'phase' => $inbox?->get('historyImportPhase') !== null ? (int) $inbox->get('historyImportPhase') : null,
            ];
        }

        return $imports;
    }

    /**
     * @return list<string>
     */
    private function accountIds(string $tenantId): array
    {
        $accountIds = [];

        foreach ($this->entityManager->getRDBRepository('ChatwootAccount')->select(['id'])
            ->where(['tenantId' => $tenantId])->find() as $account) {
            $accountIds[] = (string) $account->getId();
        }

        return $accountIds;
    }

    private function hasConnectedWhatsapp(string $tenantId): bool
    {
        $accountIds = $this->accountIds($tenantId);

        if ($accountIds === []) {
            return false;
        }

        return $this->entityManager
            ->getRDBRepository('ChatwootInboxIntegration')
            ->select(['id'])
            ->where([
                'chatwootAccountId' => $accountIds,
                'channelType' => self::WHATSAPP_CHANNELS,
                'status' => 'ACTIVE',
            ])
            ->findOne() !== null;
    }
}
