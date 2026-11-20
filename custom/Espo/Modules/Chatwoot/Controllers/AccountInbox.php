<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Record\ServiceContainer;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Services\AccountInbox as Inbox;
use Espo\Modules\Chatwoot\Services\OpportunityOverdueActivities;
use Espo\Tools\Stream\MassNotePreparator;

class AccountInbox extends ContactInbox
{
    protected const ENTITY_TYPE = 'Account';

    public function __construct(Inbox $inbox, ActivityDiscussion $discussion, ServiceContainer $records, MassNotePreparator $preparator,
        private OpportunityOverdueActivities $activities, private Acl $acl)
    {
        parent::__construct($inbox, $discussion, $records, $preparator);
    }

    public function getActionStream(Request $request): object
    {
        $page = parent::getActionStream($request);
        $this->activities->decorateAccountPage($page->list, $request->getRouteParam('id'), $this->acl);
        return $page;
    }
}
