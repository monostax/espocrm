<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\Common;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\CustomField\AiWriters;
use Espo\Modules\Global\Tools\CustomField\Conditions;
use Espo\Modules\Global\Tools\CustomField\MetaProvider;
use Espo\Modules\Global\Tools\Opportunity\StageRequirements;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateCustomFieldRequirements implements BeforeSave
{
    public static int $order = 84;

    public function __construct(private MetaProvider $metaProvider, private AiWriters $aiWriters, private User $user) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        // Opportunities use the completion dialog, including ordinary save requirements.
        if ($entity->getEntityType() === 'Opportunity' || !$this->metaProvider->isEntityEnabled($entity->getEntityType())) {
            return;
        }
        $meta = $this->metaProvider->getGroupedMeta($entity->getEntityType(), $entity->get('tenantId'));
        $bag = (array) $entity->get('customFields');
        $ai = null;
        foreach ($meta['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if (!Conditions::required($field, Conditions::context($entity, [$field]))) continue;
                $reason = StageRequirements::invalidReason($field, $bag[$field['valueKey']] ?? null);
                if ($reason === null) continue;
                // An AI agent's save is not refused for a field nobody filled in; a value it doesn't accept still is
                // (user decision, 2026-11-24).
                if ($reason === 'required' && ($ai ??= $this->aiWriters->isAi($this->user->getId()))) continue;
                throw new BadRequest('Required custom field is missing or invalid: ' . $field['label']);
            }
        }
    }
}
