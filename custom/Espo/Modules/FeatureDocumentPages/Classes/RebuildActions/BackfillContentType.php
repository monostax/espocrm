<?php

namespace Espo\Modules\FeatureDocumentPages\Classes\RebuildActions;

use Espo\Core\Rebuild\RebuildAction;
use Espo\ORM\EntityManager;

/**
 * Runs after schema rebuild; does not change record timestamps or attachment links.
 *
 * Not run on rebuild since 2026-10-27: existing documents are backfilled;
 * Document.contentType is now required with default 'File'. Kept for
 * manual use; re-add it to app/rebuild.json if needed.
 */
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
