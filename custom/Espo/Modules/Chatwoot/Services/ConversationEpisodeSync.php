<?php

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Jobs\SyncConversationsFromChatwoot;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;

class ConversationEpisodeSync
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $api,
        private InjectableFactory $factory,
        private Log $log,
    ) {}

    public function syncAccount(Entity $account, int $limit = 100): int
    {
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $account->get('platformId'));
        if (!$platform) {
            throw new RuntimeException('Episode sync requires a Chatwoot platform');
        }
        $connection = [$platform->get('backendUrl'), $account->get('apiKey'), (int) $account->get('chatwootAccountId')];
        $pdo = $this->entityManager->getPDO();
        // A single consumer per account also serializes message membership changes
        // when a reconstructed episode supersedes an earlier one.
        $lockName = 'chatwoot-episodes:' . $account->getId();
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            return 0;
        }
        $synced = 0;
        $after = 0;
        $attempted = 0;
        try {
            do {
                $page = $this->api->episodeRequest(...[...$connection, "?pending=true&after={$after}"]);
                $rows = $page['payload'] ?? [];
                foreach ($rows as $source) {
                    $after = (int) $source['id'];
                    try {
                        $this->syncEpisode($account, $connection, $source);
                        $synced++;
                    } catch (\Throwable $e) {
                        $this->log->warning("Episode sync {$account->getId()}/{$after}: " . $e->getMessage());
                    }
                    if (++$attempted >= $limit) {
                        break 2;
                    }
                }
            } while (count($rows) === 100);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }

        return $synced;
    }

    private function syncEpisode(Entity $account, array $connection, array $source): void
    {
        $sourceId = (int) $source['id'];
        $revision = (int) $source['revision'];
        $superseded = !empty($source['superseded_at']);
        $messages = [];
        $after = 0;
        if (!$superseded) {
            do {
                $page = $this->api->episodeRequest(...[...$connection, "/{$sourceId}/messages?revision={$revision}&after={$after}"]);
                foreach ($page['payload'] ?? [] as $message) {
                    $after = (int) $message['id'];
                    $messages[$after] = $message;
                }
            } while (count($page['payload'] ?? []) === 100);
            if (count($messages) !== (int) $source['message_count']) {
                throw new RuntimeException('Transcript count mismatch; revision remains pending');
            }
        }
        // Every transcript page is revision-qualified; the conditional source
        // acknowledgement catches changes after the last page. Another GET here
        // cannot close that race and adds one request per historical episode.

        $conversation = $this->parentConversation($account, $connection, $source);
        $inbox = $this->entityManager->getRDBRepository('ChatwootInbox')->where([
            'chatwootAccountId' => $account->getId(), 'chatwootInboxId' => (int) $source['inbox_id'],
        ])->findOne();
        if (!$inbox) {
            throw new RuntimeException('Episode inbox has not synchronized yet');
        }
        $teams = $this->entityManager->getRDBRepository('ChatwootAccount')->getRelation($account, 'teams')->find();
        $teamIds = [];
        foreach ($teams as $team) {
            $teamIds[] = $team->getId();
        }
        $this->persistWithRetry(function () use (
            $account, $source, $sourceId, $revision, $superseded, $messages, $conversation, $inbox, $teamIds
        ): void {
            $repo = $this->entityManager->getRDBRepository('ChatwootConversationEpisode');
            $query = $this->entityManager->getQueryBuilder()->select()->from('ChatwootConversationEpisode')->where([
                'chatwootAccountId' => $account->getId(), 'chatwootEpisodeId' => $sourceId,
            ])->withDeleted()->build();
            $episode = $repo->clone($query)->forUpdate()->findOne();
            if ($episode && (int) $episode->get('sourceRevision') > $revision) {
                return;
            }
            if ($episode && $episode->get('deleted') && !$superseded) {
                $repo->restoreDeleted($episode->getId());
            }
            $episode ??= $this->entityManager->getNewEntity('ChatwootConversationEpisode');
            if ($episode->isNew()) {
                $episode->set('id', substr(hash('sha256', 'episode:' . $account->get('platformId') . ':' . $account->getId() . ':' . $sourceId), 0, 17));
            }
            $episode->set([
                'name' => '#' . $source['conversation_display_id'] . ' · ' . $this->timestamp($source['started_at']),
                'chatwootEpisodeId' => $sourceId, 'chatwootAccountId' => $account->getId(),
                'conversationId' => $conversation->getId(), 'inboxId' => $inbox->getId(),
                'chatwootInboxId' => (int) $source['inbox_id'],
                'chatwootConversationId' => (int) $source['conversation_display_id'],
                'tenantId' => $account->get('tenantId'), 'teamsIds' => $teamIds,
                'boundaryPolicy' => $source['boundary_policy'],
                'inactivityTimeoutSeconds' => (int) $source['inactivity_timeout_seconds'],
                'policyVersion' => (int) $source['policy_version'],
                'startedAt' => $this->timestamp($source['started_at']),
                'lastInteractionAt' => $this->timestamp($source['last_interaction_at']),
                'expiresAt' => $this->timestamp($source['expires_at']),
                'closedAt' => $this->timestamp($source['closed_at']),
                'closeReason' => $source['close_reason'], 'origin' => $source['origin'],
                'communicationSpanSeconds' => $source['communication_span_seconds'],
                'elapsedSeconds' => $source['elapsed_seconds'],
                'sourceRevision' => $revision, 'sourceMessageCount' => (int) $source['message_count'],
                'syncedMessageCount' => count($messages), 'transcriptComplete' => !$superseded,
                'lastSyncedAt' => gmdate('Y-m-d H:i:s'), 'deleted' => $superseded,
            ]);
            $this->entityManager->saveEntity($episode, ['silent' => true]);
            $clear = $this->entityManager->getPDO()->prepare(
                'UPDATE chatwoot_message SET conversation_episode_id = NULL, is_episode_interaction = 0, episode_interaction_at = NULL '
                . 'WHERE conversation_episode_id = ?'
            );
            $clear->execute([$episode->getId()]);
            foreach ($messages as $message) {
                $this->saveMessage($account, $conversation, $episode, $message, $teamIds);
            }
        });
        // A crash here merely redelivers the same revision; a new source revision
        // produces HTTP 409 and remains pending. No update can fall behind a cursor.
        $this->api->episodeRequest(...[...$connection, "/{$sourceId}/acknowledge", 'POST', ['revision' => $revision]]);
    }

    private function parentConversation(Entity $account, array $connection, array $source): Entity
    {
        $where = ['chatwootAccountId' => $account->getId(), 'chatwootConversationId' => (int) $source['conversation_display_id']];
        $repo = $this->entityManager->getRDBRepository('ChatwootConversation');
        $conversation = $repo->where($where)->findOne();
        if (!$conversation) {
            $payload = $this->api->getConversation(...[...$connection, (int) $source['conversation_display_id']]);
            $this->factory->create(SyncConversationsFromChatwoot::class)->syncEpisodeParent($account, $payload);
            $conversation = $repo->where($where)->findOne();
        }
        if (!$conversation) {
            throw new RuntimeException('Episode conversation has not synchronized yet');
        }

        return $conversation;
    }

    private function saveMessage(Entity $account, Entity $conversation, Entity $episode, array $source, array $teamIds): void
    {
        $message = $this->entityManager->getRDBRepository('ChatwootMessage')->where([
            'chatwootAccountId' => $account->getId(), 'chatwootMessageId' => (int) $source['id'],
        ])->forUpdate()->findOne() ?? $this->entityManager->getNewEntity('ChatwootMessage');
        $message->set([
            'name' => mb_substr(strip_tags($source['content'] ?? ''), 0, 100) ?: 'Message #' . $source['id'],
            'chatwootAccountId' => $account->getId(), 'chatwootMessageId' => (int) $source['id'],
            'conversationId' => $conversation->getId(), 'conversationEpisodeId' => $episode->getId(),
            'chatwootContactId' => $conversation->get('chatwootContactId'), 'contactId' => $conversation->get('contactId'),
            'content' => $source['content'], 'messageType' => $source['message_type'],
            'contentType' => $source['content_type'], 'status' => $source['status'], 'isPrivate' => false,
            'senderType' => $source['sender_type'], 'senderId' => $source['sender_id'], 'sourceId' => $source['source_id'],
            'chatwootCreatedAt' => $this->timestamp($source['created_at']),
            'chatwootUpdatedAt' => $this->timestamp($source['updated_at']),
            'isEpisodeInteraction' => (bool) $source['qualifying_interaction'],
            'episodeInteractionAt' => $this->timestamp($source['interaction_at']),
            'episodeContentAttributes' => (object) ($source['content_attributes'] ?? []),
            'episodeAttachments' => $source['attachments'] ?? [],
            'teamsIds' => $teamIds, 'lastSyncedAt' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($message, ['silent' => true]);
    }

    private function persistWithRetry(\Closure $work): void
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $this->entityManager->getTransactionManager()->run($work);
                return;
            } catch (\PDOException $e) {
                // MariaDB can invalidate a read when the regular conversation
                // importer updates the same message. Retry the whole local
                // transaction; never acknowledge a partially imported revision.
                if ($attempt >= 2 || !in_array((int) ($e->errorInfo[1] ?? 0), [1020, 1205, 1213], true)) {
                    throw $e;
                }
                usleep(100_000 * ($attempt + 1));
            }
        }
    }

    private function timestamp(?string $value): ?string
    {
        return $value ? (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
    }
}
