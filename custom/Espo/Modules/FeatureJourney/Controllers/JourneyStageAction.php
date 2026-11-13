<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Core\Templates\Controllers\Base;
use Espo\Modules\FeatureJourney\Services\JourneyTargetPeople;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\Tools\EmailTemplate\Params as EmailTemplateParams;
use Espo\Tools\EmailTemplate\Service as EmailTemplateService;
use stdClass;
use Throwable;

/**
 * Standard CRUD for JourneyStageAction + dry-run template renders (no send).
 * Required so ClassFinder maps GET/POST /JourneyStageAction (no custom controller ⇒ 404).
 */
class JourneyStageAction extends Base implements \Espo\Core\Di\EntityManagerAware
{
    use \Espo\Core\Di\EntityManagerSetter;

    /**
     * Resolve Handlebars parameter mapping + body placeholders against a target
     * record without sending WhatsApp.
     *
     * POST /api/v1/JourneyStageAction/action/renderWhatsAppTemplate
     */
    public function postActionRenderWhatsAppTemplate(Request $request, Response $response): stdClass
    {
        if (!$this->acl->checkScope('JourneyStageAction', 'read')) {
            throw new Forbidden('No access to JourneyStageAction.');
        }

        $data = $request->getParsedBody() ?? (object) [];
        $target = $this->loadReadableTarget($data);

        $templateBody = isset($data->templateBody) ? (string) $data->templateBody : '';
        $mapping = $data->parameterMapping ?? [];

        if ($mapping instanceof stdClass) {
            $mapping = (array) $mapping;
        }

        /** @var JourneyWhatsAppOutbound $outbound */
        $outbound = $this->injectableFactory->create(JourneyWhatsAppOutbound::class);

        $previews = [];
        $firstError = null;

        foreach ($this->previewPeople($target) as $person) {
            try {
                $previews[] = $this->renderWhatsAppFor($outbound, $data, $mapping, $templateBody, $target, $person);
            } catch (Throwable $e) {
                $firstError ??= $e;
                $previews[] = $this->personRef($outbound, $person) + ['error' => $e->getMessage()];
            }
        }

        $ok = array_values(array_filter($previews, static fn (array $p) => !isset($p['error'])));

        if ($ok === []) {
            throw $this->previewError($firstError?->getMessage() ?? 'Nada para renderizar.');
        }

        $first = $ok[0];

        $headerUrl = isset($data->headerMediaUrl) ? trim((string) $data->headerMediaUrl) : '';
        $headerType = isset($data->headerMediaType) ? trim((string) $data->headerMediaType) : '';

        return (object) [
            'templateName' => isset($data->templateName) ? (string) $data->templateName : null,
            'templateLanguage' => isset($data->templateLanguage)
                ? (string) $data->templateLanguage
                : null,
            'templateCategory' => isset($data->templateCategory)
                ? (string) $data->templateCategory
                : null,
            'templateBody' => $templateBody,
            'resolvedParams' => $first['resolvedParams'],
            'resolvedHeaderParams' => $first['resolvedHeaderParams'],
            'renderedHeader' => $first['renderedHeader'],
            'renderedBody' => $first['renderedBody'],
            'people' => $previews,
            'headerMediaUrl' => $headerUrl !== '' ? $headerUrl : null,
            'headerMediaType' => $headerType !== '' ? $headerType : null,
            'targetEntityType' => $target->getEntityType(),
            'targetId' => $target->getId(),
            'targetName' => $outbound->displayName($target),
            'sent' => false,
        ];
    }

