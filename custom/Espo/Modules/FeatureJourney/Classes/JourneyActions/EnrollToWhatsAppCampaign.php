<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\WhatsAppCampaignService;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TenantGuard;

/**
 * Enroll the journey target Contact into a running WhatsApp Campaign.
 *
 * Creates Pending WhatsAppCampaignContact row(s) (one per sendable phone)
 * and schedules chunk send jobs via WhatsAppCampaignService::enrollAudience.
 * Idempotent by phoneNumber within the campaign.
 *
 * Params: whatsAppCampaignId (required), phone (optional override list).
 * Target must be Contact; campaign must be same-tenant and status Sending.
 */
class EnrollToWhatsAppCampaign implements Action
{
    public function __construct(
        private WhatsAppCampaignService $campaignService,
        private JourneyWhatsAppOutbound $outbound,
        private TenantGuard $tenantGuard,
        private Log $log,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('EnrollToWhatsAppCampaign: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        if ($context->target->getEntityType() !== 'Contact') {
            throw new Error(
                'EnrollToWhatsAppCampaign: target must be Contact ' .
                '(got ' . $context->target->getEntityType() . ').'
            );
        }

        $params = $context->params;
        $campaignId = isset($params['whatsAppCampaignId'])
            ? trim((string) $params['whatsAppCampaignId'])
            : '';
        $phoneOverride = isset($params['phone']) ? (string) $params['phone'] : null;

        if ($campaignId === '') {
            throw new Error('EnrollToWhatsAppCampaign: whatsAppCampaignId is required.');
        }

        $campaign = $this->tenantGuard->loadEntityInTenant(
            'WhatsAppCampaign',
            $campaignId,
            $tenantId,
            'whatsAppCampaign'
        );

        $status = (string) ($campaign->get('status') ?? '');
        if ($status !== 'Sending') {
            throw new Error(
                "EnrollToWhatsAppCampaign: campaign {$campaignId} status is '{$status}' " .
                '(expected Sending). Start the campaign before enrolling from a journey.'
            );
        }

        if ($this->outbound->isOptedOut($context->target)) {
            $this->log->warning(
                'EnrollToWhatsAppCampaign: target opted out of WhatsApp, skipping. ' .
                'Contact/' . $context->target->getId()
            );

            return;
        }

        $phones = $this->outbound->resolvePhones($context->target, $phoneOverride);
        if ($phones === []) {
            $this->log->warning(
                'EnrollToWhatsAppCampaign: no phone on target, skipping. ' .
                'Contact/' . $context->target->getId()
            );

            return;
        }

        $contactId = $context->target->getId();
        $contactName = $this->outbound->displayName($context->target);
        $audience = [];

        foreach ($phones as $phone) {
            $audience[] = [
                'contactId' => $contactId,
                'phoneNumber' => $phone,
                'contactName' => $contactName,
                'targetListIds' => [],
            ];
        }

        $enrolled = $this->campaignService->enrollAudience($campaignId, $audience);

        $this->log->info(
            "EnrollToWhatsAppCampaign: campaign={$campaignId} contact={$contactId} " .
            'phones=' . count($phones) . " newlyEnrolled={$enrolled} " .
            'journeyRecord=' . $context->record->getId()
        );
    }
}
