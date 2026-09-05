<?php

namespace Espo\Modules\Chatwoot\Hooks\Opportunity;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;

class AnchorReadState implements AfterSave
{
    public function __construct(private OpportunityReadStateService $service) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (($entity->isNew() || $entity->isAttributeChanged('assignedUserId')) && $entity->get('assignedUserId')) {
            $this->service->anchorAssignee($entity->getId(), $entity->get('assignedUserId'));
        }
    }
}
