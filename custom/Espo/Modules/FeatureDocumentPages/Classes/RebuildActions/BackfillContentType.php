<?php

namespace Espo\Modules\FeatureDocumentPages\Classes\RebuildActions;

use Espo\Core\Rebuild\RebuildAction;
use Espo\ORM\EntityManager;

/** Runs after schema rebuild; does not change record timestamps or attachment links. */
class BackfillContentType implements RebuildAction
{
    public function __construct(private EntityManager $entityManager) {}

    public function process(): void
    {
        $query = $this->entityManager->getQueryBuilder()
            ->update()
            ->in('Document')
            ->set(['contentType' => 'File'])
            ->where(['OR' => [['contentType' => null], ['contentType' => '']]])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);
    }
}
