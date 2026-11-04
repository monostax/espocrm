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

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\CutoverInput;
use Espo\Modules\FeatureCredits\Accounting\Cutovers;
use Espo\Modules\FeatureCredits\Accounting\Funding;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use Espo\Modules\FeatureCredits\Services\Configuration;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Self-service workspaces run their AI on the credit wallet from signup:
 *
 *  - provisioning publishes the workspace's agreement (BRL per credit, the
 *    shared signature-bytes conversation policy, optional native search) and
 *    schedules its immutable cutover a minute ahead;
 *  - when the trial starts (first WhatsApp connected), the workspace gets its
 *    trial credits, expiring when the trial ends;
 *  - a paid plan funds the wallet through the billing engine.
 *
 * Off until config `selfServiceCreditWallet` names the published model rate:
 *   {"aiModelCreditRateId": "...", "aiNativeSearchModelCreditRateId": "...",
 *    "creditUnitPrice": "0.50", "currency": "BRL", "aiMaxRequests": 100,
 *    "trialCredits": "100"}
 * Without it, new workspaces keep the legacy run cap.
 */
class WorkspaceWallet
{
    public const AGREEMENT_VERSION = 'self-service-v1';
    public const TRIAL_SOURCE_KEY = 'self-service-trial';
    private const CUTOVER_DELAY_SECONDS = 60;
    private const DEFAULT_TRIAL_CREDITS = '100';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Configuration $configuration,
        private Cutovers $cutovers,
        private Funding $funding,
        private Log $log,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings() !== null;
    }

    /**
     * The workspace's agreement and cutover. Safe to retry: the agreement
     * replays from the same inputs ($since must be stable, e.g. the
     * provisioning's creation time) and an existing cutover is kept.
     */
    public function ensure(string $tenantId, string $since, string $provisioningId): void
    {
        $settings = $this->settings();

        if ($settings === null) {
            return;
        }

        $agreement = (object) [
            'tenantId' => $tenantId,
            'version' => self::AGREEMENT_VERSION,
            'effectiveFrom' => $since,
            'effectiveUntil' => null,
            'currency' => (string) ($settings['currency'] ?? 'BRL'),
            'creditUnitPrice' => (string) ($settings['creditUnitPrice'] ?? '0.50'),
            'monthlyCredits' => '0',
            'aiModelCreditRateId' => (string) $settings['aiModelCreditRateId'],
            'aiMaxRequests' => (int) ($settings['aiMaxRequests'] ?? 100),
        ];

        if (!empty($settings['aiNativeSearchModelCreditRateId'])) {
            $agreement->aiNativeSearchModelCreditRateId = (string) $settings['aiNativeSearchModelCreditRateId'];
        }

        $this->configuration->publishAgreement($agreement);

        if ($this->hasCutover($tenantId)) {
            return;
        }

        $this->cutovers->schedule(new CutoverInput(
            $tenantId,
            gmdate('Y-m-d H:i:s', time() + self::CUTOVER_DELAY_SECONDS),
            (object) [
                'source' => 'self-service-provisioning',
                'provisioningId' => $provisioningId,
                'policy' => 'Self-service workspaces start on the credit wallet (decided 2026-11-04).',
            ]
        ));
    }

    /**
     * Trial credits for every wallet workspace whose trial is running and has
     * none yet. Returns how many were granted.
     */
    public function grantStartedTrials(): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        $granted = 0;

        foreach ($this->entityManager->getRDBRepository('Tenant')
            ->where([
                'plan!=' => '',
                'subscriptionStatus' => WorkspaceAccess::STATUS_TRIAL,
                'trialStartsAt!=' => null,
                'trialEndsAt>' => gmdate('Y-m-d H:i:s'),
            ])
            ->find() as $tenant) {
            try {
                if ($this->grantTrialCredits($tenant)) {
                    $granted++;
                }
            } catch (Throwable $e) {
                $this->log->error("SelfService: trial credits for tenant {$tenant->getId()} failed: " . $e->getMessage());
            }
        }

        return $granted;
    }

    /**
     * The trial's credits, once, expiring when the trial ends. Only for
     * workspaces on the wallet (they have a cutover). Returns whether it granted.
     */
    public function grantTrialCredits(Entity $tenant): bool
    {
        $settings = $this->settings();
        $tenantId = (string) $tenant->getId();
        $endsAt = (string) $tenant->get('trialEndsAt');
        $now = gmdate('Y-m-d H:i:s');

        if ($settings === null || !$tenant->get('trialStartsAt') || $endsAt <= $now ||
            !$this->hasCutover($tenantId) || $this->hasTrialGrant($tenantId)) {
            return false;
        }

        $this->funding->grant(new GrantInput(
            $tenantId,
            'migration',
            self::TRIAL_SOURCE_KEY,
            (string) ($settings['trialCredits'] ?? self::DEFAULT_TRIAL_CREDITS),
            $now,
            $endsAt,
            (object) ['source' => 'self-service-trial', 'trialStartsAt' => (string) $tenant->get('trialStartsAt'), 'trialEndsAt' => $endsAt],
        ));
        $this->log->info("SelfService: trial credits granted to tenant {$tenantId}, expiring {$endsAt}.");

        return true;
    }

    private function hasCutover(string $tenantId): bool
    {
        $query = $this->entityManager->getPDO()->prepare('SELECT 1 FROM tenant_credit_cutover WHERE tenant_id = ? AND deleted = FALSE');
        $query->execute([$tenantId]);

        return (bool) $query->fetchColumn();
    }

    private function hasTrialGrant(string $tenantId): bool
    {
        $query = $this->entityManager->getPDO()->prepare('SELECT 1 FROM credit_grant WHERE tenant_id = ? AND source_key = ?');
        $query->execute([$tenantId, self::TRIAL_SOURCE_KEY]);

        return (bool) $query->fetchColumn();
    }

    /**
     * @return ?array<string, mixed>
     */
    private function settings(): ?array
    {
        $settings = $this->config->get('selfServiceCreditWallet');

        return is_array($settings) && is_string($settings['aiModelCreditRateId'] ?? null) &&
            $settings['aiModelCreditRateId'] !== '' ? $settings : null;
    }
}
