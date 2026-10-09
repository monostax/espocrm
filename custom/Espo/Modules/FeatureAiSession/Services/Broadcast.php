<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Services;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\EntityManager;

class Broadcast implements Job
{
    public function __construct(private EntityManager $em, private Access $access, private ChatwootApiClient $client, private \Espo\Core\AclManager $acl) {}
    public function run(Data $data): void
    {
        $session = $this->em->getEntityById('AiSession', (string) $data->get('id'));
        $owner = $session ? $this->em->getEntityById('User', (string) $session->get('assignedUserId')) : null;
        if (!$session || !$owner instanceof User || !$this->access->owner($owner, $session)) return;
        if (!$this->acl->checkEntityRead($owner, $session) || !$this->acl->checkEntityStream($owner, $session)) return;
        $account = $this->em->getEntityById('ChatwootAccount', $session->get('chatwootAccountId'));
        $platform = $this->em->getEntityById('ChatwootPlatform', $account->get('platformId'));
        if (!$platform?->get('backendUrl') || !$account->get('apiKey')) return;
        foreach ($this->em->getRDBRepository('ChatwootAccountUserMembership')->join('chatwootUser')->where([
            'chatwootAccountId' => $account->getId(), 'chatwootUser.assignedUserId' => $owner->getId(),
            'chatwootUser.deleted' => false,
            'chatwootUser.platformId' => $account->get('platformId'),
        ])->find() as $membership) {
            $identity = $this->em->getEntityById('ChatwootUser', $membership->get('chatwootUserId'));
            $this->client->notifyActivityUpdate($platform->get('backendUrl'), $account->get('apiKey'), (int) $account->get('chatwootAccountId'), [
                'entityType' => 'AiSession', 'ownerUserId' => (int) $identity->get('chatwootUserId'),
            ]);
        }
    }
}
