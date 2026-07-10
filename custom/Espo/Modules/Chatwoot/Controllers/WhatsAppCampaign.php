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
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Chatwoot\Services\WhatsAppCampaignService;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use stdClass;

/**
 * Controller for WhatsApp Campaign entity.
 *
 * Extends Record for standard CRUD and adds send, abort, and template validation actions.
 */
class WhatsAppCampaign extends Record
{
    /**
     * Launch a WhatsApp campaign.
     *
     * POST /api/v1/WhatsAppCampaign/:id/send
     */
    public function postActionSend(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing campaign ID.');
        }

        $campaign = $this->getWhatsAppCampaignService()->launch($id);

        return (object) $campaign->getValueMap();
    }

    /**
     * Abort a running WhatsApp campaign.
     *
     * POST /api/v1/WhatsAppCampaign/:id/abort
     */
    public function postActionAbort(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing campaign ID.');
        }

        $campaign = $this->getWhatsAppCampaignService()->abort($id);

        return (object) $campaign->getValueMap();
    }

    /**
     * Stop continuous enrollment on a running WhatsApp campaign.
     *
     * POST /api/v1/WhatsAppCampaign/:id/stopEnrollment
     */
    public function postActionStopEnrollment(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing campaign ID.');
        }

        $campaign = $this->getWhatsAppCampaignService()->stopEnrollment($id);

        return (object) $campaign->getValueMap();
    }

    /**
     * Resolve a credential's WABA ID and OAuth account info.
     *
     * GET /api/v1/WhatsAppCampaign/action/resolveCredential?credentialId=xxx
     */
    public function getActionResolveCredential(Request $request, Response $response): stdClass
    {
        $credentialId = $request->getQueryParam('credentialId');

        if (!$credentialId) {
            throw new BadRequest('Missing credentialId query parameter.');
        }

        $credentialResolver = $this->injectableFactory->create(CredentialResolver::class);
        $credentialData = $credentialResolver->resolve($credentialId);
        $wabaId = $credentialData->businessAccountId ?? null;

        if (!$wabaId) {
            throw new Error('Credential does not contain a businessAccountId (WABA ID).');
        }

        return (object) [
            'wabaId' => $wabaId,
        ];
    }

    /**
     * Resolve Chat account + Meta auth from a Meta Cloud API ChatwootInbox.
     *
     * GET /api/v1/WhatsAppCampaign/action/resolveInbox?chatwootInboxId=xxx
     *
     * Used by the campaign form after the user picks an inbox: templates and
     * send path then use the derived account/credential/WABA without separate
     * Credential / ChatwootAccount selectors.
     */
    public function getActionResolveInbox(Request $request, Response $response): stdClass
    {
        $inboxId = $request->getQueryParam('chatwootInboxId');

        if (!$inboxId) {
            throw new BadRequest('Missing chatwootInboxId query parameter.');
        }

        $entityManager = $this->entityManager;

        $inbox = $entityManager->getEntityById('ChatwootInbox', $inboxId);

        if (!$inbox) {
            throw new NotFound("ChatwootInbox {$inboxId} not found.");
        }

        if (!$this->acl->check($inbox, 'read')) {
            throw new Forbidden("No access to ChatwootInbox {$inboxId}.");
        }

        $chatwootAccountId = $inbox->get('chatwootAccountId');
        $chatwootAccountName = null;

        if ($chatwootAccountId) {
            $account = $entityManager->getEntityById('ChatwootAccount', $chatwootAccountId);
            $chatwootAccountName = $account?->get('name');
        }

        $integrationId = $inbox->get('chatwootInboxIntegrationId');
        $credentialId = null;
        $credentialName = null;
        $oAuthAccountId = null;
        $wabaId = null;
        $channelType = null;
        $status = null;

        if ($integrationId) {
            $integration = $entityManager->getEntityById('ChatwootInboxIntegration', $integrationId);

            if ($integration) {
                $channelType = $integration->get('channelType');
                $status = $integration->get('status');
                $credentialId = $integration->get('credentialId');
                $oAuthAccountId = $integration->get('oAuthAccountId');
                $wabaId = $integration->get('businessAccountId');

                if ($credentialId) {
                    $credential = $entityManager->getEntityById('Credential', $credentialId);
                    $credentialName = $credential?->get('name');
                }

                if (!$wabaId && $credentialId) {
                    $credentialResolver = $this->injectableFactory->create(CredentialResolver::class);
                    $credentialData = $credentialResolver->resolve($credentialId);
                    $wabaId = $credentialData->businessAccountId ?? null;
                }
            }
        }

        $allowedTypes = ['whatsappCloudApi', 'whatsappCoexistence'];

        if (!$channelType || !in_array($channelType, $allowedTypes, true)) {
            throw new BadRequest(
                'Selected inbox is not linked to a Meta Cloud API (or Coexistence) channel connection.'
            );
        }

        if ($status !== 'ACTIVE') {
            throw new BadRequest('Selected inbox channel connection is not ACTIVE.');
        }

        if (!$chatwootAccountId) {
            throw new Error('Inbox has no Chatwoot Account linked.');
        }

        if (!$oAuthAccountId && !$credentialId) {
            throw new Error('Inbox channel connection has neither OAuth account nor Credential for Meta API access.');
        }

        if (!$wabaId) {
            throw new Error('Inbox channel connection does not have a WABA (businessAccountId).');
        }

        return (object) [
            'chatwootAccountId' => $chatwootAccountId,
            'chatwootAccountName' => $chatwootAccountName,
            'credentialId' => $credentialId,
            'credentialName' => $credentialName,
            'oAuthAccountId' => $oAuthAccountId,
            'wabaId' => $wabaId,
            'channelType' => $channelType,
            'status' => $status,
            'chatwootExternalInboxId' => $inbox->get('chatwootInboxId'),
        ];
    }

    /**
     * Server-side re-validation of a WhatsApp template before sending.
     *
     * POST /api/v1/WhatsAppCampaign/action/validateTemplate
     * Body: { templateName, language, credentialId, wabaId }
     */
    public function postActionValidateTemplate(Request $request, Response $response): stdClass
    {
        $data = $request->getParsedBody();

        $templateName = $data->templateName ?? null;
        $language = $data->language ?? null;
        $credentialId = $data->credentialId ?? null;
        $wabaId = $data->wabaId ?? null;

        if (!$templateName || !$language || !$credentialId || !$wabaId) {
            throw new BadRequest('Missing required fields: templateName, language, credentialId, wabaId.');
        }

        $credentialResolver = $this->injectableFactory->create(CredentialResolver::class);
        $credentialData = $credentialResolver->resolve($credentialId);
        $accessToken = $credentialData->accessToken ?? null;

        if (!$accessToken) {
            throw new Error('Credential does not contain an access token.');
        }

        $template = $this->getWhatsAppCampaignService()->validateTemplate(
            $templateName,
            $language,
            $accessToken,
            $wabaId
        );

        return (object) [
            'valid' => true,
            'template' => $template,
        ];
    }

    private function getWhatsAppCampaignService(): WhatsAppCampaignService
    {
        return $this->injectableFactory->create(WhatsAppCampaignService::class);
    }
}
