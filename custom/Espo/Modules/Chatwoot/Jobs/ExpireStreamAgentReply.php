<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Entities\Note;
use Espo\Modules\Chatwoot\Services\StreamAgentProgress;
use Espo\ORM\EntityManager;

class ExpireStreamAgentReply implements Job
{
    public function __construct(private EntityManager $em) {}

    public function run(Data $data): void
    {
        $this->em->getTransactionManager()->run(function () use ($data): void {
            // Same lock order as claim/reply/status, so a completed reply cannot be overwritten.
            $this->em->getRDBRepository('Note')->where(['id' => $data->get('sourceNoteId')])->forUpdate()->findOne();
            $reply = $this->em->getEntityById('Note', $data->get('noteId'));
            if (!$reply instanceof Note || !StreamAgentProgress::isPending($reply)) return;
            $metadata = $reply->getData();
            if (($metadata->opportunityStreamAgent->workflowRunId ?? null) !== $data->get('workflowRunId')) return;
            $metadata->opportunityStreamAgent->status = 'failed';
            unset($metadata->opportunityStreamAgent->draft);
            $metadata->opportunityStreamAgent->finishedAt = gmdate('Y-m-d\TH:i:s\Z');
            $reply->setData($metadata);
            $reply->setPost('This request timed out. Check the workflow before requesting another execution.');
            $this->em->saveEntity($reply);
        });
    }
}
