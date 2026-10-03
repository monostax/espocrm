<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Hooks\Task;

use Espo\Modules\FeatureTaskRecurrence\Services\Recurrence as Service;
use Espo\ORM\Entity;

class Recurrence
{
    public static int $order = 20;

    public function __construct(private Service $recurrence) {}

    public function beforeSave(Entity $entity, array $options): void { $this->recurrence->beforeSave($entity); }
    public function afterSave(Entity $entity, array $options): void { $this->recurrence->afterSave($entity); }
    public function beforeRemove(Entity $entity, array $options): void { $this->recurrence->beforeRemove($entity); }
}
