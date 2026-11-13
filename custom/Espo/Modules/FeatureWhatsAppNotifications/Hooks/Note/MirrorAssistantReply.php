<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Hooks\Note;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Job\QueueName;
use Espo\Modules\FeatureWhatsAppNotifications\Jobs\SendAssistantReply;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Assistant;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * An AI Session reply that just finished (completed, failed or blocked) queues its WhatsApp delivery. The job runs only
 * once the reply committed, and Assistant::reply sends only when the turn came from WhatsApp; send keys per reply keep a
 * replayed status from sending twice.
 */
class MirrorAssistantReply implements AfterSave
{
    public function __construct(private EntityManager $em) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->get('parentType') !== 'AiSession' || !$entity->isAttributeChanged('data')) return;
        $status = $entity->get('data')->opportunityStreamAgent->status ?? null;
        $before = $entity->isNew() ? null : ($entity->getFetched('data')->opportunityStreamAgent->status ?? null);
        if (!in_array($status, Assistant::ANSWERED, true) || $status === $before) return;
        $source = $this->em->getEntityById('Note', (string) ($entity->get('data')->opportunityStreamAgent->sourceNoteId ?? ''));
        if (($source?->get('data')->aiSessionChannel ?? null) !== Assistant::CHANNEL) return;
        $this->em->createEntity('Job', [
            'name' => SendAssistantReply::class, 'className' => SendAssistantReply::class, 'queue' => QueueName::Q0,
            'attempts' => 2, 'data' => (object) ['noteId' => $entity->getId()],
        ]);
    }
}
