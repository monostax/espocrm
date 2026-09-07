<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\Attachment;

use Espo\Core\Select\Applier\AdditionalApplier;
use Espo\Core\Select\SearchParams;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAttachmentAccess;
use Espo\ORM\Query\SelectBuilder;

class OpportunityAccess implements AdditionalApplier
{
    public function __construct(private User $user, private OpportunityAttachmentAccess $access) {}

    public function apply(SelectBuilder $queryBuilder, SearchParams $searchParams): void
    {
        $queryBuilder->where($this->access->where($this->user));
    }
}
