<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\WhatsAppOptOutService;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Controller to receive Chatwoot delivery status webhooks.
 *
 * Listens for `message_updated` events (delivery/read status) and
 * `message_created` events (incoming replies) from Chatwoot, mapping them
 * back to WhatsAppCampaignContact records and updating counters on the
 * parent WhatsAppCampaign.
 *
 * POST /api/v1/WhatsAppDeliveryWebhook/:accountId
 */
class DeliveryWebhook
{
    private const STATUS_ORDER = [
        'Pending' => 0,
        'Sent' => 1,
        'Delivered' => 2,
        'Read' => 3,
        'Replied' => 4,
        'Failed' => 99,
    ];

    public function __construct(
        private EntityManager $entityManager,
        private WhatsAppOptOutService $optOutService,
        private Log $log
    ) {}

    public function postActionReceive(Request $request, Response $response): stdClass
    {
        $accountId = $request->getRouteParam('accountId');

        if (!$accountId) {
            throw new BadRequest('Missing accountId parameter.');
        }

        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody);

        if (!$data) {
            throw new BadRequest('Invalid JSON payload.');
        }

        $event = $data->event ?? 'unknown';

        $this->log->debug("DeliveryWebhook: Received '{$event}' for account {$accountId}");

        if (!in_array($event, ['message_updated', 'message_created'], true)) {
            return (object) ['success' => true, 'message' => "Event '{$event}' ignored."];
        }

        $account = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where(['chatwootAccountId' => (int) $accountId])
            ->findOne();

        if (!$account) {
            $this->log->warning("DeliveryWebhook: Account not found for chatwootAccountId: {$accountId}");
            throw new NotFound("Account not found.");
        }

        $webhookSecret = $this->findWebhookSecret($account->getId());

        if ($webhookSecret) {
            $signature = $_SERVER['HTTP_X_CHATWOOT_SIGNATURE'] ?? null;
            $timestamp = $_SERVER['HTTP_X_CHATWOOT_TIMESTAMP'] ?? null;

            if (!$this->validateChatwootSignature($rawBody, $signature, $timestamp, $webhookSecret)) {
                $this->log->warning("DeliveryWebhook: Invalid HMAC signature for account {$accountId}");
                throw new Forbidden('Invalid signature.');
            }
        }

        if ($event === 'message_created') {
            return $this->handleMessageCreated($data, $accountId);
        }

