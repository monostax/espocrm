<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Hooks\Lead;

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

        if (
            !$entity->isNew() &&
            !$entity->isAttributeChanged('status') &&
            !$entity->isAttributeChanged('assignedUserId') &&
            !$entity->isAttributeChanged('source')
        ) {
            return;
        }

        $this->dispatcher->dispatchEntityChange(
            $entity->getEntityType(),
            $entity->getId(),
            $entity->isNew() ? 'create' : 'update',
            [
                'status' => $entity->get('status'),
                'source' => $entity->get('source'),
                'assignedUserId' => $entity->get('assignedUserId'),
            ],
        );
    }
}
