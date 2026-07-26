<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\Mail\Account\GroupAccount\AccountFactory as GroupAccountFactory;
use Espo\Core\Mail\Account\PersonalAccount\AccountFactory as PersonalAccountFactory;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Mail\SenderParams;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Core\Utils\Log;
use Espo\Entities\Attachment;
use Espo\Entities\Email as EmailEntity;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyEmailToken;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\EntityManager;
use Espo\Tools\EmailTemplate\Data as EmailTemplateData;
use Espo\Tools\EmailTemplate\Params as EmailTemplateParams;
use Espo\Tools\EmailTemplate\Service as EmailTemplateService;
use Throwable;

class SendEmail implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private EmailSender $emailSender,
        private TenantGuard $tenantGuard,
        private EmailTemplateService $emailTemplateService,
        private RecordServiceContainer $recordServiceContainer,
        private GroupAccountFactory $groupAccountFactory,
        private PersonalAccountFactory $personalAccountFactory,
        private Log $log,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('SendEmail: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $params = $context->params;
        $to = isset($params['to']) ? (string) $params['to'] : null;
        $subject = (string) ($params['subject'] ?? '');
        $body = (string) ($params['body'] ?? '');
        $emailTemplateId = isset($params['emailTemplateId']) ? (string) $params['emailTemplateId'] : null;
        $isHtml = (bool) ($params['isHtml'] ?? true);
        $inboundEmailId = isset($params['inboundEmailId']) ? (string) $params['inboundEmailId'] : '';
        $emailAccountId = isset($params['emailAccountId']) ? (string) $params['emailAccountId'] : '';

        if ($inboundEmailId !== '' && $emailAccountId !== '') {
            throw new Error('SendEmail: choose either Group or Personal email account, not both.');
        }

        if ($inboundEmailId === '' && $emailAccountId === '') {
            throw new Error(
                'SendEmail: inboundEmailId or emailAccountId is required (system SMTP is not allowed).'
            );
        }

        /** @var list<Attachment> $attachmentList */
        $attachmentList = [];

        if (!$to) {
            $to = $context->target->get('emailAddress')
                ? (string) $context->target->get('emailAddress')
                : null;
        }

        if (!$to) {
            $this->log->warning('SendEmail action: no recipient on target');

            return;
        }

        $this->tenantGuard->assertEmailRecipientAllowed($to, $context->target);

        [$smtpParams, $senderParams, $fromAddress] = $this->resolveOutboundAccount(
            $inboundEmailId,
            $emailAccountId,
            $tenantId,
        );

        if ($emailTemplateId) {
            $this->tenantGuard->assertEmailTemplateInTenant($emailTemplateId, $tenantId);

            try {
                try {
                    $this->recordServiceContainer
                        ->get($context->target->getEntityType())
                        ->loadAdditionalFields($context->target);
                } catch (Throwable $e) {
                    $this->log->warning(
                        'SendEmail: loadAdditionalFields skipped: ' . $e->getMessage()
                    );
                }

                $data = EmailTemplateData::create()
                    ->withParent($context->target)
                    ->withParentId($context->target->getId())
                    ->withParentType($context->target->getEntityType())
                    ->withEmailAddress($to)
                    ->withEntityHash([
                        $context->target->getEntityType() => $context->target,
                    ]);

                $result = $this->emailTemplateService->process(
                    $emailTemplateId,
                    $data,
                    EmailTemplateParams::create()
                        ->withApplyAcl(false)
                        ->withCopyAttachments(true)
                );

                if ($result->getSubject() !== '') {
                    $subject = $result->getSubject();
                }
                if ($result->getBody() !== '') {
                    $body = $result->getBody();
                }
                $isHtml = $result->isHtml();
                $attachmentList = $result->getAttachmentList();
            } catch (Throwable $e) {
                $this->log->error('SendEmail: template process failed: ' . $e->getMessage());
                throw $e;
            }
        }

        if (!empty($params['attachmentsIds']) && is_array($params['attachmentsIds'])) {
            foreach ($params['attachmentsIds'] as $attId) {
                if (!is_string($attId) || $attId === '') {
                    continue;
                }
                $att = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attId);
                if ($att instanceof Attachment) {
                    $attachmentList[] = $att;
                }
            }
        }

        try {
            // Token stored on Email row; Message-ID uses From domain (normal MTA shape).
            // X-headers are optional debug — clients never echo them on reply.
            $mint = JourneyEmailToken::mint($fromAddress);

            $email = $this->entityManager->getNewEntity(EmailEntity::ENTITY_TYPE);
            $email->set([
                'to' => $to,
                'from' => $fromAddress,
                'subject' => $subject !== '' ? $subject : ('Journey: ' . ($context->journey->get('name') ?: '')),
                'body' => $body,
                'isHtml' => $isHtml,
                'status' => EmailEntity::STATUS_SENDING,
                'parentType' => $context->target->getEntityType(),
                'parentId' => $context->target->getId(),
                'teamsIds' => $this->tenantGuard->getJourneyTeamsIds($context->journey),
                'messageId' => $mint['messageId'],
                'journeyToken' => $mint['token'],
                'journeyRecordId' => $context->record->getId(),
                'journeyId' => $context->journey->getId(),
            ]);

            if ($attachmentList !== []) {
                $ids = [];
                foreach ($attachmentList as $att) {
                    $ids[] = $att->getId();
                }
                $email->set('attachmentsIds', $ids);
            }

            $sender = $this->emailSender->create()
                ->withSmtpParams($smtpParams)
                ->withParams($senderParams);

            if ($attachmentList !== []) {
                $sender = $sender->withAttachments($attachmentList);
            }

            $sender->send($email);

            // Always persist so inbound In-Reply-To → repliedId → token lookup works
            // even when the SMTP account has "store sent" off.
            $this->entityManager->saveEntity($email, [
                EmailEntity::SAVE_OPTION_IS_JUST_SENT => true,
                SaveOption::SILENT => true,
            ]);
        } catch (Throwable $e) {
            $this->log->error('SendEmail action failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array{0: \Espo\Core\Mail\SmtpParams, 1: SenderParams, 2: string}
     */
    private function resolveOutboundAccount(
        string $inboundEmailId,
        string $emailAccountId,
        string $tenantId,
    ): array {
        $senderParams = SenderParams::create();

        if ($emailAccountId !== '') {
            $this->tenantGuard->assertEmailAccountAllowedForSending($emailAccountId, $tenantId);

            $account = $this->personalAccountFactory->create($emailAccountId);
            $smtpParams = $account->getSmtpParams();

            if (!$account->isAvailableForSending() || !$smtpParams) {
                throw new Error("SendEmail: personal account {$emailAccountId} is not available for SMTP.");
            }

            $entity = $account->getEntity();
            $from = (string) ($entity->getEmailAddress() ?? '');

            if ($from === '') {
                throw new Error("SendEmail: personal account {$emailAccountId} has no emailAddress.");
            }

            $senderParams = $senderParams->withFromAddress($from);
            $fromName = $entity->get('fromName');
            if (is_string($fromName) && $fromName !== '') {
                $senderParams = $senderParams->withFromName($fromName);
            }

            return [$smtpParams, $senderParams, $from];
        }

        $this->tenantGuard->assertInboundEmailAllowedForSending($inboundEmailId, $tenantId);

        $account = $this->groupAccountFactory->create($inboundEmailId);
        $smtpParams = $account->getSmtpParams();

        if (!$account->isAvailableForSending() || !$smtpParams) {
            throw new Error("SendEmail: group account {$inboundEmailId} is not available for SMTP.");
        }

        $entity = $account->getEntity();
        $from = (string) ($entity->getEmailAddress() ?? '');

        if ($from === '') {
            throw new Error("SendEmail: group account {$inboundEmailId} has no emailAddress.");
        }

        $senderParams = $senderParams->withFromAddress($from);
        $fromName = $entity->get('fromName');
        if (is_string($fromName) && $fromName !== '') {
            $senderParams = $senderParams->withFromName($fromName);
        }

        $replyTo = $entity->get('replyToAddress');
        if (is_string($replyTo) && $replyTo !== '') {
            $senderParams = $senderParams->withReplyToAddress($replyTo);
        }

        return [$smtpParams, $senderParams, $from];
    }
}