    /**
     * Apply EmailTemplate against a target record without sending email.
     *
     * POST /api/v1/JourneyStageAction/action/renderEmailTemplate
     *
     * Body: { emailTemplateId, targetEntityType?, targetId, emailAddress? }
     */
    public function postActionRenderEmailTemplate(Request $request, Response $response): stdClass
    {
        if (!$this->acl->checkScope('JourneyStageAction', 'read')) {
            throw new Forbidden('No access to JourneyStageAction.');
        }

        $data = $request->getParsedBody() ?? (object) [];

        $emailTemplateId = isset($data->emailTemplateId)
            ? trim((string) $data->emailTemplateId)
            : '';

        if ($emailTemplateId === '') {
            throw new BadRequest('emailTemplateId is required.');
        }

        if (!$this->acl->checkScope('EmailTemplate', 'read')) {
            throw new Forbidden('No read access to EmailTemplate.');
        }

        $template = $this->entityManager->getEntityById('EmailTemplate', $emailTemplateId);

        if (!$template) {
            throw new NotFound("EmailTemplate {$emailTemplateId} not found.");
        }

        if (!$this->acl->check($template, 'read')) {
            throw new Forbidden("No read access to EmailTemplate {$emailTemplateId}.");
        }

        $target = $this->loadReadableTarget($data);

        try {
            /** @var RecordServiceContainer $recordServices */
            $recordServices = $this->injectableFactory->create(RecordServiceContainer::class);
            $recordServices->get($target->getEntityType())->loadAdditionalFields($target);
        } catch (Throwable) {
            // best-effort; placeholders still work on loaded attributes
        }

        $emailAddress = isset($data->emailAddress) ? trim((string) $data->emailAddress) : '';

        if ($emailAddress === '') {
            $raw = $target->get('emailAddress');
            $emailAddress = is_string($raw) ? trim($raw) : '';
        }

        /** @var EmailTemplateService $service */
        $service = $this->injectableFactory->create(EmailTemplateService::class);
        /** @var JourneyWhatsAppOutbound $outbound */
        $outbound = $this->injectableFactory->create(JourneyWhatsAppOutbound::class);

        $previews = [];
        $firstError = null;

        foreach ($this->previewPeople($target) as $person) {
            $address = $person === $target ? $emailAddress : '';

            try {
                $map = $service->process(
                    $emailTemplateId,
                    JourneyTargetPeople::emailTemplateData($target, $person, $address !== '' ? $address : null),
                    EmailTemplateParams::create()
                        ->withApplyAcl(true)
                        ->withCopyAttachments(false)
                )->getValueMap();
            } catch (Throwable $e) {
                $firstError ??= $e;
                $previews[] = $this->personRef($outbound, $person) + ['error' => $e->getMessage()];

                continue;
            }

            $previews[] = $this->personRef($outbound, $person) + [
                'unresolved' => $this->unresolvedPlaceholders(($map->subject ?? '') . ' ' . ($map->body ?? '')),
                'subject' => $map->subject ?? null,
                'body' => $map->body ?? null,
                'isHtml' => (bool) ($map->isHtml ?? true),
                'attachmentsIds' => $map->attachmentsIds ?? [],
                'attachmentsNames' => $map->attachmentsNames ?? (object) [],
            ];
        }

        $ok = array_values(array_filter($previews, static fn (array $p) => !isset($p['error'])));

        if ($ok === []) {
            throw $this->previewError($firstError?->getMessage() ?? 'Nada para renderizar.');
        }

        $first = $ok[0];

        return (object) [
            'emailTemplateId' => $emailTemplateId,
            'emailTemplateName' => (string) ($template->get('name') ?? ''),
            'subject' => $first['subject'],
            'body' => $first['body'],
            'isHtml' => $first['isHtml'],
            'attachmentsIds' => $first['attachmentsIds'],
            'attachmentsNames' => $first['attachmentsNames'],
            'unresolved' => $first['unresolved'],
            'people' => $previews,
            'targetEntityType' => $target->getEntityType(),
            'targetId' => $target->getId(),
            'targetName' => (string) ($target->get('name') ?? ''),
            'sent' => false,
        ];
    }

    /**
     * Who a preview renders for: the target, or the readable contacts in an
     * Account's Contatos (the same people the action sends to).
     *
     * @return list<\Espo\ORM\Entity>
     */
    private function previewPeople(\Espo\ORM\Entity $target): array
    {
        /** @var JourneyTargetPeople $people */
        $people = $this->injectableFactory->create(JourneyTargetPeople::class);

        if (!$people->fansOut($target)) {
            return [$target];
        }

        /** @var TenantGuard $tenantGuard */
        $tenantGuard = $this->injectableFactory->create(TenantGuard::class);
        $list = array_values(array_filter(
            $people->forTarget($target, $tenantGuard->requireTenantId($target, 'target')),
            fn (\Espo\ORM\Entity $person) => $this->acl->check($person, 'read')
        ));

        if ($list === []) {
            throw new BadRequest('This ' . $target->getEntityType() . ' has no contacts to send to.');
        }

        return $list;
    }

