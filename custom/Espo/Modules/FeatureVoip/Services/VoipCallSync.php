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

namespace Espo\Modules\FeatureVoip\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Tools\ContactReconciler;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Syncs Chatwoot voice_call message webhook events into CRM Call records.
 *
 * For each voice_call message event:
 *  - Resolves or creates a Contact via E.164 phone matching (ContactReconciler)
 *  - Upserts a CRM Call by twilioCallSid (or Chatwoot message source_id)
 *  - Sets status, direction, duration, recordingUrl, and parent links
 */
class VoipCallSync
{
    private const VOICE_CALL_CONTENT_TYPE = 12;

    private const STATUS_MAP = [
        'ringing' => 'Planned',
        'in-progress' => 'Held',
        'answered' => 'Held',
        'completed' => 'Held',
        'no-answer' => 'Not Held',
        'busy' => 'Not Held',
        'failed' => 'Not Held',
        'canceled' => 'Not Held',
        'declined' => 'Not Held',
        'missed' => 'Not Held',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private ContactReconciler $contactReconciler,
        private Log $log
    ) {}

    public function syncFromWebhookEvent(object $data, Entity $account): stdClass
    {
        $content = $data->content ?? null;
        $contentAttributes = $data->content_attributes ?? null;
        $messageType = $data->message_type ?? null;

        if (!$this->isVoiceCallMessage($data)) {
            return (object) ['success' => true, 'message' => 'Not a voice_call message, skipped.'];
        }

        $callData = $contentAttributes->data ?? null;
        if (!$callData) {
            return (object) ['success' => true, 'message' => 'No call data in content_attributes, skipped.'];
        }

        $callSid = $callData->call_sid ?? $data->source_id ?? null;
        if (!$callSid) {
            $this->log->warning('VoipCallSync: No call_sid found in voice_call message');
            return (object) ['success' => false, 'message' => 'No call_sid'];
        }

        $existingCall = $this->findCallBySid($callSid);
        $contactId = $this->resolveContact($callData, $account, $data);

        $status = $callData->status ?? 'ringing';
        $crmStatus = self::STATUS_MAP[$status] ?? 'Planned';

        $direction = ($callData->call_direction ?? ($messageType === 'outgoing' ? 'outbound' : 'inbound')) === 'outbound'
            ? 'Outbound'
            : 'Inbound';

        $duration = $callData->duration ?? null;
        $recordingUrl = $callData->recording_url ?? null;

        $meta = $callData->meta ?? (object) [];
        $dateStart = $this->resolveDateStart($meta, $callData, $data);

        $callDataArray = [
            'name' => $this->buildCallName($callData, $direction),
            'status' => $crmStatus,
            'direction' => $direction,
            'twilioCallSid' => $callSid,
            'voipProvider' => $callData->voice_provider ?? 'voip_twilio',
            'chatwootConversationId' => $data->conversation_id ?? null,
            'chatwootAccountId' => $data->account_id ?? null,
            'dateStart' => $dateStart,
            'assignedUserId' => $this->resolveAssignedUser($account),
            'tenantId' => $account->get('tenantId'),
        ];

        // Mirror the ChatwootAccount's teams so the Call is visible under the
        // same team-based ACL scope as the rest of the tenant's records.
        $teamsIds = $account->getLinkMultipleIdList('teams');
        if (!empty($teamsIds)) {
            $callDataArray['teamsIds'] = $teamsIds;
        }

        if ($duration) {
            $callDataArray['duration'] = (float) $duration;
        }

        if ($recordingUrl) {
            $callDataArray['recordingUrl'] = $recordingUrl;
        }

        if ($crmStatus === 'Held' && isset($meta->ended_at)) {
            $callDataArray['dateEnd'] = date('Y-m-d H:i:s', (int) $meta->ended_at);
        } elseif ($crmStatus === 'Held' && $duration) {
            $callDataArray['dateEnd'] = date('Y-m-d H:i:s', strtotime($dateStart) + (int) $duration);
        }

        if ($existingCall) {
            $existingCall->set($callDataArray);
            $this->entityManager->saveEntity($existingCall);
            $call = $existingCall;
        } else {
            $call = $this->entityManager->getEntity('Call');
            $call->set($callDataArray);
            $this->entityManager->saveEntity($call);
        }

        if ($contactId) {
            $this->linkContact($call, $contactId);
        }

        return (object) ['success' => true, 'callId' => $call->getId()];
    }

