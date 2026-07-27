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
 * Free-text WhatsApp via Chatwoot inbox (WAHA QR / Cloud / Coexistence).
 *
 * Cloud/Coexistence: when the 24h session window is closed, optional fallback
 * Meta template (name + mapping) is sent instead of failing hard.
 */
class SendWhatsAppMessage implements Action
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
            throw new Error('SendWhatsAppMessage: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $params = $context->params;
        $inboxId = isset($params['chatwootInboxId']) ? (string) $params['chatwootInboxId'] : '';
        $body = isset($params['body']) ? trim((string) $params['body']) : '';
        $phoneOverride = isset($params['phone']) ? (string) $params['phone'] : null;

        if ($inboxId === '') {
            throw new Error('SendWhatsAppMessage: chatwootInboxId is required.');
        }

        if ($body === '') {
            throw new Error('SendWhatsAppMessage: body is required.');
        }

        if ($this->outbound->isOptedOut($context->target)) {
            $this->log->warning(
                'SendWhatsAppMessage: target opted out of WhatsApp, skipping. ' .
                $context->target->getEntityType() . '/' . $context->target->getId()
            );

            return;
        }

        $phone = $this->outbound->resolvePhone($context->target, $phoneOverride);
        if ($phone === null) {
            $this->log->warning('SendWhatsAppMessage: no phone on target, skipping.');

            return;
        }

        $conn = $this->outbound->resolveConnection(
            $inboxId,
            $tenantId,
            JourneyWhatsAppOutbound::CHANNELS_MESSAGE,
            $context->actor,
        );

        $name = $this->outbound->displayName($context->target);

        try {
            $this->outbound->sendFreeText($conn, $phone, $name, $body);

            return;
        } catch (Throwable $e) {
            $fallbackName = isset($params['fallbackTemplateName'])
                ? trim((string) $params['fallbackTemplateName'])
                : '';
            $channel = $conn['channelType'];
            $canFallback = $fallbackName !== ''
                && in_array($channel, JourneyWhatsAppOutbound::CHANNELS_TEMPLATE, true)
                && $this->outbound->isOutsideSessionWindow($e->getMessage());

            if (!$canFallback) {
                $this->log->error('SendWhatsAppMessage failed: ' . $e->getMessage());
                throw $e;
            }

            $this->log->info(
                'SendWhatsAppMessage: session window closed; sending fallback template ' .
                $fallbackName
            );
        }

        $language = isset($params['fallbackTemplateLanguage'])
            ? trim((string) $params['fallbackTemplateLanguage'])
            : 'pt_BR';
        if ($language === '') {
            $language = 'pt_BR';
        }

        $category = isset($params['fallbackTemplateCategory'])
            ? trim((string) $params['fallbackTemplateCategory'])
            : 'UTILITY';

        $mapping = $params['fallbackParameterMapping'] ?? [];
        $resolved = $this->outbound->resolveParameterMapping($mapping, $context->target);

        $headerUrl = isset($params['fallbackHeaderMediaUrl'])
            ? (string) $params['fallbackHeaderMediaUrl']
            : null;
        $headerType = isset($params['fallbackHeaderMediaType'])
            ? (string) $params['fallbackHeaderMediaType']
            : null;
        if ($headerUrl === '') {
            $headerUrl = null;
        }
        if ($headerType === '') {
            $headerType = null;
        }

        try {
            $this->outbound->sendTemplate(
                $conn,
                $phone,
                $name,
                $fallbackName,
                $language,
                $resolved,
                $category !== '' ? $category : 'UTILITY',
                '',
                $headerUrl,
                $headerType,
            );
        } catch (Throwable $e) {
            $this->log->error('SendWhatsAppMessage fallback template failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
