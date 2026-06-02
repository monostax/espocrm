<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDatasetSource;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDatasetSource;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Derives the record name from sourceName (falling back to sourceId).
 *
 * @implements BeforeSave<MetaCapiDatasetSource>
 */
class DeriveName implements BeforeSave
{
    public static int $order = 5;

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaCapiDatasetSource) {
            return;
        }

        $sourceName = trim((string) $entity->get('sourceName'));
        $sourceId = trim((string) $entity->get('sourceId'));

        $name = $sourceName !== '' ? $sourceName : $sourceId;

        if ($name !== '') {
            $entity->set('name', $name);
        }
    }
}
