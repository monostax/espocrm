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

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Jobs\SyncWhatsAppCoexistenceData;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppCoexistenceSyncService;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Controller for the WhatsApp Embedded Signup v4 follow-up handshake.
 *
 * The OAuth code → access_token exchange itself reuses EspoCRM's generic
 * `POST /OAuth/{id}/connection` route — Facebook's Embedded Signup popup
 * returns a standard authorization-code grant code that the existing
 * {@see \Espo\Tools\OAuth\Api\PostConnection} action already handles
 * correctly. We do NOT duplicate the token exchange.
 *
 * What this controller is for: the **Embedded Signup-specific metadata**
 * the popup returns via the `WA_EMBEDDED_SIGNUP` postMessage event
 * (sessionInfoVersion 3), which the standard OAuth flow knows nothing
 * about:
 *
 *   - `waba_id` / `phone_number_id` (must be persisted on the OAuthAccount
 *     so we can drive Cloud API + Coexistence sync afterwards)
 *   - `event` (`FINISH` for Cloud API only, `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`
 *     for Coexistence). This determines `whatsappOnboardingType`.
 *   - `current_step` (`SUCCESS_*`) for diagnostics.
 *
 * The frontend MUST call this controller AFTER `POST /OAuth/{id}/connection`
 * has stored the access token, so the WABA fetch / `subscribeApp` /
 * `postSmbAppData` calls in the sync service can use the real token.
 *
 * For Coexistence (`featureType=whatsapp_business_app_onboarding`), this
 * controller also kicks off the {@see SyncWhatsAppCoexistenceData} job
 * which is bound by Meta's hard 24h deadline.
 */
class WhatsAppEmbeddedSignup
{
    private const VALID_ONBOARDING_TYPES = ['cloud_api', 'coexistence'];

    public function __construct(
        private InjectableFactory $injectableFactory,
        private EntityManager $entityManager,
        private Acl $acl,
        private Log $log,
        private JobSchedulerFactory $jobSchedulerFactory,
    ) {}

    /**
     * POST WhatsAppEmbeddedSignup/finish
     *
     * Body:
     *   {
     *     oAuthAccountId: string,                  // The OAuthAccount we just connected
     *     onboardingType: 'cloud_api'|'coexistence',
     *     wabaId: string,                          // session_info.waba_id
     *     phoneNumberId: string,                   // session_info.phone_number_id
     *     sessionInfo?: object                     // Full session_info payload (for diagnostics)
     *   }
     *
     * Persists session_info on the OAuthAccount and, for Coexistence,
     * queues the sync job. Returns the sync result inline when possible
     * so the UI can surface "still waiting for the in-app code paste"
     * vs "all good".
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function postActionFinish(Request $request): stdClass
    {
        $body = $request->getParsedBody();

        $oAuthAccountId = $body->oAuthAccountId ?? null;
        $onboardingType = $body->onboardingType ?? null;
        $wabaId = $body->wabaId ?? null;
        $phoneNumberId = $body->phoneNumberId ?? null;
        $sessionInfo = $body->sessionInfo ?? null;

        if (!$oAuthAccountId) {
            throw new BadRequest('oAuthAccountId is required.');
        }

        if (!$onboardingType || !in_array($onboardingType, self::VALID_ONBOARDING_TYPES, true)) {
            throw new BadRequest('onboardingType must be one of: ' . implode(', ', self::VALID_ONBOARDING_TYPES) . '.');
        }

        if (!$wabaId) {
            throw new BadRequest('wabaId is required.');
        }

        if (!$phoneNumberId) {
            throw new BadRequest('phoneNumberId is required.');
        }

        $account = $this->entityManager->getEntityById('OAuthAccount', (string) $oAuthAccountId);

        if (!$account) {
            throw new NotFound('OAuthAccount not found.');
        }

        if (!$this->acl->check($account, 'edit')) {
            throw new Forbidden("You don't have edit access to this OAuth Account.");
        }

        // Persist session_info on the OAuthAccount. We do this BEFORE running
        // the Coexistence sync so a job retry can read the fields back from
        // the entity if the controller-side sync attempt crashes.
        $account->set('whatsappBusinessAccountId', (string) $wabaId);
        $account->set('whatsappPhoneNumberId', (string) $phoneNumberId);
        $account->set('whatsappOnboardingType', (string) $onboardingType);

        if ($sessionInfo !== null) {
            $account->set('whatsappSessionInfo', $sessionInfo);
        }

        $this->entityManager->saveEntity($account);

        $this->log->info(
            "WhatsAppEmbeddedSignup: persisted session_info on OAuthAccount {$oAuthAccountId} " .
            "(waba={$wabaId}, phone={$phoneNumberId}, type={$onboardingType})."
        );

        $syncResult = null;

        if ($onboardingType === 'coexistence') {
            // Try the sync inline first — when the customer has finished the
            // in-app verification before clicking the popup's last button,
            // we can complete everything in one HTTP round-trip and the UI
            // shows ACTIVE immediately.
            try {
                $syncService = $this->injectableFactory->create(WhatsAppCoexistenceSyncService::class);
                $syncResult = $syncService->sync((string) $oAuthAccountId);
            } catch (\Throwable $e) {
                $this->log->warning(
                    "WhatsAppEmbeddedSignup: inline sync threw for OAuthAccount {$oAuthAccountId} " .
                    "({$e->getMessage()}); queuing job for retry."
                );

                $syncResult = [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }

            // Queue a job too — when inline returned `pending` (customer hasn't
            // finished the in-app step yet), the job will run a few minutes
            // later and retry. The job is idempotent at the OAuthAccount level.
            if (!isset($syncResult['status']) || $syncResult['status'] !== 'synced') {
                $this->jobSchedulerFactory->create()
                    ->setClassName(SyncWhatsAppCoexistenceData::class)
                    ->setData(['oAuthAccountId' => (string) $oAuthAccountId])
                    ->schedule();

                $this->log->info(
                    "WhatsAppEmbeddedSignup: queued SyncWhatsAppCoexistenceData job for {$oAuthAccountId} " .
                    "(inline result: " . json_encode($syncResult) . ")."
                );
            }
        }

        return (object) [
            'oAuthAccountId' => (string) $oAuthAccountId,
            'onboardingType' => $onboardingType,
            'wabaId' => $wabaId,
            'phoneNumberId' => $phoneNumberId,
            'sync' => $syncResult,
        ];
    }
}
