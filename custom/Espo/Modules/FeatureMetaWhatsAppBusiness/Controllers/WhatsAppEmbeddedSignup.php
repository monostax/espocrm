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
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\MetaGraphApiClient;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppCoexistenceSyncService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\TokensProvider;
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

        $account = $this->getEditableOAuthAccount((string) $oAuthAccountId);

        return $this->finishOnboarding(
            $account,
            (string) $oAuthAccountId,
            (string) $onboardingType,
            (string) $wabaId,
            (string) $phoneNumberId,
            $sessionInfo,
        );
    }

    /**
     * POST WhatsAppEmbeddedSignup/hydrate
     *
     * Body: { oAuthAccountId: string, onboardingType?: 'cloud_api'|'coexistence' }
     *
     * Recovery path when Embedded Signup session_info postMessage was missed
     * (common race after FB.login resolves). Discovers the single assigned
     * WABA + phone via Graph and reuses the same finish path.
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function postActionHydrate(Request $request): stdClass
    {
        $body = $request->getParsedBody();
        $oAuthAccountId = $body->oAuthAccountId ?? null;
        $onboardingType = $body->onboardingType ?? null;

        if (!$oAuthAccountId || !is_string($oAuthAccountId)) {
            throw new BadRequest('oAuthAccountId is required.');
        }

        $account = $this->getEditableOAuthAccount($oAuthAccountId);

        if (!$onboardingType || !in_array($onboardingType, self::VALID_ONBOARDING_TYPES, true)) {
            $onboardingType = $account->get('providerType') === 'meta-whatsapp-coexistence'
                ? 'coexistence'
                : 'cloud_api';
        }

        $tokensProvider = $this->injectableFactory->create(TokensProvider::class);
        $apiClient = $this->injectableFactory->create(MetaGraphApiClient::class);

        $tokens = $tokensProvider->get($oAuthAccountId);
        $accessToken = $tokens->getAccessToken();

        if (!$accessToken) {
            throw new Error('OAuthAccount has no access token — connect Embedded Signup first.');
        }

        $wabaId = (string) ($account->get('whatsappBusinessAccountId') ?? '');
        $phoneNumberId = (string) ($account->get('whatsappPhoneNumberId') ?? '');

        if ($wabaId === '') {
            $assigned = $apiClient->discoverAssignedWabas($accessToken);

            if (count($assigned) === 0) {
                throw new Error(
                    'No WhatsApp Business Accounts assigned to this token. ' .
                    'Re-run Embedded Signup and complete the flow until FINISH.'
                );
            }

            if (count($assigned) > 1) {
                throw new Error(
                    'Multiple WhatsApp Business Accounts are assigned to this token. ' .
                    'Re-run Embedded Signup so Meta returns the selected waba_id / phone_number_id.'
                );
            }

            $wabaId = (string) ($assigned[0]['id'] ?? '');
        }

        if ($wabaId === '') {
            throw new Error('Could not resolve WhatsApp Business Account id from Graph.');
        }

        if ($phoneNumberId === '') {
            $phones = $apiClient->getPhoneNumbers($accessToken, $wabaId);

            if (count($phones) === 0) {
                throw new Error("WABA {$wabaId} has no phone numbers.");
            }

            if (count($phones) > 1) {
                throw new Error(
                    "WABA {$wabaId} has multiple phone numbers. " .
                    'Re-run Embedded Signup so Meta returns phone_number_id in session_info.'
                );
            }

            $phoneNumberId = (string) ($phones[0]['id'] ?? '');
        }

        if ($phoneNumberId === '') {
            throw new Error('Could not resolve phone_number_id from Graph.');
        }

        $this->log->info(
            "WhatsAppEmbeddedSignup: hydrate resolved oAuthAccount={$oAuthAccountId} " .
            "waba={$wabaId} phone={$phoneNumberId} type={$onboardingType}."
        );

        $result = $this->finishOnboarding(
            $account,
            $oAuthAccountId,
            (string) $onboardingType,
            $wabaId,
            $phoneNumberId,
            (object) [
                'source' => 'hydrate',
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
            ],
        );

        $result->hydrated = true;

        return $result;
    }

    /**
     * @throws Forbidden
     * @throws NotFound
     */
    private function getEditableOAuthAccount(string $oAuthAccountId): Entity
    {
        $account = $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId);

        if (!$account) {
            throw new NotFound('OAuthAccount not found.');
        }

        if (!$this->acl->check($account, 'edit')) {
            throw new Forbidden("You don't have edit access to this OAuth Account.");
        }

        return $account;
    }

    /**
     * Persist WABA/phone/onboarding metadata and (for coexistence) run sync.
     *
     * @param string|stdClass|array<string, mixed>|null $sessionInfo
     */
    private function finishOnboarding(
        Entity $account,
        string $oAuthAccountId,
        string $onboardingType,
        string $wabaId,
        string $phoneNumberId,
        string|stdClass|array|null $sessionInfo,
    ): stdClass {
        // Persist session_info on the OAuthAccount. We do this BEFORE running
        // the Coexistence sync so a job retry can read the fields back from
        // the entity if the controller-side sync attempt crashes.
        $account->set('whatsappBusinessAccountId', $wabaId);
        $account->set('whatsappPhoneNumberId', $phoneNumberId);
        $account->set('whatsappOnboardingType', $onboardingType);

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
                $syncResult = $syncService->sync($oAuthAccountId);
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
                    ->setData(['oAuthAccountId' => $oAuthAccountId])
                    ->schedule();

                $this->log->info(
                    "WhatsAppEmbeddedSignup: queued SyncWhatsAppCoexistenceData job for {$oAuthAccountId} " .
                    "(inline result: " . json_encode($syncResult) . ")."
                );
            }
        }

        return (object) [
            'oAuthAccountId' => $oAuthAccountId,
            'onboardingType' => $onboardingType,
            'wabaId' => $wabaId,
            'phoneNumberId' => $phoneNumberId,
            'sync' => $syncResult,
        ];
    }
}
