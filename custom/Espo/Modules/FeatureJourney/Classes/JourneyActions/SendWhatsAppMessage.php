<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyTargetPeople;
use Espo\Modules\FeatureJourney\Services\JourneyUndeliveredNotice;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Throwable;

/**
 * Free-text WhatsApp via Chatwoot inbox (WAHA QR / Cloud / Coexistence).
 *
 * Sends to every sendable phone of each person on the target: the target itself,
 * or each contact in an Account's Contatos ({@see JourneyTargetPeople}). A phone
 * override sends once, against the target.
 * Cloud/Coexistence: when the 24h session window is closed, optional fallback
 * Meta template (name + mapping) is sent instead of failing hard.
 */
class SendWhatsAppMessage implements Action
{
    public function __construct(
        private JourneyWhatsAppOutbound $outbound,
        private TenantGuard $tenantGuard,
        private JourneyTargetPeople $people,
        private JourneyUndeliveredNotice $notice,
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

        // A phone override is one fixed recipient; otherwise every person on the target
        // (an Account's Contatos) gets the message on their own phones.
        $hasOverride = $phoneOverride !== null && trim($phoneOverride) !== '';
        $people = $hasOverride ? [$context->target] : $this->people->forTarget($context->target, $tenantId);

        if ($people === []) {
            $this->log->warning(
                'SendWhatsAppMessage: no contacts on ' . $context->target->getEntityType() . '/' .
                $context->target->getId() . ', skipping.'
            );

            return;
        }

        $conn = $this->outbound->resolveConnection(
            $inboxId,
            $tenantId,
            JourneyWhatsAppOutbound::CHANNELS_MESSAGE,
            $context->actor,
        );

        $journeyContext = [
            'journeyId' => $context->journey->getId(),
            'journeyRecordId' => $context->record->getId(),
        ];

        $errors = [];
        /** @var array<string, string> $undelivered person name => reason, for the record's mural */
        $undelivered = [];
        $sent = 0;
        $attempted = 0;

        foreach ($people as $person) {
            $name = $this->outbound->displayName($person);

            if ($this->outbound->isOptedOut($person)) {
                $undelivered[$name] = 'saiu do WhatsApp (opt-out)';
                $this->log->warning(
                    'SendWhatsAppMessage: opted out of WhatsApp, skipping ' .
                    $person->getEntityType() . '/' . $person->getId()
                );

                continue;
            }

            $phones = $this->outbound->resolvePhones($person, $phoneOverride);

            if ($phones === []) {
                $undelivered[$name] = 'sem celular';
                $this->log->info(
                    'SendWhatsAppMessage: no phone on ' . $person->getEntityType() . '/' . $person->getId()
                );

                continue;
            }

            $attempted += count($phones);
            $templatePerson = $person === $context->target ? null : $person;
            $sentToPerson = 0;
            $personError = null;

            foreach ($phones as $phone) {
                try {
                    $this->sendOne($conn, $phone, $name, $body, $params, $context, $journeyContext, $templatePerson);
                    $sent++;
                    $sentToPerson++;
                } catch (Throwable $e) {
                    $errors[] = $name . ' ' . $phone . ': ' . $e->getMessage();
                    $personError ??= $e->getMessage();
                    $this->log->error(
                        'SendWhatsAppMessage failed for ' . $phone . ': ' . $e->getMessage()
                    );
                }
            }

            if ($sentToPerson === 0 && $personError !== null) {
                $undelivered[$name] = 'falha no envio: ' . JourneyUndeliveredNotice::code(mb_substr($personError, 0, 200));
            }
        }

        $this->notice->post($context, 'O WhatsApp', $undelivered, count($people));

        if ($attempted === 0) {
            $this->log->warning('SendWhatsAppMessage: no phone on any recipient, skipping.');

            return;
        }

        if ($sent === 0 && $errors !== []) {
            throw new Error('SendWhatsAppMessage: all recipients failed. ' . implode('; ', $errors));
        }

        if ($errors !== []) {
            throw new Error(
                'SendWhatsAppMessage: sent ' . $sent . '/' . $attempted .
                ' failed: ' . implode('; ', $errors)
            );
        }
    }

    /**
     * @param array<string, mixed> $conn
     * @param array<string, mixed> $params
     * @param array{journeyId: string, journeyRecordId: string} $journeyContext
     */
    private function sendOne(
        array $conn,
        string $phone,
        string $name,
        string $body,
        array $params,
        ActionContext $context,
        array $journeyContext,
        ?Entity $person,
    ): void {
        try {
            $this->outbound->sendFreeText($conn, $phone, $name, $body, $journeyContext);

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
                throw $e;
            }

            $this->log->info(
                'SendWhatsAppMessage: session window closed for ' . $phone .
                '; sending fallback template ' . $fallbackName
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
        $resolved = $this->outbound->resolveParameterMapping($mapping, $context->target, $person);

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
            $journeyContext,
        );
    }
}
