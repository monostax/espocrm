<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Classes\Record;

use Espo\Core\InjectableFactory;
use Espo\Core\Record\Deleted\Restorer;
use Espo\Core\Record\Deleted\DefaultRestorer;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureRecordKnowledge\Services\Overviews;

class Restore implements Restorer
{
    public function __construct(private EntityManager $em, private InjectableFactory $factory,
        private Metadata $metadata, private Overviews $overviews) {}

    public function restore(Entity $entity): void
    {
        $this->em->getTransactionManager()->run(function () use ($entity) {
            $class = $this->metadata->get(['recordDefs', $entity->getEntityType(), 'knowledgeOriginalRestorerClassName']) ?? DefaultRestorer::class;
            $this->factory->create($class)->restore($entity);
            $this->em->refreshEntity($entity);
            $this->overviews->restored($entity);
        });
    }
}
