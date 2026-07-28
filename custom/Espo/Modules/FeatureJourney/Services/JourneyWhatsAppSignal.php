<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

/**
 * Signal codes for journey-originated WhatsApp ↔ inbound reply correlation.
 *
 * Correlation is conversation-based (not Content-ID style):
 *   outbound journey send stamps JourneyRecord + optional ChatwootConversation
 *   with chatwootConversationId + journeyId/journeyRecordId;
 *   inbound ChatwootMessage (or DeliveryWebhook) matches that conversation.
 */
class JourneyWhatsAppSignal
{
    public const CODE_REPLIED = 'whatsapp_replied';
}
