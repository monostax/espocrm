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
        $panels = [
            (object) ['name' => 'overview', 'label' => 'Overview',
                'view' => 'feature-record-knowledge:views/record/panels/overview'],
            (object) ['name' => 'knowledgeRelations', 'label' => 'Relations',
                'view' => 'feature-record-knowledge:views/record/panels/relations'],
        ];
        $data->app->recordKnowledge = (object) ['supportedScopes' => $types, 'panels' => $panels];
        foreach ($types as $type) {
            $data->entityDefs->$type->transactionalSave = true;
            $record = $data->recordDefs->$type ??= new stdClass();
            if (($record->deletedRestorerClassName ?? Restore::class) !== Restore::class) {
                $record->knowledgeOriginalRestorerClassName = $record->deletedRestorerClassName;
            }
            $record->deletedRestorerClassName = Restore::class;
            $client = $data->clientDefs->$type ??= new stdClass();
            if (!isset($client->recordIconAttribute)) {
                $client->recordIconAttribute = 'recordIcon';
                $data->entityDefs->$type->fields->recordIcon ??= (object) [
                    'type' => 'jsonObject', 'view' => 'global:views/fields/record-icon',
                    'layoutFiltersDisabled' => true, 'layoutMassUpdateDisabled' => true,
                ];
            }
            $bottom = $client->bottomPanels ??= new stdClass();
            foreach (['detail', 'edit'] as $mode) {
                $bottomPanels = array_values(array_filter($bottom->$mode ?? [],
                    fn ($p) => !in_array($p->name ?? null, ['overview', 'knowledgeRelations'], true)));
                if (!array_filter($bottomPanels, fn ($p) => ($p->name ?? null) === 'mentionedIn')) {
                    $bottomPanels[] = (object) ['name' => 'mentionedIn', 'label' => 'Mentioned in',
                        'view' => 'feature-knowledge-base-editor:views/record/panels/mentioned-in', 'order' => 90];
                }
                $bottom->$mode = $bottomPanels;
            }
        }
    }
}
