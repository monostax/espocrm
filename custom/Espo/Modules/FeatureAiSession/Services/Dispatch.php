<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Services;

use Espo\Core\Job\Job\Data;
use Espo\Core\Job\QueueName;
use Espo\Entities\Note;
use Espo\Modules\Chatwoot\Jobs\DispatchOpportunityStreamAgent;
use Espo\Modules\Chatwoot\Services\StreamAgentProgress;
use Espo\ORM\EntityManager;

/** Enqueue in CRM post order, even when background delivery jobs run out of order. */
class Dispatch extends DispatchOpportunityStreamAgent
{
    public function __construct(private EntityManager $em) {}
    public function run(Data $data): void
    {
        $source = $this->em->getEntityById('Note', (string) $data->get('noteId'));
        if (!$source instanceof Note || $source->getParentType() !== 'AiSession' || $source->get('opportunityPostDeleted')) return;
        foreach ($this->em->getRDBRepository('Note')->where([
            'parentType' => 'AiSession', 'parentId' => $source->getParentId(), 'type' => 'Post',
            'number<' => $source->get('number'), 'opportunityPostDeleted' => false,
        ])->find() as $reply) {
            assert($reply instanceof Note);
            if (!StreamAgentProgress::isPending($reply)) continue;
            $payload = (object) [];
            foreach (['noteId', 'parentType', 'parentId', 'postHash', 'aiAgentMembershipId', 'chatwootAccountCrmId', 'crmTenantId'] as $key) $payload->$key = $data->get($key);
            if ($data->get('queuedAt')) $payload->queuedAt = $data->get('queuedAt');
            $this->em->createEntity('Job', ['name' => self::class, 'className' => self::class,
                'queue' => \Espo\Modules\Chatwoot\Tools\Stream\DispatchQueue::execution(),
                'attempts' => 3, 'executeTime' => gmdate('Y-m-d H:i:s', time() + 5), 'data' => $payload]);
            return;
        }
        parent::run($data);
    }
}
