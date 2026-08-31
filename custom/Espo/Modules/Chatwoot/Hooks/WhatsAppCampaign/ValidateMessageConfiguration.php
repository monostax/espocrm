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
use Espo\Modules\Chatwoot\Tools\WhatsAppChannel;
use Espo\ORM\Entity;

/**
 * Enforces that messageMode, the inbox channel, and the message content agree.
 *
 * `templateName` is no longer unconditionally required at the entity level
 * (free-text campaigns have no template), so the per-mode requirement is
 * enforced here instead:
 *
 *   - Template mode requires a template-capable channel + templateName.
 *   - FreeText mode requires messageBody, and clears template attributes so a
 *     campaign converted from Template mode cannot send a stale template.
 *
 * Order = 2 so it runs after DeriveFromInbox (0) has resolved channelType and
 * CascadeTenantFromAccount (1), but before ValidateOpportunityConfiguration (20).
 */
class ValidateMessageConfiguration
{
    public static int $order = 2;

    /**
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        if (!empty($options['silent'])) {
            return;
        }

        $channelType = $entity->get('channelType');
        $mode = WhatsAppChannel::normalizeMode($entity->get('messageMode'));

        // Normalize legacy/blank values so downstream reads never branch on null.
        $entity->set('messageMode', $mode);

        // channelType may legitimately be absent on legacy rows that are saved
        // without touching the inbox; DeriveFromInbox only backfills it when
        // the inbox is set. Skip channel checks in that case.
        if ($channelType !== null && $channelType !== '' && !WhatsAppChannel::supportsMode($channelType, $mode)) {
            throw new BadRequest(sprintf(
                'A %s inbox cannot send %s messages. %s',
                WhatsAppChannel::label($channelType),
                $mode === WhatsAppChannel::MODE_TEMPLATE ? 'template' : 'free-text',
                $mode === WhatsAppChannel::MODE_TEMPLATE
                    ? 'Meta message templates require a Cloud API or Coexistence inbox; ' .
                      'switch the campaign to Free Text.'
                    : 'Select a different inbox.',
            ));
        }

        if ($mode === WhatsAppChannel::MODE_TEMPLATE) {
            $templateName = trim((string) $entity->get('templateName'));

            if ($templateName === '') {
                throw new BadRequest('Select a WhatsApp template for the campaign.');
            }

            // Meta templates carry their own media in the template header
            // (headerMediaUrl); a separate attachment has no way to be sent.
            if ($entity->get('attachmentId')) {
                throw new BadRequest(
                    'Template campaigns cannot carry an attachment. ' .
                    'Use the template header media, or switch the campaign to Free Text.'
                );
            }

            return;
        }

        $messageBody = trim((string) $entity->get('messageBody'));

        // Media-only sends are legitimate (a bare voice note or image), so the
        // body is only required when there is nothing else to deliver.
        if ($messageBody === '' && !$entity->get('attachmentId')) {
            throw new BadRequest('Enter the message body, or attach a file, for the campaign.');
        }

        // Free-text sends resolve {{tokens}} straight from the body; the Meta
        // template attributes are meaningless and must not leak into the send
        // path or the A/B-test cloner.
        $entity->set([
            'templateName' => null,
            'templateLanguage' => null,
            'templateCategory' => null,
            'templateBody' => null,
            'parameterMapping' => null,
            'headerMediaUrl' => null,
            'headerMediaType' => null,
        ]);
    }
}
