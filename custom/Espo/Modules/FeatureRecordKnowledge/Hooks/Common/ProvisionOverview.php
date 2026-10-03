<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Hooks\Common;

use Espo\ORM\Entity;
use Espo\Modules\FeatureRecordKnowledge\Services\Overviews;

class ProvisionOverview
{
    public static int $order = 100;
    public function __construct(private Overviews $overviews) {}
    public function afterSave(Entity $entity, array $options): void { $this->overviews->saved($entity); }
    public function afterRemove(Entity $entity, array $options): void { $this->overviews->removed($entity); }
}
