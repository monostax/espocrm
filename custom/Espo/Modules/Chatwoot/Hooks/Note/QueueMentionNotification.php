<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Note;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Job\QueueName;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Jobs\BroadcastFeedMention;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

class QueueMentionNotification implements AfterSave
{
    public static int $order = 99;

    public function __construct(private EntityManager $em, private User $user) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->get('type') !== Note::TYPE_POST ||
            !in_array($entity->get('parentType'), ['Opportunity', ...ActivityDiscussion::PARENT_TYPES], true) ||
            (!$entity->isNew() && !$entity->isAttributeChanged('post'))) return;

        // The before-save resolver has already checked record access and expanded teams.
        $recipients = array_values(array_diff(
            $entity->get('opportunityMentionUserIds') ?? [],
            $entity->isNew() ? [] : ($entity->getFetched('opportunityMentionUserIds') ?? []),
            [$this->user->getId()],
        ));
        if (!$recipients) return;

        $this->em->createEntity('Job', [
            'name' => BroadcastFeedMention::class, 'className' => BroadcastFeedMention::class,
            'queue' => QueueName::Q0, 'attempts' => 3,
            'data' => (object) ['noteId' => $entity->getId(), 'userIds' => $recipients],
        ]);
    }
}
