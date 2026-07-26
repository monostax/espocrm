<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Hooks\Opportunity;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\FeatureAutomation\Services\AutomationEventDispatcher;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements AfterSave<\Espo\ORM\Entity> */
class DispatchAutomationEntityChange implements AfterSave
{
    public static int $order = 90;

    public function __construct(
        private AutomationEventDispatcher $dispatcher,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('silent') || $options->get('skipAutomationDispatch')) {
            return;
        }

        // Prefer any important field change; broadens later via definition filters.
        if (
            !$entity->isNew() &&
            !$entity->isAttributeChanged('stage') &&
            !$entity->isAttributeChanged('status') &&
            !$entity->isAttributeChanged('opportunityStageId')
        ) {
            return;
        }

        $this->dispatcher->dispatchEntityChange(
            $entity->getEntityType(),
            $entity->getId(),
            $entity->isNew() ? 'create' : 'update',
            [
                'stage' => $entity->get('stage'),
                'status' => $entity->get('status'),
            ],
        );
    }
}
