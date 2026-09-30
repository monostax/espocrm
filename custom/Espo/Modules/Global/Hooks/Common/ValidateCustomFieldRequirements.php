<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\Common;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\Global\Tools\CustomField\Conditions;
use Espo\Modules\Global\Tools\CustomField\MetaProvider;
use Espo\Modules\Global\Tools\Opportunity\StageRequirements;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateCustomFieldRequirements implements BeforeSave
{
    public static int $order = 84;

    public function __construct(private MetaProvider $metaProvider) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        // Opportunities use the completion dialog, including ordinary save requirements.
        if ($entity->getEntityType() === 'Opportunity' || !$this->metaProvider->isEntityEnabled($entity->getEntityType())) {
            return;
        }
        $meta = $this->metaProvider->getGroupedMeta($entity->getEntityType(), $entity->get('tenantId'));
        $bag = (array) $entity->get('customFields');
        foreach ($meta['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if (Conditions::required($field, Conditions::context($entity, [$field])) &&
                    StageRequirements::invalidReason($field, $bag[$field['valueKey']] ?? null) !== null) {
                    throw new BadRequest('Required custom field is missing or invalid: ' . $field['label']);
                }
            }
        }
    }
}
