<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\Global\Controllers\RecordNextAction;
use Espo\ORM\Entity;

class OpportunityNextAction extends RecordNextAction
{
    protected function entityType(): string
    {
        return 'Opportunity';
    }

    protected function checkMutable(Entity $record): void
    {
        if ($record->get('status') !== 'Open') {
            throw new Conflict('The opportunity is closed.');
        }
    }
}
