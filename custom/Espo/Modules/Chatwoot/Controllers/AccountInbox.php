<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Record\ServiceContainer;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Services\AccountInbox as Inbox;
use Espo\Tools\Stream\MassNotePreparator;

class AccountInbox extends ContactInbox
{
    protected const ENTITY_TYPE = 'Account';

    public function __construct(Inbox $inbox, ActivityDiscussion $discussion, ServiceContainer $records, MassNotePreparator $preparator)
    {
        parent::__construct($inbox, $discussion, $records, $preparator);
    }
}
