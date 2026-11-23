<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\ChatwootConversation;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Services\JourneyConversations;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * A conversation the CRM copies in after a journey sent to it gets the enrollment stamped (journeyId, journeyRecordId),
 * as JourneyWhatsAppOutbound does when the row already exists at send time. Runs on silent sync saves too.
 *
 * @implements BeforeSave<Entity>
 */
class StampJourneyRecord implements BeforeSave
{
    public static int $order = 20;

    public function __construct(
        private JourneyConversations $conversations,
        private Log $log,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew() || (string) ($entity->get('journeyRecordId') ?? '') !== '') {
            return;
        }

        try {
            $record = $this->conversations->liveRecord(
                (string) ($entity->get('chatwootAccountId') ?? ''),
                (int) ($entity->get('chatwootConversationId') ?? 0),
            );
        } catch (Throwable $e) {
            $this->log->warning('StampJourneyRecord: ' . $e->getMessage());

            return;
        }

        if (!$record) {
            return;
        }

        $entity->set('journeyId', $record->get('journeyId'));
        $entity->set('journeyRecordId', $record->getId());
    }
}
