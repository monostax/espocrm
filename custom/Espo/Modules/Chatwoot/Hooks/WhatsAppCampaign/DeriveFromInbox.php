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
use Espo\Modules\Chatwoot\Tools\WhatsAppChannel;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Derives chatwootAccount / channelType (and Meta credential / wabaId when the
 * channel is template-capable) from the selected ChatwootInbox.
 *
 * Server-side counterpart of the campaign form inbox picker: storybook/UI may
 * prefill, but save path re-derives so API clients and race conditions cannot
 * leave account/auth out of sync with the inbox.
 *
 * WAHA QR inboxes have no Meta identity: credential/wabaId derivation is
 * skipped for them and any stale Meta attributes are cleared, so a campaign
 * switched from Cloud API to QR cannot keep sending with the old WABA.
 *
 * Order = 0 so it runs before CascadeTenantFromAccount (order = 1) and
 * ValidateMessageConfiguration (order = 2), which reads the channelType
 * this hook resolves.
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
                    'Select a WhatsApp Inbox (Meta Cloud API, Coexistence, or QR Code) for the campaign.'
                );
            }

            return;
        }

        // Nothing to re-derive: inbox unchanged and the channel snapshot is
        // already consistent with what this hook would produce.
        if (
            !$entity->isNew() &&
            !$entity->isAttributeChanged('chatwootInboxId') &&
            $entity->get('chatwootAccountId') &&
            $entity->get('channelType') &&
            (
                !WhatsAppChannel::requiresMetaAuth($entity->get('channelType')) ||
                $entity->get('wabaId')
            )
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
            throw new BadRequest('Selected inbox has no WhatsApp channel connection.');
        }

        $integration = $this->entityManager
            ->getEntityById('ChatwootInboxIntegration', $integrationId);

        if (!$integration) {
            throw new BadRequest('Selected inbox channel connection not found.');
        }

        $channelType = $integration->get('channelType');

        if (!WhatsAppChannel::isSendable($channelType)) {
            throw new BadRequest(
                'Selected inbox must be a Meta Cloud API, Coexistence, or QR Code channel connection.'
            );
        }

        if ($integration->get('status') !== 'ACTIVE') {
            throw new BadRequest('Selected inbox channel connection is not ACTIVE.');
        }

        $entity->set('channelType', $channelType);

        if (!WhatsAppChannel::requiresMetaAuth($channelType)) {
            // QR sessions send through Chatwoot with no Meta auth. Clear any
            // Meta attributes inherited from a previously selected inbox.
            $entity->set([
                'credentialId' => null,
                'wabaId' => null,
            ]);

            return;
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
