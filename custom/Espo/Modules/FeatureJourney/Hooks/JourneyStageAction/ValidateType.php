<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\JourneyStageAction;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyStage;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Entity> */
class ValidateType implements BeforeSave
{
    public static int $order = 15;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private User $user,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $stageId = $entity->get('stageId');
        if ($stageId) {
            $stage = $this->entityManager->getEntityById(JourneyStage::ENTITY_TYPE, (string) $stageId);
            if ($stage) {
                $journey = $this->entityManager->getEntityById(
                    Journey::ENTITY_TYPE,
                    (string) $stage->get('journeyId')
                );
                if ($journey) {
                    $status = $journey->get('status');
                    if (!in_array($status, [Journey::STATUS_DRAFT, Journey::STATUS_PAUSED], true)) {
                        throw new BadRequest("Cannot modify actions while journey status is {$status}.");
                    }
                }
            }
        }

        $type = $entity->get('type');
        if (!$type) {
            throw new BadRequest('type is required.');
        }

        $meta = $this->metadata->get(['app', 'journeyActionTypes', 'types', $type]);
        if (!is_array($meta)) {
            throw new BadRequest("Unknown action type '{$type}'.");
        }

        $className = $meta['implementationClassName'] ?? null;
        if (!$className || !class_exists((string) $className)) {
            throw new BadRequest("Action type '{$type}' is not available (implementation missing).");
        }

        $tier = $meta['tier'] ?? 'tenant';
        if ($tier === 'platform' && !$this->user->isAdmin()) {
            throw new Forbidden("Action type '{$type}' is platform-tier (superadmin only).");
        }

        $params = $entity->get('params');
        if ($params instanceof \stdClass) {
            $params = (array) $params;
        }
        if (!is_array($params)) {
            $params = [];
        }

        if ($type === 'runScript') {
            $className = (string) ($params['className'] ?? '');
            if ($className === '') {
                throw new BadRequest('runScript requires params.className.');
            }
            if (!class_exists($className)) {
                throw new BadRequest("runScript class '{$className}' not found.");
            }
            /** @var list<string>|null $allow */
            $allow = $this->metadata->get(['app', 'journeyPlatformAllowList', 'scriptClassNameList']);
            if (!is_array($allow) || !in_array($className, $allow, true)) {
                throw new BadRequest(
                    "runScript class '{$className}' is not in app.journeyPlatformAllowList.scriptClassNameList."
                );
            }
        }

        if ($type === 'sendEmail') {
            $inboundEmailId = (string) ($params['inboundEmailId'] ?? '');
            $emailAccountId = (string) ($params['emailAccountId'] ?? '');

            if ($inboundEmailId !== '' && $emailAccountId !== '') {
                throw new BadRequest(
                    'sendEmail: choose either Group Email Account or Personal Email Account, not both.'
                );
            }

            if ($inboundEmailId === '' && $emailAccountId === '') {
                throw new BadRequest(
                    'sendEmail requires a Group Email Account (inboundEmailId) or Personal Email Account ' .
                    '(emailAccountId). System SMTP is not allowed.'
                );
            }
        }

        if ($type === 'sendWhatsAppMessage') {
            $inboxId = (string) ($params['chatwootInboxId'] ?? '');
            $body = trim((string) ($params['body'] ?? ''));
            $bodyFormula = $this->paramFormulaScript($params, 'body');

            if ($inboxId === '') {
                throw new BadRequest('sendWhatsAppMessage requires chatwootInboxId (WhatsApp Inbox).');
            }

            if ($body === '' && $bodyFormula === '') {
                throw new BadRequest(
                    'sendWhatsAppMessage requires body (static text or dynamic/fx formula).'
                );
            }

            $this->assertWhatsAppInboxChannel(
                $inboxId,
                ['whatsappQrcode', 'whatsappCloudApi', 'whatsappCoexistence'],
                'sendWhatsAppMessage'
            );
        }

        if ($type === 'sendWhatsAppTemplate') {
            $inboxId = (string) ($params['chatwootInboxId'] ?? '');
            $templateName = trim((string) ($params['templateName'] ?? ''));

            if ($inboxId === '') {
                throw new BadRequest('sendWhatsAppTemplate requires chatwootInboxId (Cloud/Coexistence inbox).');
            }

            if ($templateName === '') {
                throw new BadRequest('sendWhatsAppTemplate requires templateName.');
            }

            $this->assertWhatsAppInboxChannel(
                $inboxId,
                ['whatsappCloudApi', 'whatsappCoexistence'],
                'sendWhatsAppTemplate'
            );
        }
    }

    /**
     * @param list<string> $allowed
     */
    private function assertWhatsAppInboxChannel(string $inboxId, array $allowed, string $action): void
    {
        $inbox = $this->entityManager->getEntityById('ChatwootInbox', $inboxId);
        if (!$inbox) {
            throw new BadRequest("{$action}: Chatwoot inbox not found.");
        }

        $channelType = (string) ($inbox->get('channelType') ?? '');
        if ($channelType === '' || !in_array($channelType, $allowed, true)) {
            throw new BadRequest(
                "{$action}: inbox channelType '{$channelType}' is not allowed " .
                '(expected: ' . implode(', ', $allowed) . ').'
            );
        }
    }

    /**
     * Non-empty formula script from params.paramFormulas for a field key.
     *
     * @param array<string, mixed> $params
     */
    private function paramFormulaScript(array $params, string $key): string
    {
        $formulas = $params['paramFormulas'] ?? null;
        if ($formulas instanceof \stdClass) {
            $formulas = (array) $formulas;
        }
        if (!is_array($formulas)) {
            return '';
        }

        $script = $formulas[$key] ?? null;

        return is_string($script) ? trim($script) : '';
    }
}
