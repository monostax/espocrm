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
 * Provisioning sets `plan` advanced, `subscriptionStatus` trial and
 * `onboardingStep` connectWhatsapp. The 7 days start when the first WhatsApp
 * channel is connected (an ACTIVE inbox integration). Tenants with no plan are
 * not managed by self-service: no onboarding gate, no trial.
 */
class WorkspaceTrial
{
    public const TRIAL_DAYS = 7;

    public const STEP_CONNECT_WHATSAPP = 'connectWhatsapp';
    public const STEP_DONE = 'done';

    private const WHATSAPP_CHANNELS = ['whatsappQrcode', 'whatsappCloudApi', 'whatsappCoexistence'];

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
            'plan' => 'advanced',
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
        ];
    }

    public function isConnecting(Entity $tenant): bool
    {
        return $tenant->get('onboardingStep') === self::STEP_CONNECT_WHATSAPP;
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
            'onboardingStep' => self::STEP_DONE,
            'channelConnectedAt' => gmdate('Y-m-d H:i:s', $now),
            'trialStartsAt' => gmdate('Y-m-d H:i:s', $now),
            'trialEndsAt' => gmdate('Y-m-d H:i:s', $now + self::TRIAL_DAYS * 86400),
        ]);
        $this->entityManager->saveEntity($tenant, ['silent' => true]);

        return true;
    }

    public static function isWhatsappChannel(?string $channelType): bool
    {
        return in_array($channelType, self::WHATSAPP_CHANNELS, true);
    }

    private function hasConnectedWhatsapp(string $tenantId): bool
    {
        $accountIds = [];

        foreach ($this->entityManager->getRDBRepository('ChatwootAccount')->select(['id'])
            ->where(['tenantId' => $tenantId])->find() as $account) {
            $accountIds[] = (string) $account->getId();
        }

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
