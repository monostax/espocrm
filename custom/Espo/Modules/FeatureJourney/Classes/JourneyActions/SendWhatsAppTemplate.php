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
use Espo\Modules\FeatureMetaWhatsAppBusiness\Tools\TemplateParameters;
use Throwable;

/**
 * Meta template WhatsApp via Chatwoot (Cloud API / Coexistence only).
 * Parameter mapping matches WhatsAppCampaign (Handlebars {{field}}).
 *
 * Sends to every sendable phone of each person on the target: the target
 * itself, or each contact in an Account's Contatos ({@see JourneyTargetPeople}),
 * rendered per person (`{{Contact.firstName}}`). A phone override sends once,
 * against the target.
 */
class SendWhatsAppTemplate implements Action
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

        // A phone override is one fixed recipient; otherwise every person on the target
        // (an Account's Contatos) gets their own message on their own phones.
        $hasOverride = $phoneOverride !== null && trim($phoneOverride) !== '';
        $people = $hasOverride ? [$target] : $this->people->forTarget($target, $tenantId);

        if ($people === []) {
            $this->log->warning(
                'SendWhatsAppTemplate: no contacts on ' . $target->getEntityType() . '/' .
                $target->getId() . ', skipping.'
            );

            return;
        }

        $conn = $this->outbound->resolveConnection(
            $inboxId,
            $tenantId,
            JourneyWhatsAppOutbound::CHANNELS_TEMPLATE,
            $context->actor,
        );

        $mapping = $params['parameterMapping'] ?? [];
        // Exactly once per action, before iterating over people and phone numbers.
        $liveTemplate = $this->outbound->preflightTemplate($conn, $templateName, $language, $params);
        $category = $liveTemplate['category'];

        $headerUrl = isset($params['headerMediaUrl']) ? (string) $params['headerMediaUrl'] : null;
        $headerType = isset($params['headerMediaType']) ? (string) $params['headerMediaType'] : null;
        if ($headerUrl === '') {
            $headerUrl = null;
        }
        if ($headerType === '') {
            $headerType = null;
        }

        $templateBody = isset($params['templateBody']) ? (string) $params['templateBody'] : '';
        $journeyContext = [
            'journeyId' => $context->journey->getId(),
            'journeyRecordId' => $context->record->getId(),
        ];

        $errors = [];
        /** @var array<string, list<string>> $missing person name => empty parameters */
        $missing = [];
        /** @var array<string, string> $undelivered person name => reason, for the record's mural */
        $undelivered = [];
        $sent = 0;
        $attempted = 0;

        foreach ($people as $person) {
            $name = $this->outbound->displayName($person);

            if ($this->outbound->isOptedOut($person)) {
                $undelivered[$name] = 'saiu do WhatsApp (opt-out)';
                $this->log->warning(
                    'SendWhatsAppTemplate: opted out of WhatsApp, skipping ' .
                    $person->getEntityType() . '/' . $person->getId()
                );

                continue;
            }

            $phones = $this->outbound->resolvePhones($person, $phoneOverride);

            if ($phones === []) {
                $undelivered[$name] = 'sem celular';
                $this->log->info(
                    'SendWhatsAppTemplate: no phone on ' . $person->getEntityType() . '/' . $person->getId()
                );

                continue;
            }

            $templatePerson = $person === $target ? null : $person;

            try {
                $resolved = $this->outbound->resolveParameterMapping($mapping, $target, $templatePerson);
                $resolvedHeader = $this->outbound->resolveParameterMapping(
                    $params['headerParameterMapping'] ?? [],
                    $target,
                    $templatePerson,
                );
            } catch (Throwable $e) {
                $attempted += count($phones);
                $errors[] = $name . ': ' . $e->getMessage();
                $undelivered[$name] = 'erro ao montar a mensagem: ' . JourneyUndeliveredNotice::code($e->getMessage());

                continue;
            }

            // A Meta template can't go out with an empty parameter: leave this person out.
            $empty = [
                ...$this->emptyParameters($resolved, $mapping),
                ...$this->emptyParameters($resolvedHeader, $params['headerParameterMapping'] ?? []),
            ];

            if ($empty !== []) {
                $missing[$name] = $empty;
                $undelivered[$name] = 'sem valor em ' . implode(', ', array_map(
                    static fn (string $item) => JourneyUndeliveredNotice::code($item),
                    $empty,
                ));

                continue;
            }

            $attempted += count($phones);

            $content = $templateBody;
            foreach ($resolved as $num => $value) {
                $content = str_replace('{{' . $num . '}}', $value, $content);
            }

            $sentToPerson = 0;
            $personError = null;

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
                        $resolvedHeader,
                    );
                    $sent++;
                    $sentToPerson++;
                } catch (Throwable $e) {
                    $errors[] = $name . ' ' . $phone . ': ' . $e->getMessage();
                    $personError ??= $e->getMessage();
                    $this->log->error(
                        'SendWhatsAppTemplate failed for ' . $phone . ': ' . $e->getMessage()
                    );
                }
            }

            if ($sentToPerson === 0 && $personError !== null) {
                $undelivered[$name] = 'falha no envio: ' . JourneyUndeliveredNotice::code(mb_substr($personError, 0, 200));
            }
        }

        $this->notice->post(
            $context,
            'O WhatsApp ' . JourneyUndeliveredNotice::code($templateName),
            $undelivered,
            count($people),
        );

        if ($missing !== []) {
            $summary = JourneyTargetPeople::missingSummary($missing);

            // Nobody got it: fail so the journey record shows what is missing.
            if ($attempted === 0) {
                throw new Error($summary);
            }

            $this->log->warning('SendWhatsAppTemplate: skipped. ' . $summary);
        }

        if ($attempted === 0) {
            $this->log->warning('SendWhatsAppTemplate: no phone on any recipient, skipping.');

            return;
        }

        if ($sent === 0 && $errors !== []) {
            throw new Error('SendWhatsAppTemplate: all recipients failed. ' . implode('; ', $errors));
        }

        if ($errors !== []) {
            throw new Error(
                'SendWhatsAppTemplate: sent ' . $sent . '/' . $attempted .
                ' failed: ' . implode('; ', $errors)
            );
        }
    }

    /**
     * @param array<string, string> $resolved
     * @param mixed $mapping
     * @return list<string> e.g. `{{2}}={{customFields.x}}`
     */
    private function emptyParameters(array $resolved, mixed $mapping): array
    {
        $mapping = is_object($mapping) ? (array) $mapping : (is_array($mapping) ? $mapping : []);
        $out = [];

        foreach ($resolved as $num => $value) {
            try {
                TemplateParameters::assertResolved([$value], true);
            } catch (Throwable) {
                $out[] = '{{' . $num . '}}=' . (string) ($mapping[$num] ?? '');
            }
        }

        return $out;
    }
}
