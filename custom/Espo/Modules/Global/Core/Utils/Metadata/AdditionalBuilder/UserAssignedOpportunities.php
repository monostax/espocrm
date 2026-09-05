<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Core\Utils\Metadata\AdditionalBuilder;

use Espo\Core\Utils\Metadata\AdditionalBuilder;
use stdClass;

class UserAssignedOpportunities implements AdditionalBuilder
{
    public function build(stdClass $data): void
    {
        // The sidebar queries this reverse link. Persisted Custom metadata can disable it,
        // so enable it after merging, using utility to hide it without forbidding queries.
        $link = $data->entityDefs->User->links->assignedOpportunities ??= new stdClass();

        $link->type = 'hasMany';
        $link->entity = 'Opportunity';
        $link->foreign = 'assignedUser';
        $link->disabled = false;
        $link->utility = true;
    }
}
