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
use Espo\Modules\Chatwoot\Services\WhatsAppCampaignDistributionService;
use Espo\Modules\Chatwoot\Tools\WhatsAppChannel;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use stdClass;

/**
 * Controller for WhatsApp Campaign entity.
 *
 * Extends Record for standard CRUD and adds send, abort, and template validation actions.
 */
class WhatsAppCampaign extends Record implements \Espo\Core\Di\EntityManagerAware
{
    use \Espo\Core\Di\EntityManagerSetter;

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

        $campaign = $this->requireEditableCampaign($id);
        $this->assertOpportunityCreateAccess($campaign);
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

        $this->requireEditableCampaign($id);
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

        $this->requireEditableCampaign($id);
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
     * Resolve Chat account (and Meta auth, when applicable) from a WhatsApp
     * ChatwootInbox.
     *
     * GET /api/v1/WhatsAppCampaign/action/resolveInbox?chatwootInboxId=xxx
     *
     * Used by the campaign form after the user picks an inbox: templates and
     * send path then use the derived account/credential/WABA without separate
     * Credential / ChatwootAccount selectors.
     *
     * Meta Cloud API / Coexistence inboxes must resolve a WABA + auth.
     * WAHA QR inboxes have no Meta identity, so those checks are skipped and
     * the response reports the channel's capabilities instead; the form uses
     * them to force free-text mode.
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

        if (!WhatsAppChannel::isSendable($channelType)) {
            throw new BadRequest(
                'Selected inbox is not linked to a sendable WhatsApp channel connection ' .
                '(Meta Cloud API, Coexistence, or QR Code).'
            );
        }

        if ($status !== 'ACTIVE') {
            throw new BadRequest('Selected inbox channel connection is not ACTIVE.');
        }

        if (!$chatwootAccountId) {
            throw new Error('Inbox has no Chatwoot Account linked.');
        }

        // Only template-capable channels talk to the Meta Graph API; a QR
        // session has no OAuth account, Credential or WABA by design.
        if (WhatsAppChannel::requiresMetaAuth($channelType)) {
            if (!$oAuthAccountId && !$credentialId) {
                throw new Error(
                    'Inbox channel connection has neither OAuth account nor Credential for Meta API access.'
                );
            }

            if (!$wabaId) {
                throw new Error('Inbox channel connection does not have a WABA (businessAccountId).');
            }
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
            'supportsTemplates' => WhatsAppChannel::supportsTemplates($channelType),
            'supportsFreeText' => WhatsAppChannel::supportsFreeText($channelType),
            'defaultMessageMode' => WhatsAppChannel::defaultModeFor($channelType),
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

    /**
     * Create an A/B/n test from a Draft campaign: clones it as N template
     * variants and wires everything into a new campaign distribution.
     *
     * POST /api/v1/WhatsAppCampaign/:id/createAbTest
     * Body: { weight, variants: [{ name, weight, templateName, ... }], activate }
     */
    public function postActionCreateAbTest(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing campaign ID.');
        }

        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $id);

        if (!$campaign) {
            throw new NotFound("Campaign {$id} not found.");
        }

        if (!$this->acl->check($campaign, 'edit')) {
            throw new Forbidden('No edit access to the campaign.');
        }

        if (
            !$this->acl->checkScope('WhatsAppCampaign', 'create') ||
            !$this->acl->checkScope('WhatsAppCampaignDistribution', 'create')
        ) {
            throw new Forbidden('No create access to campaigns or campaign distributions.');
        }

        $data = $request->getParsedBody();

        if (!empty($data->activate)) {
            $this->assertOpportunityCreateAccess($campaign);
        }

        $result = $this->injectableFactory
            ->create(WhatsAppCampaignDistributionService::class)
            ->createAbTest($id, $data);

        $responseData = (object) $result['distribution']->getValueMap();
        $responseData->activationError = $result['activationError'];

        return $responseData;
    }

    private function getWhatsAppCampaignService(): WhatsAppCampaignService
    {
        return $this->injectableFactory->create(WhatsAppCampaignService::class);
    }

    private function requireEditableCampaign(string $id): \Espo\ORM\Entity
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $id);

        if (!$campaign) {
            throw new NotFound("Campaign {$id} not found.");
        }

        if (!$this->acl->check($campaign, 'edit')) {
            throw new Forbidden('No edit access to the campaign.');
        }

        return $campaign;
    }

    private function assertOpportunityCreateAccess(\Espo\ORM\Entity $campaign): void
    {
        if (!$campaign->get('createOpportunity')) {
            return;
        }

        if (!$this->acl->checkScope('Opportunity', 'create')) {
            throw new Forbidden('No create access to Opportunities.');
        }

        foreach ([
            ['Funnel', 'funnelId'],
            ['OpportunityStage', 'opportunityStageId'],
            ['User', 'opportunityAssignedUserId'],
        ] as [$entityType, $attribute]) {
            $linkedId = $campaign->get($attribute);
            $linked = $linkedId
                ? $this->entityManager->getEntityById($entityType, $linkedId)
                : null;

            if (!$linked || !$this->acl->check($linked, 'read')) {
                throw new Forbidden("No read access to the configured {$entityType}.");
            }
        }
    }
}
