<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Scripts;

use Espo\Core\Container;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\ReferenceIndex;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;

/** Run while writes are paused; per-record transactions make retries idempotent. */
class RebuildReferences
{
    public function run(Container $container): void
    {
        $em = $container->get('entityManager');
        $index = $container->get('injectableFactory')->create(ReferenceIndex::class);
        // Clear orphaned/deleted source rows as well as stale content references.
        $em->getQueryExecutor()->execute($em->getQueryBuilder()->delete()->from('EditorReferenceIndex')->build());
        foreach (References::FIELDS as $type => $fields) {
            $after = '';
            do {
                $page = $em->getRDBRepository($type)->where(['id>' => $after])->order('id')->limit(0, 100)->find();
                $count = 0;
                foreach ($page as $entity) {
                    $em->getTransactionManager()->run(fn () => $index->replace($entity));
                    $after = $entity->getId();
                    $count++;
                }
            } while ($count === 100);
        }
    }
}
