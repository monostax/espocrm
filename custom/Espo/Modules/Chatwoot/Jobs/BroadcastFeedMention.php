<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\EntityManager;
use Throwable;

class BroadcastFeedMention implements Job
{
    public function __construct(private EntityManager $em, private ChatwootApiClient $client) {}

    public function run(Data $data): void
    {
        $note = $this->em->getEntityById('Note', $data->get('noteId'));
        if (!$note) return;
        if ($note->get('parentType') === 'AiSession') return;
        $parent = $this->em->getEntityById($note->get('parentType'), $note->get('parentId'));
        if (!$parent?->get('tenantId')) return;
        $recipients = array_values(array_intersect($data->get('userIds'), $note->get('opportunityMentionUserIds') ?? []));
        if (!$recipients) return;

        $where = ['tenantId' => $parent->get('tenantId')];
        if ($note->get('opportunityChatwootAccountId')) {
            $where['chatwootAccountId'] = $note->get('opportunityChatwootAccountId');
        }
        $failure = null;
        foreach ($this->em->getRDBRepository('ChatwootAccount')->where($where)->find() as $account) {
            $platform = $this->em->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));
            if (!$platform?->get('backendUrl') || !$account->get('apiKey') || !$account->get('chatwootAccountId')) continue;
            $query = $this->em->getQueryBuilder()->select()->from('ChatwootAccountUserMembership')
                ->join('chatwootUser')->distinct()->where([
                    'chatwootAccountId' => $account->getId(),
                    'chatwootUser.platformId' => $account->get('platformId'),
                    'chatwootUser.assignedUserId' => $recipients,
                ])->select([['chatwootUser.chatwootUserId', 'userId']])->build();
            $ids = $this->em->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_COLUMN);
            if (!$ids) continue;
            try {
                $this->client->notifyActivityUpdate(
                    $platform->get('backendUrl'), $account->get('apiKey'), (int) $account->get('chatwootAccountId'),
                    ['mention' => ['id' => $note->getId(), 'userIds' => array_map('intval', $ids)]],
                );
            } catch (Throwable $e) { $failure = $e; }
        }
        if ($failure) throw $failure;
    }
}
