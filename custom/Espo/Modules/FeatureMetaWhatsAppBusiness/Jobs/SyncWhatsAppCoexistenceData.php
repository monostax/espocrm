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

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\WhatsAppCoexistenceSyncService;

/**
 * Job wrapper around {@see WhatsAppCoexistenceSyncService::sync()}.
 *
 * Queued by {@see \Espo\Modules\FeatureMetaWhatsAppBusiness\Controllers\WhatsAppEmbeddedSignup}
 * after a successful Embedded Signup flow returns
 * `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`, and (optionally) re-queued by
 * the embedded-signup controller when the customer hasn't finished the
 * in-app verification step yet (sync returns `status=pending`).
 *
 * Carries a single `oAuthAccountId` so the same job class can drive sync
 * for any onboarded number.
 */
class SyncWhatsAppCoexistenceData implements Job
{
    public function __construct(
        private WhatsAppCoexistenceSyncService $syncService,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $oAuthAccountId = $data->get('oAuthAccountId');

        if (!$oAuthAccountId) {
            $this->log->error('SyncWhatsAppCoexistenceData: missing oAuthAccountId in job data.');

            return;
        }

        try {
            $result = $this->syncService->sync((string) $oAuthAccountId);

            $this->log->info(
                "SyncWhatsAppCoexistenceData: result for OAuthAccount {$oAuthAccountId}: " .
                json_encode($result)
            );
        } catch (\Throwable $e) {
            $this->log->error(
                "SyncWhatsAppCoexistenceData: failed for OAuthAccount {$oAuthAccountId}: " .
                $e->getMessage()
            );
        }
    }
}