    private function isVoiceCallMessage(object $data): bool
    {
        $contentType = $data->content_type ?? null;
        if ($contentType === self::VOICE_CALL_CONTENT_TYPE || $contentType === 'voice_call') {
            return true;
        }

        $content = $data->content ?? '';
        $contentAttributes = $data->content_attributes ?? null;
        $callData = $contentAttributes->data ?? null;

        return $content === 'Voice Call' && $callData && isset($callData->call_sid);
    }

    private function findCallBySid(string $callSid): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository('Call')
            ->where(['twilioCallSid' => $callSid])
            ->findOne();
    }

    private function resolveContact(object $callData, Entity $account, object $message): ?string
    {
        $phoneNumber = ($callData->call_direction ?? null) === 'outbound'
            ? ($callData->to_number ?? null)
            : ($callData->from_number ?? null);

        if (!$phoneNumber) {
            return null;
        }

        $e164 = PhoneNormalizer::normalize($phoneNumber);
        if (!$e164) {
            $e164 = $phoneNumber;
        }

        $senderName = $message->sender->name ?? null;
        $input = [
            'tenantId' => $account->get('tenantId'),
            'chatwootAccountId' => $account->getId(),
            'name' => $senderName ?? $e164,
            'phoneNumber' => $e164,
            'contactInboxes' => [
                [
                    'source_id' => $e164,
                    'inbox' => ['channel_type' => 'Channel::Api'],
                ],
            ],
        ];

        try {
            $result = $this->contactReconciler->reconcile($input);
            $contact = $result['contact'] ?? null;
            return $contact ? $contact->getId() : null;
        } catch (\Throwable $e) {
            $this->log->error("VoipCallSync: ContactReconciler failed: {$e->getMessage()}");
            return null;
        }
    }

    private function linkContact(Entity $call, string $contactId): void
    {
        $this->entityManager
            ->getRDBRepository('Call')
            ->getRelation($call, 'contacts')
            ->relateById($contactId);

        $call->set('parentType', 'Contact');
        $call->set('parentId', $contactId);
        $this->entityManager->saveEntity($call);
    }

    private function buildCallName(object $callData, string $direction): string
    {
        $from = $callData->from_number ?? '?';
        $to = $callData->to_number ?? '?';
        $dir = $direction === 'Outbound' ? '→' : '←';

        return "VoIP Call {$from} {$dir} {$to}";
    }

    private function resolveDateStart(object $meta, object $callData, object $message): string
    {
        if (isset($meta->initiated_at)) {
            return date('Y-m-d H:i:s', (int) $meta->initiated_at);
        }
        if (isset($meta->ringing_at)) {
            return date('Y-m-d H:i:s', (int) $meta->ringing_at);
        }
        if (isset($meta->answered_at)) {
            return date('Y-m-d H:i:s', (int) $meta->answered_at);
        }

        $createdAt = $message->created_at ?? null;
        if ($createdAt) {
            $ts = is_numeric($createdAt) ? (int) $createdAt : strtotime($createdAt);
            return date('Y-m-d H:i:s', $ts);
        }

        return date('Y-m-d H:i:s');
    }

    private function resolveAssignedUser(Entity $account): ?string
    {
        $assignedUser = $account->get('assignedUser');
        if ($assignedUser) {
            return $assignedUser->getId();
        }

        return null;
    }
}
