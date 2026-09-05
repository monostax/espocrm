<?php

namespace Espo\Modules\Chatwoot\Hooks\Note;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\Note;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\Modules\Chatwoot\Services\OpportunityPostMentions;

class NormalizeOpportunityMentions implements BeforeSave
{
    // Native Espo mentions are populated at order 9.
    public static int $order = 20;

    public function __construct(private OpportunityPostMentions $mentions) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->get('parentType') === 'Opportunity' && $entity->get('type') === Note::TYPE_POST &&
            ($entity->isNew() || $entity->isAttributeChanged('post'))) {
            assert($entity instanceof Note);
            $entity->set('opportunityMentionUserIds', $this->mentions->resolve($entity));
        }
    }
}
