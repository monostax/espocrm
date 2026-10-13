<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\WebSocket\Submission;

/** Existing CRM UI topics only. Chatwoot Live Records uses authorized native projections. */
class BroadcastActivityUpdate implements Job
{
    public function __construct(private Submission $socket) {}

    public function run(Data $data): void
    {
        if (!$data->get('type') || !$data->get('id')) return;
        $this->socket->submit('recordUpdate.' . $data->get('type') . '.' . $data->get('id'));
        $this->socket->submit('streamUpdate.' . $data->get('type') . '.' . $data->get('id'));
    }
}
