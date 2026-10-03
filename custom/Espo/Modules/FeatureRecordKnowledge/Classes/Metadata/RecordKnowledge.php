<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Classes\Metadata;

use Espo\Core\Utils\Metadata\AdditionalBuilder;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;
use Espo\Modules\FeatureRecordKnowledge\Classes\Record\Restore;
use stdClass;

class RecordKnowledge implements AdditionalBuilder
{
    public function build(stdClass $data): void
    {
        $types = Scopes::discover($data);
        $data->app->recordKnowledge = (object) ['supportedScopes' => $types];
        foreach ($types as $type) {
            $data->entityDefs->$type->transactionalSave = true;
            $record = $data->recordDefs->$type ??= new stdClass();
            if (($record->deletedRestorerClassName ?? Restore::class) !== Restore::class) {
                $record->knowledgeOriginalRestorerClassName = $record->deletedRestorerClassName;
            }
            $record->deletedRestorerClassName = Restore::class;
            $client = $data->clientDefs->$type ??= new stdClass();
            $bottom = $client->bottomPanels ??= new stdClass();
            foreach (['detail', 'edit'] as $mode) {
                $panels = $bottom->$mode ?? [];
                foreach ([
                    ['overview', 'Overview', 'feature-record-knowledge:views/record/panels/overview', 1],
                    ['knowledgeRelations', 'Relations', 'feature-record-knowledge:views/record/panels/relations', 85],
                    ['mentionedIn', 'Mentioned in', 'feature-knowledge-base-editor:views/record/panels/mentioned-in', 90],
                ] as [$name, $label, $view, $order]) {
                    if (array_filter($panels, fn ($p) => ($p->name ?? null) === $name)) continue;
                    $panels[] = (object) compact('name', 'label', 'view', 'order');
                }
                $bottom->$mode = $panels;
            }
        }
    }
}
