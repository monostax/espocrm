<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Controllers;

use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Global\Controllers\RecordNextAction;
use Espo\ORM\Entity;

class InitiativeNextAction extends RecordNextAction
{
    protected function entityType(): string
    {
        return 'Initiative';
    }

    protected function taskTeamIds(Entity $record): array
    {
        // Initiatives inherit ownership from their live type rather than storing teams.
        /** @var ?\Espo\Core\ORM\Entity $type */
        $type = $this->entityManager->getEntityById('InitiativeType', (string) $record->get('initiativeTypeId'));
        if (!$type) {
            throw new NotFound();
        }

        return $type->getLinkMultipleIdList('teams');
    }
}
