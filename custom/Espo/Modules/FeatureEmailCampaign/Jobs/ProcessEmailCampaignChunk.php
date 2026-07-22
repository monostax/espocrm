<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureEmailCampaign\Jobs;

use Espo\Core\Exceptions\Error;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Mail\Account\GroupAccount\AccountFactory as GroupAccountFactory;
use Espo\Core\Mail\Account\PersonalAccount\AccountFactory as PersonalAccountFactory;
use Espo\Core\Mail\ConfigDataProvider;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Mail\SenderParams;
use Espo\Core\Mail\SmtpParams;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\Email;
use Espo\Entities\EmailTemplate;
use Espo\Modules\FeatureEmailCampaign\Services\EmailCampaignOpportunityService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\EmailTemplate\Data as TemplateData;
use Espo\Tools\EmailTemplate\Params as TemplateParams;
use Espo\Tools\EmailTemplate\Processor as TemplateProcessor;
use Exception;

/**
 * Send a chunk of EmailCampaignContact rows via SMTP + EmailTemplate.
 */
class ProcessEmailCampaignChunk implements Job
{
    private const RATE_LIMIT_DELAY_MS = 200;
    private const MAX_RETRIES = 3;
    private const RETRY_BACKOFF_SECONDS = 2;

    public function __construct(
        private EntityManager $entityManager,
        private EmailSender $emailSender,
        private TemplateProcessor $templateProcessor,
        private GroupAccountFactory $groupAccountFactory,
        private PersonalAccountFactory $personalAccountFactory,
        private ConfigDataProvider $configDataProvider,
        private Config $config,
        private EmailCampaignOpportunityService $opportunityService,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $campaignId = $data->get('campaignId');
        $campaignContactIds = $data->get('campaignContactIds');

        if (!is_array($campaignContactIds)) {
            $campaignContactIds = [];
        }

        if (!$campaignId) {
            return;
        }

        $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

        if (!$campaign) {
            $this->log->warning("ProcessEmailCampaignChunk: Campaign {$campaignId} not found.");
            return;
        }

        if ($campaign->get('status') === 'Cancelled') {
            return;
        }

        $templateId = $campaign->get('emailTemplateId');
        $emailTemplate = $templateId
            ? $this->entityManager->getEntityById(EmailTemplate::ENTITY_TYPE, $templateId)
            : null;

        if (!$emailTemplate) {
            $this->log->error("ProcessEmailCampaignChunk: Email template missing for campaign {$campaignId}.");
            return;
        }

        try {
            [$smtpParams, $senderParams] = $this->getSenderParams($campaign);
        } catch (Exception $e) {
            $this->log->error("ProcessEmailCampaignChunk: SMTP setup failed for {$campaignId}: {$e->getMessage()}");
            $this->failChunkForSetupError($campaignId, $campaignContactIds, $e->getMessage());
            $this->checkCampaignCompletion($campaignId);
            return;
        }

        $ctx = [
            'campaignId' => $campaignId,
            'campaign' => $campaign,
            'emailTemplate' => $emailTemplate,
            'smtpParams' => $smtpParams,
            'senderParams' => $senderParams,
            'createOpportunity' => (bool) $campaign->get('createOpportunity'),
            'storeSentEmails' => (bool) $campaign->get('storeSentEmails'),
        ];

        $contacts = $this->findChunkContacts($campaignId, 'Pending', $campaignContactIds);
        $this->processContacts($contacts, $ctx);

        for ($retryPass = 1; $retryPass <= self::MAX_RETRIES; $retryPass++) {
            $retryContacts = $this->findChunkContacts($campaignId, 'Retry', $campaignContactIds);
            $hasRetry = false;

            foreach ($retryContacts as $_) {
                $hasRetry = true;
                break;
            }

            if (!$hasRetry) {
                break;
            }

            sleep(self::RETRY_BACKOFF_SECONDS);
            $this->processContacts(
                $this->findChunkContacts($campaignId, 'Retry', $campaignContactIds),
                $ctx
            );
        }

        $this->finalizeRemainingRetries($campaignId, $campaignContactIds);
        $this->checkCampaignCompletion($campaignId);
    }

