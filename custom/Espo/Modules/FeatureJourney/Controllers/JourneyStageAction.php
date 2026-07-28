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
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Tools\EmailTemplate\Data as EmailTemplateData;
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
        $resolved = $outbound->resolveParameterMapping($mapping, $target);

        $renderedBody = $templateBody;

        if ($renderedBody !== '' && $resolved !== []) {
            foreach ($resolved as $num => $value) {
                $renderedBody = str_replace('{{' . $num . '}}', $value, $renderedBody);
            }
        }

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
            'resolvedParams' => (object) $resolved,
            'renderedBody' => $renderedBody,
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

        $templateData = EmailTemplateData::create()
            ->withParent($target)
            ->withParentId($target->getId())
            ->withParentType($target->getEntityType())
            ->withEntityHash([
                $target->getEntityType() => $target,
            ]);

        if ($emailAddress !== '') {
            $templateData = $templateData->withEmailAddress($emailAddress);
        }

        /** @var EmailTemplateService $service */
        $service = $this->injectableFactory->create(EmailTemplateService::class);

        $result = $service->process(
            $emailTemplateId,
            $templateData,
            EmailTemplateParams::create()
                ->withApplyAcl(true)
                ->withCopyAttachments(false)
        );

        $map = $result->getValueMap();

        return (object) [
            'emailTemplateId' => $emailTemplateId,
            'emailTemplateName' => (string) ($template->get('name') ?? ''),
            'subject' => $map->subject ?? null,
            'body' => $map->body ?? null,
            'isHtml' => (bool) ($map->isHtml ?? true),
            'attachmentsIds' => $map->attachmentsIds ?? [],
            'attachmentsNames' => $map->attachmentsNames ?? (object) [],
            'targetEntityType' => $target->getEntityType(),
            'targetId' => $target->getId(),
            'targetName' => (string) ($target->get('name') ?? ''),
            'sent' => false,
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

        return $target;
    }
}