    /**
     * A 400 whose message the preview shows as is (JSON body; the status-reason
     * header can't carry accents).
     */
    private function previewError(string $message): BadRequest
    {
        return BadRequest::createWithBody(
            $message,
            (string) json_encode(['message' => $message], JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * @param array<string, string> $resolved
     * @param array<string, mixed> $mapping
     * @return list<string> e.g. `{{2}} = {{customFields.x}}`
     */
    private function emptyParameters(array $resolved, array $mapping, string $prefix): array
    {
        $out = [];

        foreach ($resolved as $num => $value) {
            if (trim($value) === '' || str_contains($value, '{{') || str_contains($value, '}}')) {
                $out[] = $prefix . '{{' . $num . '}} = ' . (string) ($mapping[$num] ?? '');
            }
        }

        return $out;
    }

    /**
     * Email placeholders the template left as text, e.g. `{Account.customFields.x}`
     * when the record has no value or no such related record.
     *
     * @return list<string>
     */
    private function unresolvedPlaceholders(string $text): array
    {
        preg_match_all('/\{[A-Z][A-Za-z]*\.[A-Za-z0-9_.]+\}/', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @return array{personType: string, personId: string, personName: string}
     */
    private function personRef(JourneyWhatsAppOutbound $outbound, \Espo\ORM\Entity $person): array
    {
        return [
            'personType' => $person->getEntityType(),
            'personId' => (string) $person->getId(),
            'personName' => $outbound->displayName($person),
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function renderWhatsAppFor(
        JourneyWhatsAppOutbound $outbound,
        object $data,
        array $mapping,
        string $templateBody,
        \Espo\ORM\Entity $target,
        \Espo\ORM\Entity $person,
    ): array {
        $templatePerson = $person === $target ? null : $person;
        $resolved = $outbound->resolveParameterMapping($mapping, $target, $templatePerson);
        $resolvedHeader = $outbound->resolveParameterMapping(
            $data->headerParameterMapping ?? [],
            $target,
            $templatePerson,
        );
        $headerMapping = $data->headerParameterMapping ?? [];
        $headerMapping = $headerMapping instanceof stdClass ? (array) $headerMapping : (array) $headerMapping;
        $empty = [
            ...$this->emptyParameters($resolved, $mapping, ''),
            ...$this->emptyParameters($resolvedHeader, $headerMapping, 'cabeçalho '),
        ];

        if ($empty !== []) {
            throw $this->previewError(
                'Sem valor para ' . $outbound->displayName($person) . ': ' . implode('; ', $empty) . '.'
            );
        }

        $renderedHeader = (string) ($data->templateHeader ?? '');
        foreach ($resolvedHeader as $num => $value) {
            $renderedHeader = str_replace('{{' . $num . '}}', $value, $renderedHeader);
        }

        $renderedBody = $templateBody;
        foreach ($resolved as $num => $value) {
            $renderedBody = str_replace('{{' . $num . '}}', $value, $renderedBody);
        }

        preg_match_all('/\{\{\s*\d+\s*\}\}/', $renderedHeader . ' ' . $renderedBody, $left);

        if ($left[0] !== []) {
            throw $this->previewError(
                'O template usa ' . implode(', ', array_unique($left[0])) .
                ' sem mapeamento. Adicione esses parâmetros.'
            );
        }

        return $this->personRef($outbound, $person) + [
            'resolvedParams' => (object) $resolved,
            'resolvedHeaderParams' => (object) $resolvedHeader,
            'renderedHeader' => $renderedHeader,
            'renderedBody' => $renderedBody,
        ];
    }

    /**
     * @param object $data
     */
    private function loadReadableTarget(object $data): \Espo\ORM\Entity
    {
        $targetId = isset($data->targetId) ? trim((string) $data->targetId) : '';
        $targetEntityType = isset($data->targetEntityType)
            ? trim((string) $data->targetEntityType)
            : 'Contact';

        if ($targetId === '') {
            throw new BadRequest('targetId is required.');
        }

        if ($targetEntityType === '') {
            $targetEntityType = 'Contact';
        }

        if (!$this->acl->checkScope($targetEntityType, 'read')) {
            throw new Forbidden("No read access to {$targetEntityType}.");
        }

        $target = $this->entityManager->getEntityById($targetEntityType, $targetId);

        if (!$target) {
            throw new NotFound("{$targetEntityType} {$targetId} not found.");
        }

        if (!$this->acl->check($target, 'read')) {
            throw new Forbidden("No read access to {$targetEntityType} {$targetId}.");
        }

        $tenantId = isset($data->tenantId) ? trim((string) $data->tenantId) : '';

        if ($tenantId !== '') {
            /** @var TenantGuard $tenantGuard */
            $tenantGuard = $this->injectableFactory->create(TenantGuard::class);

            if (!$tenantGuard->entityBelongsToTenant($target, $tenantId)) {
                throw $this->previewError(
                    'Este registro é de outro ambiente. Escolha um registro do ambiente da jornada.'
                );
            }
        }

        return $target;
    }
}
