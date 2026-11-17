<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Hooks\Opportunity;

use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use Espo\Modules\FeatureCatchUp\Services\ConversationCycle;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

class DiscardDealCards implements AfterSave, BeforeRemove
{
    public function __construct(private Verdicts $verdicts, private ConversationCycle $cycle) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isAttributeChanged('status') || !in_array($entity->get('status'), ['Won', 'Lost'], true)) return;
        $this->verdicts->discardDeal($entity->getId());
        if ($entity->get('status') === 'Lost') $this->cycle->discardForDeal($entity);
    }

    public function beforeRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->verdicts->discardDeal($entity->getId());
        $this->cycle->discardForDeal($entity);
    }
}
