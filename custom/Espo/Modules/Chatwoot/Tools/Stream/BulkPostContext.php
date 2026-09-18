<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

/** Request-local context shared with Note/read-state hooks; never accepts client data. */
class BulkPostContext
{
    public ?string $opportunityId = null;
}
