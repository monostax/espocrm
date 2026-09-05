<?php

namespace Espo\Modules\Chatwoot\Hooks\Note;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Entities\Note;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;

class TouchOpportunityReadState implements AfterSave
{
    public function __construct(
        private OpportunityReadStateService $readStateService
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew() || $entity->get('type') !== Note::TYPE_POST) {
            return;
        }

        if (
            $entity->get('parentType') === 'Opportunity' &&
            $entity->get('parentId') &&
            $entity->get('createdById')
        ) {
            assert($entity instanceof Note);
            $this->readStateService->recordPost($entity);
        }
    }
}
