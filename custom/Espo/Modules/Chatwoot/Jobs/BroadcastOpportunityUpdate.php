<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\WebSocket\Submission;

/** Retain the original CRM UI's record/stream topics, including personal read cutoffs. */
class BroadcastOpportunityUpdate implements Job
{
    public function __construct(private Submission $socket) {}

    public function run(Data $data): void
    {
        $id = $data->get('opportunityId');
        if (!$id) return;
        $this->socket->submit("recordUpdate.Opportunity.$id", $data->get('userId'));
        $this->socket->submit("streamUpdate.Opportunity.$id", $data->get('userId'));
    }
}
