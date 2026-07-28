<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\ChatwootMessage;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppReplyTracker;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * Inbound Chatwoot WhatsApp message → journey signal `whatsapp_replied`.
 *
 * Correlation is conversation-based (parallel to email Message-ID / repliedId):
 * journey outbound stamps JourneyRecord.whatsAppChatwootConversationId (+ optional
 * ChatwootConversation.journeyRecordId); inbound messages on that conversation fire.
 *
 * @implements AfterSave<Entity>
 */
class TrackJourneyWhatsAppReply implements AfterSave
{
    public static int $order = 60;

    public function __construct(
        private JourneyWhatsAppReplyTracker $tracker,
        private Log $log,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->getEntityType() !== 'ChatwootMessage') {
            return;
        }

        try {
            $this->tracker->handleChatwootMessage($entity);
        } catch (Throwable $e) {
            $this->log->warning('TrackJourneyWhatsAppReply: ' . $e->getMessage());
        }
    }
}
