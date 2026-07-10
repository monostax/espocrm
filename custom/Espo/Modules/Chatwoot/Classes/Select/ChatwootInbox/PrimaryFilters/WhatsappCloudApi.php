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

namespace Espo\Modules\Chatwoot\Classes\Select\ChatwootInbox\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\Modules\Chatwoot\Entities\ChatwootInboxIntegration;
use Espo\ORM\Query\SelectBuilder;

/**
 * Restrict inbox pickers to active Meta Cloud API / Coexistence WhatsApp inboxes.
 *
 * Used by WhatsAppCampaign so the user only picks template-capable Cloud API
 * inboxes (account + Meta auth can then be derived from the integration).
 */
class WhatsappCloudApi implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder
            ->leftJoin('chatwootInboxIntegration', 'integrationWhatsappCloudApiFilter')
            ->where([
                'integrationWhatsappCloudApiFilter.channelType' => [
                    ChatwootInboxIntegration::CHANNEL_TYPE_WHATSAPP_CLOUD_API,
                    'whatsappCoexistence',
                ],
                'integrationWhatsappCloudApiFilter.status' => ChatwootInboxIntegration::STATUS_ACTIVE,
                'integrationWhatsappCloudApiFilter.deleted' => false,
            ]);
    }
}
