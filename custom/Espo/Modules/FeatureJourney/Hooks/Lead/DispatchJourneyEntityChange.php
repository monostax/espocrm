<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\Lead;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\FeatureJourney\Services\EntityChangeDispatcher;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/** @implements AfterSave<Entity> */
class DispatchJourneyEntityChange implements AfterSave
{
    public static int $order = 60;

    public function __construct(
        private EntityChangeDispatcher $dispatcher,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('skipJourneyDispatch')) {
            return;
        }

        try {
            $this->dispatcher->dispatch($entity);
        } catch (Throwable) {
        }
    }
}
