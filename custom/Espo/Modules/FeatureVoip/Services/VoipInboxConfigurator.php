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
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Pushes Twilio credentials (resolved from a CRM Credential entity) into a
 * Chatwoot inbox's voip_config via the voip_calls#settings endpoint.
 *
 * Triggered when linking a Twilio credential to a Chatwoot inbox — keeps the
 * secrets originating in the CRM and avoids duplicating them in Chatwoot's UI.
 */
class VoipInboxConfigurator
{
    public function __construct(
        private EntityManager $entityManager,
        private CredentialResolver $credentialResolver,
        private ChatwootApiClient $chatwootApiClient,
        private Log $log
    ) {}

    public function configureInbox(
        Entity $chatwootAccount,
        int $inboxId,
        string $credentialId
    ): array {
        $creds = $this->credentialResolver->resolve($credentialId);

        $platformUrl = $chatwootAccount->get('platformUrl');
        $accountApiKey = $chatwootAccount->get('accountApiKey') ?? $chatwootAccount->get('apiAccessToken');
        $accountId = (int) $chatwootAccount->get('chatwootAccountId');

        $voipConfig = [
            'voip_enabled' => true,
            'voip_provider' => 'twilio',
            'phone_number' => $creds->fromNumber ?? null,
            'twilio_account_sid' => $creds->accountSid ?? null,
            'twilio_api_key_sid' => $creds->apiKeySid ?? null,
            'twilio_api_key_secret' => $creds->apiKeySecret ?? null,
            'twilio_auth_token' => $creds->authToken ?? null,
        ];

        $voipConfig = array_filter($voipConfig, fn($v) => $v !== null);

        $response = $this->chatwootApiClient->updateVoipSettings(
            $platformUrl,
            $accountApiKey,
            $accountId,
            $inboxId,
            $voipConfig
        );

        $this->log->info("VoipInboxConfigurator: Configured inbox {$inboxId} with credential {$credentialId}");

        return $response;
    }
}
