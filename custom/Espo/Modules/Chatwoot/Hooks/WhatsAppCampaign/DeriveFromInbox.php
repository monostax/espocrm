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

namespace Espo\Modules\Chatwoot\Hooks\WhatsAppCampaign;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Derives chatwootAccount / credential / wabaId from the selected ChatwootInbox.
 *
 * Server-side counterpart of the campaign form inbox picker: storybook/UI may
 * prefill, but save path re-derives so API clients and race conditions cannot
 * leave account/auth out of sync with the inbox.
 *
 * Order = 0 so it runs before CascadeTenantFromAccount (order = 1).
 */
class DeriveFromInbox
{
    public static int $order = 0;

    public function __construct(
        private EntityManager $entityManager,
        private CredentialResolver $credentialResolver,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        if (!empty($options['silent'])) {
            return;
        }

        $inboxId = $entity->get('chatwootInboxId');

        if (!$inboxId) {
            if ($entity->isNew() || $entity->isAttributeChanged('chatwootInboxId')) {
                throw new BadRequest(
                    'Select a WhatsApp Inbox (Meta Cloud API) for the campaign.'
                );
            }

            return;
        }

        if (
            !$entity->isNew() &&
            !$entity->isAttributeChanged('chatwootInboxId') &&
            $entity->get('chatwootAccountId') &&
            $entity->get('wabaId')
        ) {
            return;
        }

        $inbox = $this->entityManager->getEntityById('ChatwootInbox', $inboxId);

        if (!$inbox) {
            throw new BadRequest("ChatwootInbox {$inboxId} not found.");
        }

        $chatwootAccountId = $inbox->get('chatwootAccountId');

        if (!$chatwootAccountId) {
            throw new Error('Selected inbox has no Chatwoot Account.');
        }

        $entity->set('chatwootAccountId', $chatwootAccountId);

        $integrationId = $inbox->get('chatwootInboxIntegrationId');

        if (!$integrationId) {
            throw new BadRequest('Selected inbox has no Meta channel connection.');
        }

        $integration = $this->entityManager
            ->getEntityById('ChatwootInboxIntegration', $integrationId);

        if (!$integration) {
            throw new BadRequest('Selected inbox channel connection not found.');
        }

        $channelType = $integration->get('channelType');
        $allowedTypes = ['whatsappCloudApi', 'whatsappCoexistence'];

        if (!in_array($channelType, $allowedTypes, true)) {
            throw new BadRequest(
                'Selected inbox must be a Meta Cloud API (or Coexistence) channel connection.'
            );
        }

        if ($integration->get('status') !== 'ACTIVE') {
            throw new BadRequest('Selected inbox channel connection is not ACTIVE.');
        }

        $credentialId = $integration->get('credentialId');
        $oAuthAccountId = $integration->get('oAuthAccountId');
        $wabaId = $integration->get('businessAccountId');

        if ($credentialId) {
            $entity->set('credentialId', $credentialId);
        }

        if (!$wabaId && $credentialId) {
            $credentialData = $this->credentialResolver->resolve($credentialId);
            $wabaId = $credentialData->businessAccountId ?? null;
        }

        if (!$wabaId) {
            throw new Error('Selected inbox has no WABA (businessAccountId).');
        }

        if (!$credentialId && !$oAuthAccountId) {
            throw new Error(
                'Selected inbox channel connection has neither Credential nor OAuth account.'
            );
        }

        $entity->set('wabaId', $wabaId);
    }
}
