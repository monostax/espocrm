<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Per-conversation fact the Atendimento lane sorts its owner on without scanning messages per request: the
 * last staff member who wrote from the app (an AI agent never counts). One row per conversation, keyed by the
 * conversation id, kept current by the ChatwootMessage hook and filled for recent conversations at rebuild.
 */
class ConversationStates
{
    /** Conversations active in this window are filled at rebuild; the lane's window is the same. */
    private const BACKFILL_DAYS = 30;

    /** The CRM user behind message `cm`'s sender when that is a person in the app, with when they wrote. */
    private const STAFF_SQL = 'SELECT cu.assigned_user_id AS user, cm.chatwoot_created_at AS at FROM chatwoot_message cm '
        . 'JOIN chatwoot_account a ON a.id = cm.chatwoot_account_id '
        . 'JOIN chatwoot_user cu ON cu.chatwoot_user_id = cm.sender_id AND cu.platform_id = a.platform_id '
        . 'AND cu.deleted = 0 AND cu.assigned_user_id IS NOT NULL '
        . "WHERE cm.deleted = 0 AND cm.is_private = 0 AND cm.message_type <> 'incoming' AND cm.sender_type = 'User' "
        . 'AND cu.assigned_user_id NOT IN (' . Ownership::AI_USERS_SQL . ') ';

    public function __construct(private EntityManager $em) {}

    /** A message was written: a person's reply from the app makes them the conversation's last staff writer. */
    public function forMessage(Entity $message): void
    {
        if (!$message->get('conversationId') || $message->get('isPrivate') || $message->get('messageType') === 'incoming'
            || $message->get('senderType') !== 'User') return;
        $query = $this->em->getPDO()->prepare(self::STAFF_SQL . 'AND cm.id = :id');
        $query->execute(['id' => $message->getId()]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if ($row) $this->write((string) $message->get('conversationId'), (string) $row['user'], (string) $row['at']);
    }

    /** Fill conversations active in the window that have no row yet. @return int how many were filled */
    public function backfill(): int
    {
        $ids = $this->em->getPDO()->query(
            'SELECT c.id FROM chatwoot_conversation c LEFT JOIN catch_up_conversation_state s ON s.id = c.id '
            . 'WHERE c.deleted = 0 AND s.id IS NULL AND c.last_activity_at >= UTC_TIMESTAMP() - INTERVAL ' . self::BACKFILL_DAYS . ' DAY'
        )->fetchAll(\PDO::FETCH_COLUMN);
        $latest = $this->em->getPDO()->prepare(self::STAFF_SQL . 'AND cm.conversation_id = :id ORDER BY cm.chatwoot_created_at DESC LIMIT 1');
        $filled = 0;
        foreach ($ids as $id) {
            $latest->execute(['id' => $id]);
            $row = $latest->fetch(\PDO::FETCH_ASSOC);
            $this->write((string) $id, $row ? (string) $row['user'] : null, $row ? (string) $row['at'] : null);
            $filled++;
        }
        return $filled;
    }

    /** Keep the newest writer: a late-synced older reply never replaces a newer one. */
    private function write(string $id, ?string $user, ?string $at): void
    {
        $this->em->getPDO()->prepare(
            'INSERT INTO catch_up_conversation_state (id, deleted, staff_user_id, staff_wrote_at) VALUES (:id, 0, :user, :at) '
            . "ON DUPLICATE KEY UPDATE deleted = 0, staff_user_id = IF(COALESCE(VALUES(staff_wrote_at), '1970-01-01') >= "
            . "COALESCE(staff_wrote_at, '1970-01-01'), VALUES(staff_user_id), staff_user_id), "
            . "staff_wrote_at = GREATEST(COALESCE(staff_wrote_at, '1970-01-01'), COALESCE(VALUES(staff_wrote_at), '1970-01-01'))"
        )->execute(['id' => $id, 'user' => $user, 'at' => $at]);
    }
}
