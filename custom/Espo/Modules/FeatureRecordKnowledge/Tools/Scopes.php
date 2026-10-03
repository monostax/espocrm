<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Tools;

use Espo\Core\Utils\Metadata;
use stdClass;

/** One merged-metadata contract for provisioning, references and client panels. */
class Scopes
{
    public function __construct(private Metadata $metadata) {}

    public static function discover(stdClass $data): array
    {
        $types = [];
        foreach ($data->scopes ?? new stdClass() as $type => $scope) {
            // Persistent tab-facing objects opt in automatically. Hidden technical
            // scopes need an explicit metadata opt-in rather than a type-name list.
            $enabled = $scope->recordKnowledge ?? (($scope->object ?? false) && ($scope->tab ?? false));
            if (!$enabled || !($scope->entity ?? false) || ($scope->disabled ?? false) || ($scope->isVirtual ?? false) ||
                ($data->entityDefs->$type->skipRebuild ?? false) ||
                !isset($data->entityDefs->$type->fields->name) ||
                !preg_match('/^[A-Z][a-zA-Z0-9]{0,63}$/D', $type)) continue;
            $types[] = $type;
        }
        sort($types);
        return $types;
    }

    public function all(): array
    {
        return self::discover($this->metadata->getAll());
    }

    public function supports(string $type): bool { return in_array($type, $this->all(), true); }
}
