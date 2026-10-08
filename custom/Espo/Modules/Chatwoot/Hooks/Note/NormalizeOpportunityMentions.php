<?php

namespace Espo\Modules\Chatwoot\Hooks\Note;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\Modules\Chatwoot\Services\OpportunityPostMentions;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;

class NormalizeOpportunityMentions implements BeforeSave
{
    // Native Espo mentions are populated at order 9.
    public static int $order = 20;

    public function __construct(private OpportunityPostMentions $mentions, private User $user) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (in_array($entity->get('parentType'), ['Opportunity', ...ActivityDiscussion::PARENT_TYPES], true) && $entity->get('type') === Note::TYPE_POST &&
            ($entity->isNew() || $entity->isAttributeChanged('post'))) {
            assert($entity instanceof Note);
            $entity->set('opportunityMentionUserIds', $this->mentions->resolve($entity));
            if ($entity->isNew()) {
                // Note.data is server-owned. Keep the author-authorized targets for the async worker.
                $data = $entity->getData();
                // Capture the authenticated posting identity, never client-supplied createdBy.
                $data->opportunityAiInitiatorUserId = $this->user->getId();
                $data->opportunityAiMentionTargets = $this->mentions->resolveAiTargets($entity);
                $entity->setData($data);
            }
        }
    }
}