    /**
     * @param string[] $campaignContactIds
     * @return iterable<Entity>
     */
    private function findChunkContacts(string $campaignId, string $status, array $campaignContactIds): iterable
    {
        $where = [
            'emailCampaignId' => $campaignId,
            'status' => $status,
        ];

        if ($campaignContactIds !== []) {
            $where['id'] = $campaignContactIds;
        }

        return $this->entityManager
            ->getRDBRepository('EmailCampaignContact')
            ->where($where)
            ->order('createdAt')
            ->find();
    }

    /**
     * @param iterable<Entity> $contacts
     * @param array<string, mixed> $ctx
     */
    private function processContacts(iterable $contacts, array $ctx): void
    {
        $campaignId = $ctx['campaignId'];
        $processedCount = 0;

        foreach ($contacts as $campaignContact) {
            if ($processedCount > 0 && $processedCount % 10 === 0) {
                $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

                if ($campaign && $campaign->get('status') === 'Cancelled') {
                    return;
                }
            }

            if (!$this->claimContact($campaignContact)) {
                continue;
            }

            try {
                if ($processedCount > 0) {
                    usleep(self::RATE_LIMIT_DELAY_MS * 1000);
                }

                $this->sendToContact($campaignContact, $ctx);
            } catch (Exception $e) {
                $this->handleSendFailure($campaignContact, $campaignId, $e);
            }

            if ($campaignContact->get('status') === 'Sent') {
                $this->incrementCampaignCounter($campaignId, 'sentCount');

                if ($ctx['createOpportunity']) {
                    $this->attributeOpportunity($campaignContact);
                }
            }

            $processedCount++;
        }
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function sendToContact(Entity $campaignContact, array $ctx): void
    {
        $contactId = $campaignContact->get('contactId');
        $emailAddress = $campaignContact->get('emailAddress');

        if (!$contactId || !$emailAddress) {
            throw new Error('Recipient missing contact or email address.');
        }

        $contact = $this->entityManager->getEntityById('Contact', $contactId);

        if (!$contact) {
            throw new Error("Contact {$contactId} not found.");
        }

        /** @var EmailTemplate $emailTemplate */
        $emailTemplate = $ctx['emailTemplate'];
        /** @var Entity $campaign */
        $campaign = $ctx['campaign'];

        $emailData = $this->templateProcessor->process(
            $emailTemplate,
            TemplateParams::create()
                ->withApplyAcl(false)
                ->withCopyAttachments(false),
            TemplateData::create()->withParent($contact)
        );

        $email = $this->entityManager->getRDBRepositoryByClass(Email::class)->getNew();
        $email
            ->addToAddress($emailAddress)
            ->setSubject($emailData->getSubject())
            ->setBody($emailData->getBody())
            ->setIsHtml($emailData->isHtml())
            ->setAttachmentIdList($emailData->getAttachmentIdList());

        if ($campaign->get('fromAddress')) {
            $email->setFromAddress($campaign->get('fromAddress'));
        }

        if ($campaign->get('replyToAddress')) {
            $email->addReplyToAddress($campaign->get('replyToAddress'));
        }

        /** @var SenderParams $senderParams */
        $senderParams = $ctx['senderParams'];
        $senderParams = $senderParams->withFromAddress(
            $campaign->get('fromAddress') ?: $this->configDataProvider->getSystemOutboundAddress()
        );

        if ($campaign->get('fromName')) {
            $senderParams = $senderParams->withFromName($campaign->get('fromName'));
        }

        if ($campaign->get('replyToName')) {
            $senderParams = $senderParams->withReplyToName($campaign->get('replyToName'));
        }

        $sender = $this->emailSender->create();

        if ($ctx['smtpParams'] instanceof SmtpParams) {
            $sender->withSmtpParams($ctx['smtpParams']);
        }

        $attachmentList = $emailTemplate->getAttachments();

        $sender
            ->withParams($senderParams)
            ->withAttachments($attachmentList)
            ->send($email);

        if ($ctx['storeSentEmails']) {
            $email->set('status', Email::STATUS_SENT);
            $email->set('dateSent', date('Y-m-d H:i:s'));
            $email->set('parentType', 'Contact');
            $email->set('parentId', $contactId);
            $this->entityManager->saveEntity($email);
        }

        $campaignContact->set([
            'status' => 'Sent',
            'sentAt' => date('Y-m-d H:i:s'),
            'emailId' => $email->hasId() ? $email->getId() : null,
            'failedReason' => null,
        ]);
        $this->entityManager->saveEntity($campaignContact);
    }

    private function handleSendFailure(Entity $campaignContact, string $campaignId, Exception $e): void
    {
        $errorMessage = $e->getMessage();
        $this->log->error(
            "ProcessEmailCampaignChunk: Failed recipient {$campaignContact->getId()}: {$errorMessage}"
        );

        $currentRetries = (int) $campaignContact->get('retryCount');

        if ($this->isTransientFailure($errorMessage) && $currentRetries < self::MAX_RETRIES) {
            $campaignContact->set([
                'status' => 'Retry',
                'retryCount' => $currentRetries + 1,
                'failedReason' => substr($errorMessage, 0, 5000),
            ]);
            $this->entityManager->saveEntity($campaignContact);

            return;
        }

        $campaignContact->set([
            'status' => 'Failed',
            'failedAt' => date('Y-m-d H:i:s'),
            'failedReason' => substr($errorMessage, 0, 5000),
            'opportunityAttributionStatus' => 'NotRequested',
        ]);
        $this->entityManager->saveEntity($campaignContact);
        $this->incrementCampaignCounter($campaignId, 'failedCount');
    }

    private function claimContact(Entity $campaignContact): bool
    {
        $expectedStatus = $campaignContact->get('status');

        if (!in_array($expectedStatus, ['Pending', 'Retry'], true)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        $updateQuery = $this->entityManager
            ->getQueryBuilder()
            ->update()
            ->in('EmailCampaignContact')
            ->set([
                'status' => 'Processing',
                'claimedAt' => $now,
            ])
            ->where([
                'id' => $campaignContact->getId(),
                'status' => $expectedStatus,
            ])
            ->build();

        $sth = $this->entityManager->getQueryExecutor()->execute($updateQuery);

        if ($sth->rowCount() === 0) {
            return false;
        }

        $campaignContact->set([
            'status' => 'Processing',
            'claimedAt' => $now,
        ]);

        return true;
    }

    private function attributeOpportunity(Entity $campaignContact): void
    {
        try {
            $this->opportunityService->attributeSuccessfulSend($campaignContact);
        } catch (\Throwable $e) {
            $this->log->error(sprintf(
                'ProcessEmailCampaignChunk: Opportunity attribution failed for recipient=%s: %s',
                $campaignContact->getId(),
                $e->getMessage(),
            ));

            try {
                $fresh = $this->entityManager->getEntityById(
                    'EmailCampaignContact',
                    $campaignContact->getId()
                );

                if ($fresh) {
                    $fresh->set([
                        'opportunityAttributionStatus' => 'Failed',
                        'opportunityAttributionError' => substr($e->getMessage(), 0, 5000),
                    ]);
                    $this->entityManager->saveEntity($fresh);
                }
            } catch (\Throwable $persistenceError) {
                $this->log->error($persistenceError->getMessage());
            }
        }
    }

    /**
     * @param string[] $campaignContactIds
     */
    private function failChunkForSetupError(
        string $campaignId,
        array $campaignContactIds,
        string $errorMessage
    ): void {
        $reason = substr($errorMessage, 0, 5000);

        foreach ($this->findChunkContacts($campaignId, 'Pending', $campaignContactIds) as $campaignContact) {
            $campaignContact->set([
                'status' => 'Failed',
                'failedAt' => date('Y-m-d H:i:s'),
                'failedReason' => $reason,
                'opportunityAttributionStatus' => 'NotRequested',
            ]);
            $this->entityManager->saveEntity($campaignContact);
            $this->incrementCampaignCounter($campaignId, 'failedCount');
        }

        foreach ($this->findChunkContacts($campaignId, 'Retry', $campaignContactIds) as $campaignContact) {
            $campaignContact->set([
                'status' => 'Failed',
                'failedAt' => date('Y-m-d H:i:s'),
                'failedReason' => $reason,
                'opportunityAttributionStatus' => 'NotRequested',
            ]);
            $this->entityManager->saveEntity($campaignContact);
            $this->incrementCampaignCounter($campaignId, 'failedCount');
        }
    }

    /**
     * @param string[] $campaignContactIds
     */
    private function finalizeRemainingRetries(string $campaignId, array $campaignContactIds): void
    {
        foreach ($this->findChunkContacts($campaignId, 'Retry', $campaignContactIds) as $campaignContact) {
            $campaignContact->set([
                'status' => 'Failed',
                'failedAt' => date('Y-m-d H:i:s'),
                'failedReason' => $campaignContact->get('failedReason') ?: 'Max retries exhausted',
                'opportunityAttributionStatus' => 'NotRequested',
            ]);
            $this->entityManager->saveEntity($campaignContact);
            $this->incrementCampaignCounter($campaignId, 'failedCount');
        }
    }

    private function incrementCampaignCounter(string $campaignId, string $field): void
    {
        $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

        if ($campaign) {
            $campaign->set($field, (int) $campaign->get($field) + 1);
            $this->entityManager->saveEntity($campaign);
        }
    }

    private function checkCampaignCompletion(string $campaignId): void
    {
        $pending = $this->entityManager
            ->getRDBRepository('EmailCampaignContact')
            ->where([
                'emailCampaignId' => $campaignId,
                'status' => ['Pending', 'Retry', 'Processing'],
            ])
            ->count();

        if ($pending !== 0) {
            return;
        }

        $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

        if (
            $campaign &&
            $campaign->get('status') === 'Sending' &&
            !$campaign->get('continuousEnrollment')
        ) {
            $campaign->set([
                'status' => 'Completed',
                'completedAt' => date('Y-m-d H:i:s'),
            ]);
            $this->entityManager->saveEntity($campaign);
        }
    }

    /**
     * @return array{0: ?SmtpParams, 1: SenderParams}
     */
    private function getSenderParams(Entity $campaign): array
    {
        $smtpParams = null;
        $senderParams = SenderParams::create();
        $emailAccountId = $campaign->get('emailAccountId');
        $inboundEmailId = $campaign->get('inboundEmailId');

        if ($emailAccountId && $inboundEmailId) {
            throw new Error('Campaign cannot use both Personal and Group email accounts.');
        }

        if ($emailAccountId) {
            $account = $this->personalAccountFactory->create($emailAccountId);
            $smtpParams = $account->getSmtpParams();

            if (!$account->isAvailableForSending() || !$smtpParams) {
                throw new Error("Personal email account {$emailAccountId} can't be used for email campaign.");
            }

            $entity = $account->getEntity();
            $from = $entity->getEmailAddress();

            if ($from) {
                $senderParams = $senderParams->withFromAddress($from);
            }

            return [$smtpParams, $senderParams];
        }

        if (!$inboundEmailId) {
            return [$smtpParams, $senderParams];
        }

        $account = $this->groupAccountFactory->create($inboundEmailId);
        $smtpParams = $account->getSmtpParams();

        if (
            !$account->isAvailableForSending() ||
            !$account->getEntity()->smtpIsForMassEmail() ||
            !$smtpParams
        ) {
            throw new Error("Group email account {$inboundEmailId} can't be used for email campaign.");
        }

        if ($account->getEntity()->getReplyToAddress()) {
            $senderParams = $senderParams
                ->withReplyToAddress($account->getEntity()->getReplyToAddress());
        }

        return [$smtpParams, $senderParams];
    }

    private function isTransientFailure(string $message): bool
    {
        $needles = [
            'temporarily',
            'try again',
            'timeout',
            'timed out',
            'connection reset',
            'connection refused',
            '4.',
            'rate limit',
            'too many',
        ];

        $lower = strtolower($message);

        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
