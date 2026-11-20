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
use Throwable;

/**
 * SMS via a Chatwoot SMS inbox (Android gateway, Vonage…).
 *
 * Sends to every mobile number of each person on the target: the target itself,
 * or each contact in an Account's Contatos ({@see JourneyTargetPeople}). The text
 * takes `{{…}}` placeholders, filled per person (`{{contact.firstName}}`); a person
 * with an empty placeholder is left out. A phone override sends once, against the
 * target.
 *
 * The send goes through the same conversation trail as WhatsApp, so an SMS reply
 * fires `whatsapp_replied` (its `channelType` says which channel).
 */
class SendSms implements Action
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
            throw new Error('SendSms: missing tenantId.');
        }

        $target = $context->target;
        $this->tenantGuard->assertEntityTenant($target, $tenantId, 'target');

        $params = $context->params;
        $inboxId = isset($params['chatwootInboxId']) ? (string) $params['chatwootInboxId'] : '';
        $body = isset($params['body']) ? trim((string) $params['body']) : '';
        $phoneOverride = isset($params['phone']) ? (string) $params['phone'] : null;

        if ($inboxId === '') {
            throw new Error('SendSms: chatwootInboxId is required.');
        }

        if ($body === '') {
            throw new Error('SendSms: body is required.');
        }

        $hasOverride = $phoneOverride !== null && trim($phoneOverride) !== '';
        $people = $context->recipients($hasOverride ? [$target] : $this->people->forTarget($target, $tenantId));

        if ($people === []) {
            $this->log->warning(
                'SendSms: no contacts on ' . $target->getEntityType() . '/' . $target->getId() . ', skipping.'
            );

            return;
        }

        $conn = $this->outbound->resolveConnection(
            $inboxId,
            $tenantId,
            JourneyWhatsAppOutbound::CHANNELS_SMS,
            $context->actor,
        );

        $journeyContext = [
            'journeyId' => $context->journey->getId(),
            'journeyRecordId' => $context->record->getId(),
        ];

        $errors = [];
        /** @var array<string, list<string>> $missing person name => empty placeholders */
        $missing = [];
        /** @var array<string, string> $undelivered person name => reason, for the record's mural */
        $undelivered = [];
        $sent = 0;
        $attempted = 0;

        foreach ($people as $person) {
            $name = $this->outbound->displayName($person);
            // WhatsApp identities are left out: one can be a landline, which takes no SMS.
            $phones = $context->phones($this->outbound->resolvePhones($person, $phoneOverride, false));

            if ($phones === []) {
                $undelivered[$name] = 'sem celular';

                continue;
            }

            try {
                $rendered = $this->outbound->renderText($body, $target, $person === $target ? null : $person);
            } catch (Throwable $e) {
                $attempted += count($phones);
                $errors[] = $name . ': ' . $e->getMessage();
                $undelivered[$name] = 'erro ao montar a mensagem: ' . JourneyUndeliveredNotice::code($e->getMessage());

                continue;
            }

            // Never send "Oi !": a person with an empty placeholder is left out.
            if ($rendered['empty'] !== []) {
                $missing[$name] = $rendered['empty'];
                $undelivered[$name] = 'sem valor em ' . implode(', ', array_map(
                    static fn (string $item) => JourneyUndeliveredNotice::code($item),
                    $rendered['empty'],
                ));

                continue;
            }

            $attempted += count($phones);
            $sentToPerson = 0;
            $personError = null;

            foreach ($phones as $phone) {
                try {
                    $this->outbound->sendFreeText($conn, $phone, $name, $rendered['text'], $journeyContext);
                    $sent++;
                    $sentToPerson++;
                } catch (Throwable $e) {
                    $errors[] = $name . ' ' . $phone . ': ' . $e->getMessage();
                    $personError ??= $e->getMessage();
                    $this->log->error('SendSms failed for ' . $phone . ': ' . $e->getMessage());
                }
            }

            if ($sentToPerson === 0 && $personError !== null) {
                $undelivered[$name] = 'falha no envio: ' . JourneyUndeliveredNotice::code(mb_substr($personError, 0, 200));
            }
        }

        $this->notice->post($context, 'O SMS', $undelivered, count($people));

        if ($missing !== []) {
            $summary = JourneyTargetPeople::missingSummary($missing);

            // Nobody got it: fail so the journey record shows what is missing.
            if ($attempted === 0) {
                throw new Error($summary);
            }

            $this->log->warning('SendSms: skipped. ' . $summary);
        }

        if ($attempted === 0) {
            $this->log->warning('SendSms: no mobile number on any recipient, skipping.');

            return;
        }

        if ($sent === 0 && $errors !== []) {
            throw new Error('SendSms: all recipients failed. ' . implode('; ', $errors));
        }

        if ($errors !== []) {
            throw new Error('SendSms: sent ' . $sent . '/' . $attempted . ' failed: ' . implode('; ', $errors));
        }
    }
}
