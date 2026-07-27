<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Throwable;

/**
 * Meta template WhatsApp via Chatwoot (Cloud API / Coexistence only).
 * Parameter mapping matches WhatsAppCampaign (Handlebars {{field}}).
 */
class SendWhatsAppTemplate implements Action
{
    public function __construct(
        private JourneyWhatsAppOutbound $outbound,
        private TenantGuard $tenantGuard,
        private Log $log,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('SendWhatsAppTemplate: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $params = $context->params;
        $inboxId = isset($params['chatwootInboxId']) ? (string) $params['chatwootInboxId'] : '';
        $templateName = isset($params['templateName']) ? trim((string) $params['templateName']) : '';
        $language = isset($params['templateLanguage']) ? trim((string) $params['templateLanguage']) : '';
        $category = isset($params['templateCategory']) ? trim((string) $params['templateCategory']) : 'UTILITY';
        $phoneOverride = isset($params['phone']) ? (string) $params['phone'] : null;

        if ($inboxId === '') {
            throw new Error('SendWhatsAppTemplate: chatwootInboxId is required.');
        }

        if ($templateName === '') {
            throw new Error('SendWhatsAppTemplate: templateName is required.');
        }

        if ($language === '') {
            $language = 'pt_BR';
        }

        if ($this->outbound->isOptedOut($context->target)) {
            $this->log->warning(
                'SendWhatsAppTemplate: target opted out of WhatsApp, skipping. ' .
                $context->target->getEntityType() . '/' . $context->target->getId()
            );

            return;
        }

        $phone = $this->outbound->resolvePhone($context->target, $phoneOverride);
        if ($phone === null) {
            $this->log->warning('SendWhatsAppTemplate: no phone on target, skipping.');

            return;
        }

        $conn = $this->outbound->resolveConnection(
            $inboxId,
            $tenantId,
            JourneyWhatsAppOutbound::CHANNELS_TEMPLATE,
            $context->actor,
        );

        $mapping = $params['parameterMapping'] ?? [];
        $resolved = $this->outbound->resolveParameterMapping($mapping, $context->target);

        $headerUrl = isset($params['headerMediaUrl']) ? (string) $params['headerMediaUrl'] : null;
        $headerType = isset($params['headerMediaType']) ? (string) $params['headerMediaType'] : null;
        if ($headerUrl === '') {
            $headerUrl = null;
        }
        if ($headerType === '') {
            $headerType = null;
        }

        $templateBody = isset($params['templateBody']) ? (string) $params['templateBody'] : '';
        $content = $templateBody;
        if ($content !== '' && $resolved !== []) {
            foreach ($resolved as $num => $value) {
                $content = str_replace('{{' . $num . '}}', $value, $content);
            }
        }

        try {
            $this->outbound->sendTemplate(
                $conn,
                $phone,
                $this->outbound->displayName($context->target),
                $templateName,
                $language,
                $resolved,
                $category !== '' ? $category : 'UTILITY',
                $content,
                $headerUrl,
                $headerType,
            );
        } catch (Throwable $e) {
            $this->log->error('SendWhatsAppTemplate failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
