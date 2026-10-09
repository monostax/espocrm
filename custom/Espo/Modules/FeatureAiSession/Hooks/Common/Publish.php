<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Hooks\Common;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Job\QueueName;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

class Publish implements AfterSave
{
    public static int $order = 99;
    public function __construct(private EntityManager $em) {}
    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        $id = $entity->getEntityType() === 'AiSession' ? $entity->getId()
            : ($entity->get('parentType') === 'AiSession' ? $entity->get('parentId') : null);
        if (!$id) return;
        $this->em->createEntity('Job', [
            'name' => \Espo\Modules\FeatureAiSession\Services\Broadcast::class,
            'className' => \Espo\Modules\FeatureAiSession\Services\Broadcast::class,
            'queue' => \Espo\Modules\Chatwoot\Tools\Stream\DispatchQueue::notification($entity),
            'attempts' => 3, 'data' => (object) ['id' => $id],
        ]);
    }
}
