<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Classes\AutomationActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Throwable;

/**
 * WhatsApp free-text; supports User targets via userBelongsToTenant + phone override.
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

        $target = $context->target;
        if ($target->getEntityType() === 'User') {
            $this->tenantGuard->assertUserInTenant($target->getId(), $tenantId, 'target-user');
        } elseif ($target->getEntityType() !== 'Tenant') {
            $this->tenantGuard->assertEntityTenant($target, $tenantId, 'target');
        }

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

        if ($this->outbound->isOptedOut($target)) {
            $this->log->warning('Automation SendWhatsAppMessage: target opted out.');

            return;
        }

        $phone = $this->outbound->resolvePhone($target, $phoneOverride);
        if ($phone === null) {
            $this->log->warning('Automation SendWhatsAppMessage: no phone, skipping.');

            return;
        }

        $conn = $this->outbound->resolveConnection(
            $inboxId,
            $tenantId,
            JourneyWhatsAppOutbound::CHANNELS_MESSAGE,
            $context->actor,
        );

        $name = $this->outbound->displayName($target);

        try {
            $this->outbound->sendFreeText($conn, $phone, $name, $body);
        } catch (Throwable $e) {
            throw new Error('Automation SendWhatsAppMessage failed: ' . $e->getMessage());
        }
    }
}
