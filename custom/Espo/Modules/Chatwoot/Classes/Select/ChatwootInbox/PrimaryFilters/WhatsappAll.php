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
 * All active WhatsApp inboxes (QR / Cloud API / Coexistence).
 * Used by journey "Send WhatsApp Message" inbox pickers.
 */
class WhatsappAll implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder
            ->leftJoin('chatwootInboxIntegration', 'integrationWhatsappAllFilter')
            ->where([
                'integrationWhatsappAllFilter.channelType' => [
                    ChatwootInboxIntegration::CHANNEL_TYPE_WHATSAPP_QRCODE,
                    ChatwootInboxIntegration::CHANNEL_TYPE_WHATSAPP_CLOUD_API,
                    'whatsappCoexistence',
                ],
                'integrationWhatsappAllFilter.status' => ChatwootInboxIntegration::STATUS_ACTIVE,
                'integrationWhatsappAllFilter.deleted' => false,
            ]);
    }
}
