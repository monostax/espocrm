<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Scripts;

use Espo\Core\Container;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Modules\FeatureRecordKnowledge\Services\Overviews;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;

/** Durable cursor per scope; a failed record never advances the checkpoint. */
class Backfill
{
    public function run(Container $container): void
    {
        $factory = $container->get('injectableFactory');
        $em = $container->get('entityManager');
        $config = $container->get('config');
        $writer = $factory->create(ConfigWriter::class);
        $service = $factory->create(Overviews::class);
        $cursors = getenv('RECORD_KNOWLEDGE_BACKFILL_RESET') === '1' ? [] : (array) ($config->get('recordKnowledgeBackfillCursors') ?? []);
        $batches = max(1, (int) (getenv('RECORD_KNOWLEDGE_BACKFILL_BATCHES') ?: 10));
        foreach ($factory->create(Scopes::class)->all() as $type) {
            $after = $cursors[$type] ?? '';
            for ($batch = 0; $batch < $batches; $batch++) {
                $rows = $em->getRDBRepository($type)->where(['id>' => $after])->order('id')->limit(0, 100)->find();
                $count = 0;
                foreach ($rows as $record) {
                    $service->ensure($record);
                    $after = $record->getId();
                    $count++;
                }
                $cursors[$type] = $after;
                $writer->set('recordKnowledgeBackfillCursors', $cursors);
                $writer->save();
                if ($count < 100) break;
            }
            if ($after !== '') echo "$type: $after\n";
        }
    }
}
