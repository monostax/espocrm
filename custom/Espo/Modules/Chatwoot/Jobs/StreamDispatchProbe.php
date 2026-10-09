<?php
declare(strict_types=1);
namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;

/** Operational queue probe: exercises dispatch without sending a message or invoking AI. */
class StreamDispatchProbe implements Job
{
    public function run(Data $data): void {}
}
