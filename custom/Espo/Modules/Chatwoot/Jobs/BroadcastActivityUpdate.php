<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\WebSocket\Submission;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\EntityManager;
use Throwable;

class BroadcastActivityUpdate implements Job
{
    public function __construct(private EntityManager $em, private Submission $socket, private ChatwootApiClient $client) {}
    public function run(Data $data): void
    {
        $this->socket->submit('recordUpdate.' . $data->get('type') . '.' . $data->get('id'));
        $this->socket->submit('streamUpdate.' . $data->get('type') . '.' . $data->get('id'));
        $failure = null;
        foreach ($this->em->getRDBRepository('ChatwootAccount')->where(['tenantId' => $data->get('tenantIds')])->find() as $account) {
            $platform = $this->em->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));
            if (!$platform?->get('backendUrl') || !$account->get('apiKey') || !$account->get('chatwootAccountId')) continue;
            try {
                $this->client->notifyActivityUpdate($platform->get('backendUrl'), $account->get('apiKey'), (int) $account->get('chatwootAccountId'));
            } catch (Throwable $e) { $failure = $e; }
        }
        if ($failure) throw $failure;
    }
}
