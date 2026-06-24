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

namespace Espo\Modules\FeatureVoip\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureVoip\Services\VoipCallSync;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Controller to receive Chatwoot voice_call message webhook events.
 *
 * Listens for `message_created` / `message_updated` events from Chatwoot
 * where the message content_type is `voice_call`, and delegates to
 * VoipCallSync to upsert a CRM Call record.
 *
 * POST /api/v1/VoipWebhook/:accountId
 */
class VoipWebhook
{
    public function __construct(
        private EntityManager $entityManager,
        private VoipCallSync $voipCallSync,
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

        $this->log->debug("VoipWebhook: Received '{$event}' for account {$accountId}");

        if (!in_array($event, ['message_created', 'message_updated'], true)) {
            return (object) ['success' => true, 'message' => "Event '{$event}' ignored."];
        }

        $account = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where(['chatwootAccountId' => (int) $accountId])
            ->findOne();

        if (!$account) {
            $this->log->warning("VoipWebhook: Account not found for chatwootAccountId: {$accountId}");
            throw new NotFound("Account not found.");
        }

        $webhookSecrets = $this->collectWebhookSecrets($account->getId());

        if (!empty($webhookSecrets)) {
            $signature = $_SERVER['HTTP_X_CHATWOOT_SIGNATURE'] ?? null;
            $timestamp = $_SERVER['HTTP_X_CHATWOOT_TIMESTAMP'] ?? null;

            if (!$this->validateChatwootSignature($rawBody, $signature, $timestamp, $webhookSecrets)) {
                $this->log->warning("VoipWebhook: Invalid HMAC signature for account {$accountId}");
                throw new Forbidden('Invalid signature.');
            }
        }

        return $this->voipCallSync->syncFromWebhookEvent($data, $account);
    }

    /**
     * Collect every webhook secret registered for the account. Chatwoot does
     * not include the webhook id in the payload and each webhook signs with its
     * own secret, so we must validate the signature against all candidate
     * secrets and accept if any one matches.
     *
     * @return list<string>
     */
    private function collectWebhookSecrets(string $espoAccountId): array
    {
        $webhooks = $this->entityManager
            ->getRDBRepository('ChatwootAccountWebhook')
            ->where(['accountId' => $espoAccountId])
            ->find();

        $secrets = [];
        foreach ($webhooks as $webhook) {
            $secret = $webhook->get('webhookSecret');
            if ($secret) {
                $secrets[] = $secret;
            }
        }

        return $secrets;
    }

    /**
     * @param list<string> $secrets
     */
    private function validateChatwootSignature(
        string $rawBody,
        ?string $signature,
        ?string $timestamp,
        array $secrets
    ): bool {
        if (!$signature || !$timestamp) {
            return false;
        }

        $message = $timestamp . '.' . $rawBody;

        foreach ($secrets as $secret) {
            $expected = 'sha256=' . hash_hmac('sha256', $message, $secret);
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
