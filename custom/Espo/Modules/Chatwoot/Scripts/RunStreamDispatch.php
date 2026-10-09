<?php
declare(strict_types=1);
namespace Espo\Modules\Chatwoot\Scripts;

use Espo\Core\Container;
use Espo\Modules\Chatwoot\Services\StreamDispatchWorker;

class RunStreamDispatch
{
    public function run(Container $container): void
    {
        $container->get('injectableFactory')->create(StreamDispatchWorker::class)
            ->run(getenv('CRM_STREAM_WORKER_QUEUE') ?: 'stream-ai');
    }
}
