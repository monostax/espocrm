<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Core\Acl;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Which conversations the reader may open, as Chatwoot decides (gtm/05 §13). Everyone, tenant admins included,
 * gets only conversations Chatwoot's inbox rules let them open. The CRM's ChatwootConversation ACL is not
 * enough: it mirrors inbox membership only and gives a Chatwoot global admin every inbox of the account, while
 * Chatwoot lets a global admin open only the inboxes they belong to (several did not belong to all of them on
 * 2026-11-10), adds access through teams and participation, and narrows it for custom roles. So every Em dia
 * list, card, count and sidebar asks Chatwoot here, after the CRM ACL.
 *
 * It fails closed: a conversation Chatwoot can't answer for is not allowed. Answers are kept for the request.
 */
class ChatwootAccess
{
    /** Display ids per call; the Chatwoot endpoint accepts at most 200. */
    private const BATCH = 200;

    /** @var array<string, bool> CRM conversation id => the reader may open it */
    private array $known = [];
    /** @var array<string, ?int> CRM platform id => the reader's Chatwoot user id there */
    private array $users = [];

    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private User $user,
        private ChatwootApiClient $api,
        private Log $log,
    ) {}

    /**
     * @param array<string, Entity> $conversations CRM ChatwootConversation entities keyed by id
     * @return array<string, Entity> those the reader may open in Chatwoot, in the same order
     */
    public function filter(array $conversations): array
    {
        $byAccount = [];
        foreach ($conversations as $id => $conversation) {
            if (!array_key_exists((string) $id, $this->known)) $byAccount[(string) $conversation->get('chatwootAccountId')][(string) $id] = $conversation;
        }
        foreach ($byAccount as $accountId => $list) $this->ask($accountId, $list);
        return array_filter($conversations, fn ($id) => $this->known[(string) $id] ?? false, ARRAY_FILTER_USE_KEY);
    }

    public function allows(Entity $conversation): bool
    {
        return $this->filter([$conversation->getId() => $conversation]) !== [];
    }

    /**
     * The deals with at least one linked conversation the reader may open, in the CRM and in Chatwoot.
     *
     * @param list<string> $dealIds
     * @return array<string, true>
     */
    public function dealsWithOpenConversation(array $dealIds): array
    {
        if (!$dealIds) return [];
        $marks = implode(',', array_fill(0, count($dealIds), '?'));
        $query = $this->em->getPDO()->prepare('SELECT r.opportunity_id, r.chatwoot_conversation_id FROM chatwoot_conversation_opportunity r '
            . "JOIN chatwoot_conversation c ON c.id = r.chatwoot_conversation_id AND c.deleted = 0 WHERE r.deleted = 0 AND r.opportunity_id IN ($marks)");
        $query->execute(array_values($dealIds));
        $links = $query->fetchAll(\PDO::FETCH_NUM);
        $open = $this->openConversations(array_values(array_unique(array_column($links, 1))));
        $deals = [];
        foreach ($links as [$dealId, $conversationId]) if (isset($open[$conversationId])) $deals[(string) $dealId] = true;
        return $deals;
    }

    /**
     * @param list<string> $conversationIds CRM conversation ids
     * @return array<string, true> those the reader may open, in the CRM and in Chatwoot
     */
    public function openConversations(array $conversationIds): array
    {
        if (!$conversationIds) return [];
        $readable = [];
        foreach ($this->em->getRDBRepository('ChatwootConversation')->where(['id' => array_values($conversationIds)])->find() as $conversation) {
            if ($this->acl->checkEntityRead($conversation)) $readable[$conversation->getId()] = $conversation;
        }
        return array_fill_keys(array_keys($this->filter($readable)), true);
    }

    /** @param array<string, Entity> $conversations of one CRM ChatwootAccount */
    private function ask(string $accountId, array $conversations): void
    {
        foreach (array_keys($conversations) as $id) $this->known[$id] = false;
        $account = $accountId !== '' ? $this->em->getEntityById('ChatwootAccount', $accountId) : null;
        $platform = $account ? $this->em->getEntityById('ChatwootPlatform', (string) $account->get('platformId')) : null;
        if (!$account || !$platform || !$platform->get('backendUrl') || !$platform->get('accessToken')) return;
        $userId = $this->chatwootUserId($platform->getId());
        if ($userId === null) return;
        $byDisplay = [];
        foreach ($conversations as $id => $conversation) {
            $display = (int) $conversation->get('chatwootConversationId');
            if ($display > 0) $byDisplay[$display][] = $id;
        }
        foreach (array_chunk(array_keys($byDisplay), self::BATCH) as $chunk) {
            try {
                $allowed = $this->api->getAccessibleConversationIds((string) $platform->get('backendUrl'), (string) $platform->get('accessToken'),
                    (int) $account->get('chatwootAccountId'), $userId, $chunk);
            } catch (\Throwable $error) {
                $this->log->warning('Em dia: Chatwoot conversation access unavailable; nothing shown from these conversations.',
                    ['account' => $accountId, 'error' => $error->getMessage()]);
                continue;
            }
            foreach ($allowed as $display) foreach ($byDisplay[$display] ?? [] as $id) $this->known[$id] = true;
        }
    }

    /** The reader's Chatwoot user on a platform (ChatwootUser.assignedUserId); without one, Chatwoot shows them nothing. */
    private function chatwootUserId(string $platformId): ?int
    {
        if (array_key_exists($platformId, $this->users)) return $this->users[$platformId];
        $query = $this->em->getPDO()->prepare('SELECT chatwoot_user_id FROM chatwoot_user WHERE deleted = 0 '
            . 'AND assigned_user_id = :user AND platform_id = :platform AND chatwoot_user_id IS NOT NULL LIMIT 1');
        $query->execute(['user' => $this->user->getId(), 'platform' => $platformId]);
        $id = $query->fetchColumn();
        return $this->users[$platformId] = $id !== false && (int) $id > 0 ? (int) $id : null;
    }
}
