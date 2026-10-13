<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Classes\Metadata;

use Espo\Core\Utils\Metadata\AdditionalBuilder;
use Espo\Modules\FeatureLiveRecords\Scope;
use Espo\Modules\FeatureLiveRecords\Persistence\Observer;
use stdClass;

final class LiveRecords implements AdditionalBuilder
{
    public function build(stdClass $data): void
    {
        $enabled = static fn (string $type): bool => isset($data->entityDefs->$type) &&
            !($data->scopes->$type->disabled ?? false) && !($data->scopes->$type->isVirtual ?? false) &&
            !($data->entityDefs->$type->skipRebuild ?? false);
        $records = array_values(array_filter(Scope::RECORD_TYPES, $enabled));
        foreach (Scope::all() as $type) {
            if (!$enabled($type)) continue;
            $defs = $data->entityDefs->$type;
            $defs->mutationObserverClassName = Observer::class;
            $defs->transactionalSave = true;
            if (in_array($type, [...$records, 'Note', 'Document', 'ContactChannelIdentity', 'CrmTag',
                'Funnel', 'OpportunityStage', 'InitiativeType', 'Journey'], true)) {
                $defs->optimisticConcurrencyControl = true;
                $defs->atomicConcurrencyControl = true;
            }
        }
        $data->app ??= new stdClass();
        $data->app->liveRecords = (object) [
            'recordTypes' => $records,
            'dependencyTypes' => array_values(array_filter(Scope::DEPENDENCIES, $enabled)),
            'sharedTypes' => array_values(array_filter(Scope::SHARED, $enabled)),
            'accessTypes' => array_values(array_filter(Scope::ACCESS, $enabled)),
            'protocolVersion' => 1,
        ];
    }
}
