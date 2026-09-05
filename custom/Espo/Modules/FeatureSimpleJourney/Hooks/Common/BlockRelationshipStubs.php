<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Hooks\Common;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Modules\FeatureSimpleJourney\Services\ValidationError;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Espo accepts hasMany stubs on create even without a linkMultiple field.
 * LinkMultipleSaver can reparent existing children without saving those children.
 * Block both IDs and column-only payloads before the API or ORM can persist them.
 */
class BlockRelationshipStubs implements BeforeSave, SaveHook
{
    public static int $order = -10;

    private const LINKS = [
        'SimpleJourney' => ['stages', 'records'],
        'SimpleJourneyStage' => ['records'],
        'SimpleJourneyRecord' => ['parents'],
    ];

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->process($entity);
    }

    public function process(Entity $entity): void
    {
        if (!$entity->isNew()) {
            return; // Espo does not process these stubs on updates.
        }

        foreach (self::LINKS[$entity->getEntityType()] ?? [] as $link) {
            foreach (['Ids', 'Columns'] as $suffix) {
                $value = $entity->get($link . $suffix);

                if ($value === null || $value === [] || (is_object($value) && (array) $value === [])) {
                    continue;
                }

                throw ValidationError::badRequest(
                    'relationshipStubsDisabled',
                    'Create or update children through their journey, stage or record fields, not relationship ID lists.',
                );
            }
        }
    }
}