        return $this->handleMessageUpdated($data, $accountId);
    }

    /**
     * Handle `message_created` — detect incoming replies to campaign conversations.
     * Only the first reply per contact is tracked.
     */
    private function handleMessageCreated(object $data, string $accountId): stdClass
    {
        $messageType = $data->message_type ?? null;

        // message_type 0 = incoming in Chatwoot
        if ($messageType !== 0 && $messageType !== 'incoming') {
            return (object) ['success' => true, 'message' => 'Not an incoming message.'];
        }

        $conversationId = null;

        if (isset($data->conversation->display_id)) {
            $conversationId = (string) $data->conversation->display_id;
        } elseif (isset($data->conversation->id)) {
            $conversationId = (string) $data->conversation->id;
        }

        if (!$conversationId) {
            return (object) ['success' => true, 'message' => 'No conversation ID in payload.'];
        }

        $campaignContact = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where(['chatwootConversationId' => $conversationId])
            ->findOne();

        if (!$campaignContact) {
            return (object) ['success' => true, 'message' => 'Conversation not related to a campaign.'];
        }

        $currentStatus = $campaignContact->get('status');

        if ($currentStatus === 'Replied') {
            return (object) ['success' => true, 'message' => 'Already replied.'];
        }

        $campaignContact->set('status', 'Replied');
        $campaignContact->set('repliedAt', date('Y-m-d H:i:s'));

        if (!$campaignContact->get('deliveredAt')) {
            $campaignContact->set('deliveredAt', date('Y-m-d H:i:s'));
        }
        if (!$campaignContact->get('readAt')) {
            $campaignContact->set('readAt', date('Y-m-d H:i:s'));
        }

        $this->entityManager->saveEntity($campaignContact);
        $this->updateCampaignCounters($campaignContact->get('whatsAppCampaignId'));

        $this->log->info(
            "DeliveryWebhook: Contact {$campaignContact->getId()} replied " .
            "(conversation {$conversationId}, account {$accountId})"
        );

        return (object) ['success' => true];
    }

    /**
     * Handle `message_updated` — delivery/read/failed status changes.
     */
    private function handleMessageUpdated(object $data, string $accountId): stdClass
    {
        $messageId = isset($data->id) ? (string) $data->id : null;

        if (!$messageId) {
            return (object) ['success' => true, 'message' => 'No message ID in payload.'];
        }

        $campaignContact = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where(['chatwootMessageId' => $messageId])
            ->findOne();

        if (!$campaignContact) {
            return (object) ['success' => true, 'message' => 'Message not related to a campaign.'];
        }

        $messageStatus = $data->status ?? null;

        if (!$messageStatus && isset($data->conversation->messages) && is_array($data->conversation->messages)) {
            foreach ($data->conversation->messages as $msg) {
                if (isset($msg->id) && (string) $msg->id === $messageId) {
                    $messageStatus = $msg->status ?? null;
                    break;
                }
            }
        }

        $newStatus = match ($messageStatus) {
            'delivered' => 'Delivered',
            'read' => 'Read',
            'failed' => 'Failed',
            default => null,
        };

        if (!$newStatus) {
            return (object) ['success' => true, 'message' => "Status '{$messageStatus}' not actionable."];
        }

        $currentStatus = $campaignContact->get('status');
        $currentRank = self::STATUS_ORDER[$currentStatus] ?? -1;
        $newRank = self::STATUS_ORDER[$newStatus] ?? -1;

        if ($newRank <= $currentRank && $newStatus !== 'Failed') {
            return (object) ['success' => true, 'message' => 'Status not advanced.'];
        }

        $campaignContact->set('status', $newStatus);

        if ($newStatus === 'Delivered') {
            $campaignContact->set('deliveredAt', date('Y-m-d H:i:s'));
        } elseif ($newStatus === 'Read') {
            if (!$campaignContact->get('deliveredAt')) {
                $campaignContact->set('deliveredAt', date('Y-m-d H:i:s'));
            }
            $campaignContact->set('readAt', date('Y-m-d H:i:s'));
        } elseif ($newStatus === 'Failed') {
            $campaignContact->set('failedAt', date('Y-m-d H:i:s'));

            $failedReason = null;
            $contentAttrs = $data->content_attributes ?? null;
            if ($contentAttrs && isset($contentAttrs->external_error)) {
                $failedReason = $contentAttrs->external_error;
            }
            if (!$failedReason) {
                $failedReason = $data->error ?? 'Delivery failed';
            }
            if (is_object($failedReason)) {
                $failedReason = json_encode($failedReason);
            }
            $campaignContact->set('failedReason', (string) $failedReason);
        }

        $this->entityManager->saveEntity($campaignContact);
        $this->updateCampaignCounters($campaignContact->get('whatsAppCampaignId'));

        if ($newStatus === 'Failed') {
            $reason = (string) ($campaignContact->get('failedReason') ?? '');

            if ($this->optOutService->isPermanentFailure($reason)) {
                $contactId = $campaignContact->get('contactId');
                $campaignId = $campaignContact->get('whatsAppCampaignId');

                if ($contactId && $campaignId) {
                    $this->optOutService->autoOptOutContact($contactId, $campaignId, $reason);
                }
            }
        }

        $this->log->info(
            "DeliveryWebhook: Updated contact {$campaignContact->getId()} " .
            "from '{$currentStatus}' to '{$newStatus}' (message {$messageId})"
        );

        return (object) ['success' => true];
    }

    /**
     * Update campaign aggregate counters from contact records.
     * Replied contacts are included in sent/delivered/read counts since
     * a reply implies all prior stages were reached.
     */
    private function updateCampaignCounters(string $campaignId): void
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            return;
        }

        $repo = $this->entityManager->getRDBRepository('WhatsAppCampaignContact');

        $countForStatuses = function (array $statuses) use ($repo, $campaignId): int {
            $total = 0;
            foreach ($statuses as $status) {
                $total += $repo->where(['whatsAppCampaignId' => $campaignId, 'status' => $status])->count();
            }
            return $total;
        };

        $campaign->set([
            'sentCount' => $countForStatuses(['Sent', 'Delivered', 'Read', 'Replied']),
            'deliveredCount' => $countForStatuses(['Delivered', 'Read', 'Replied']),
            'readCount' => $countForStatuses(['Read', 'Replied']),
            'repliedCount' => $countForStatuses(['Replied']),
            'failedCount' => $countForStatuses(['Failed']),
        ]);

        $this->entityManager->saveEntity($campaign);
    }

    /**
     * Find the webhook secret for a given EspoCRM ChatwootAccount.
     * Looks up the ChatwootAccountWebhook that has the delivery URL pattern.
     */
    private function findWebhookSecret(string $espoAccountId): ?string
    {
        $webhook = $this->entityManager
            ->getRDBRepository('ChatwootAccountWebhook')
            ->where(['accountId' => $espoAccountId])
            ->order('createdAt', 'DESC')
            ->findOne();

        if ($webhook) {
            return $webhook->get('webhookSecret');
        }

        return null;
    }

    /**
     * Validate Chatwoot webhook signature.
     *
     * Chatwoot signs webhooks as: sha256=HMAC-SHA256(secret, "{timestamp}.{raw_body}")
     * Headers: X-Chatwoot-Signature, X-Chatwoot-Timestamp
     */
    private function validateChatwootSignature(
        string $rawBody,
        ?string $signature,
        ?string $timestamp,
        string $secret
    ): bool {
        if (!$signature || !$timestamp) {
            return false;
        }

        $message = $timestamp . '.' . $rawBody;
        $expected = 'sha256=' . hash_hmac('sha256', $message, $secret);

        return hash_equals($expected, $signature);
    }
}
