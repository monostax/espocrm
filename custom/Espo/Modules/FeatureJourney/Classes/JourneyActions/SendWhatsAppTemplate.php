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
 * Sends to every sendable phone on the target (or phone override list).
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

        $target = $context->target;
        if ($target->getEntityType() === 'User') {
            $this->tenantGuard->assertUserInTenant($target->getId(), $tenantId, 'target-user');
        } elseif ($target->getEntityType() !== 'Tenant') {
            $this->tenantGuard->assertEntityTenant($target, $tenantId, 'target');
        }

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

        if ($this->outbound->isOptedOut($target)) {
            $this->log->warning(
                'SendWhatsAppTemplate: target opted out of WhatsApp, skipping. ' .
                $target->getEntityType() . '/' . $target->getId()
            );

            return;
        }

        $phones = $this->outbound->resolvePhones($target, $phoneOverride);
        if ($phones === []) {
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
        $resolved = $this->outbound->resolveParameterMapping($mapping, $target);

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

        $name = $this->outbound->displayName($target);
        $journeyContext = [
            'journeyId' => $context->journey->getId(),
            'journeyRecordId' => $context->record->getId(),
        ];

        $errors = [];
        $sent = 0;

        foreach ($phones as $phone) {
            try {
                $this->outbound->sendTemplate(
                    $conn,
                    $phone,
                    $name,
                    $templateName,
                    $language,
                    $resolved,
                    $category !== '' ? $category : 'UTILITY',
                    $content,
                    $headerUrl,
                    $headerType,
                    $journeyContext,
                );
                $sent++;
            } catch (Throwable $e) {
                $errors[] = $phone . ': ' . $e->getMessage();
                $this->log->error(
                    'SendWhatsAppTemplate failed for ' . $phone . ': ' . $e->getMessage()
                );
            }
        }

        if ($sent === 0 && $errors !== []) {
            throw new Error('SendWhatsAppTemplate: all recipients failed. ' . implode('; ', $errors));
        }

        if ($errors !== []) {
            throw new Error(
                'SendWhatsAppTemplate: sent ' . $sent . '/' . count($phones) .
                ' failed: ' . implode('; ', $errors)
            );
        }
    }
}
