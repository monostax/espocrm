<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * What Em dia already concluded about one Chatwoot conversation, for the conversation sidebar (gtm/05 §15):
 * what's agreed and the promises still open, from each open deal of the conversation, or from the
 * conversation itself when it has none. Only stored records are read; nothing is analysed here, so opening a
 * conversation costs no credits and no model call. A record is read under the reader's own source scope
 * (gtm/05 §13), so it was built only from conversations the reader may open.
 */
class ConversationRecord
{
    /** Open deals of one conversation shown, newest first. */
    private const DEALS = 3;

    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private User $user,
        private Workspace $workspace,
        private Feed $feed,
        private Transcript $transcript,
        private Records $records,
    ) {}

    /** @return array{records: list<array<string, mixed>>} */
    public function read(Request $request): array
    {
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) || !$this->acl->checkScope('ChatwootConversation', 'read')) throw new Forbidden();
        $display = (string) $request->getQueryParam('conversationId');
        if (!ctype_digit($display) || (int) $display < 1) throw new BadRequest('Invalid conversation.');
        ['tenant' => $tenant, 'account' => $account] = $this->workspace->account($request);
        $conversation = $this->conversation($account, (int) $display);
        // The record is made of the conversation's messages: the reader must be able to read them.
        if (!$conversation || !$this->acl->checkEntityRead($conversation) || !$this->transcript->readable($conversation)) return ['records' => []];
        $records = [];
        foreach ($this->deals($conversation, $tenant->getId()) as $deal) {
            $record = $this->records->load('Opportunity', $deal->getId(), Records::scope(array_keys($this->feed->conversations($deal))));
            if ($record) $records[] = self::shown('opportunity', $deal->getId(), (string) $deal->get('name'), $record);
        }
        if (!$records) {
            $id = $conversation->getId();
            $record = $this->records->load('ChatwootConversation', $id, Records::scope([$id]));
            if ($record) $records[] = self::shown('conversation', $id, null, $record);
        }
        return ['records' => $records];
    }

    private function conversation(Entity $account, int $display): ?Entity
    {
        $query = $this->em->getPDO()->prepare('SELECT id FROM chatwoot_conversation WHERE deleted = 0 '
            . 'AND chatwoot_account_id = :account AND chatwoot_conversation_id = :display LIMIT 1');
        $query->execute(['account' => $account->getId(), 'display' => $display]);
        $id = $query->fetchColumn();
        return is_string($id) && $id !== '' ? $this->em->getEntityById('ChatwootConversation', $id) : null;
    }

    /** @return list<Entity> open deals of this tenant linked to the conversation that the reader may read, newest first */
    private function deals(Entity $conversation, string $tenantId): array
    {
        if (!$this->acl->checkScope('Opportunity', 'read')) return [];
        $query = $this->em->getPDO()->prepare('SELECT o.id FROM chatwoot_conversation_opportunity r '
            . 'JOIN opportunity o ON o.id = r.opportunity_id AND o.deleted = 0 '
            . "WHERE r.deleted = 0 AND r.chatwoot_conversation_id = :conversation AND o.tenant_id = :tenant AND o.status NOT IN ('Won','Lost') "
            . 'ORDER BY o.created_at DESC LIMIT ' . self::DEALS);
        $query->execute(['conversation' => $conversation->getId(), 'tenant' => $tenantId]);
        $deals = [];
        foreach ($query->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $deal = $this->em->getEntityById('Opportunity', (string) $id);
            if ($deal && $this->acl->checkEntityRead($deal)) $deals[] = $deal;
        }
        return $deals;
    }

    /**
     * What the panel shows: what's agreed, with up to two earlier values, and the promises still open. Kept
     * and dropped promises stay in the CRM.
     *
     * @param array{agreed: list<mixed>, promises: list<mixed>, coveredAt: ?string} $record
     */
    private static function shown(string $type, string $id, ?string $name, array $record): array
    {
        $agreed = [];
        foreach ($record['agreed'] as $item) {
            if (!is_array($item) || !isset($item['key'], $item['value'])) continue;
            $was = array_values(array_filter(is_array($item['was'] ?? null) ? $item['was'] : [], fn ($old) => is_array($old) && isset($old['value'])));
            $agreed[] = ['key' => (string) $item['key'], 'value' => (string) $item['value'], 'messageId' => self::id($item['messageId'] ?? null),
                'was' => array_map(fn ($old) => ['value' => (string) $old['value'], 'messageId' => self::id($old['messageId'] ?? null)], array_slice($was, 0, 2))];
        }
        $promises = [];
        foreach ($record['promises'] as $promise) {
            if (!is_array($promise) || ($promise['status'] ?? 'open') !== 'open' || !isset($promise['text'])) continue;
            $promises[] = ['side' => ($promise['side'] ?? null) === 'theirs' ? 'theirs' : 'ours', 'text' => (string) $promise['text'],
                'dueAt' => is_string($promise['dueAt'] ?? null) ? $promise['dueAt'] : null, 'messageId' => self::id($promise['openedBy'] ?? null)];
        }
        return ['subject' => ['type' => $type, 'id' => $id, 'name' => $name], 'agreed' => $agreed, 'promises' => $promises,
            'coveredAt' => $record['coveredAt'] ?? null];
    }

    /** A Chatwoot message id the panel can scroll to, or null. */
    private static function id(mixed $value): ?string
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (string) (int) $value : null;
    }
}
