<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Per-deal conversation facts the deck sorts and filters on without scanning messages per request:
 * who spoke last, when, and the last staff member who wrote from the app. One row per opportunity,
 * keyed by the opportunity id, kept current by the ChatwootMessage hook and filled by the rebuild backfill.
 */
class DealStates
{
    private const PUBLIC = "cm.deleted = 0 AND cm.is_private = 0 AND cm.message_type IN ('incoming','outgoing','template')";

    private const LINKED = 'JOIN chatwoot_conversation_opportunity r ON r.chatwoot_conversation_id = cm.conversation_id '
        . 'AND r.deleted = 0 AND r.opportunity_id = :id';

    public function __construct(private EntityManager $em) {}

    /** A message was written: refresh every open deal its conversation is linked to. */
    public function forMessage(Entity $message): void
    {
        $conversationId = $message->get('conversationId');
        if (!$conversationId || $message->get('isPrivate') || !in_array($message->get('messageType'), ['incoming', 'outgoing', 'template'], true)) return;
        $query = $this->em->getPDO()->prepare(
            'SELECT o.id FROM chatwoot_conversation_opportunity r JOIN opportunity o ON o.id = r.opportunity_id '
            . "AND o.deleted = 0 AND o.status NOT IN ('Won','Lost') WHERE r.deleted = 0 AND r.chatwoot_conversation_id = :conversation"
        );
        $query->execute(['conversation' => $conversationId]);
        $this->refresh($query->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param list<string> $ids */
    public function refresh(array $ids): void
    {
        $pdo = $this->em->getPDO();
        $totals = $pdo->prepare(
            "SELECT MAX(CASE WHEN cm.message_type = 'incoming' THEN cm.chatwoot_created_at END) AS customer, "
            . "MAX(CASE WHEN cm.message_type <> 'incoming' THEN cm.chatwoot_created_at END) AS business, "
            . 'MAX(cm.chatwoot_created_at) AS last, COUNT(*) AS count FROM chatwoot_message cm ' . self::LINKED . ' WHERE ' . self::PUBLIC
        );
        // The newest outgoing message from a person in the app, mapped to their CRM user. AI agents are excluded.
        $staff = $pdo->prepare(
            'SELECT cu.assigned_user_id AS user, cm.chatwoot_created_at AS at FROM chatwoot_message cm ' . self::LINKED . ' '
            . 'JOIN chatwoot_account a ON a.id = cm.chatwoot_account_id '
            . 'JOIN chatwoot_user cu ON cu.chatwoot_user_id = cm.sender_id AND cu.platform_id = a.platform_id '
            . 'AND cu.deleted = 0 AND cu.assigned_user_id IS NOT NULL '
            . 'WHERE ' . self::PUBLIC . " AND cm.message_type <> 'incoming' AND cm.sender_type = 'User' "
            . 'AND cu.assigned_user_id NOT IN (' . Ownership::AI_USERS_SQL . ') '
            . 'ORDER BY cm.chatwoot_created_at DESC LIMIT 1'
        );
        $upsert = $pdo->prepare(
            'INSERT INTO catch_up_deal_state (id, deleted, tenant_id, staff_user_id, staff_wrote_at, last_customer_at, '
            . 'last_business_at, last_message_at, message_count) '
            . 'SELECT o.id, 0, o.tenant_id, :staff, :staffAt, :customer, :business, :last, :count FROM opportunity o WHERE o.id = :id '
            . 'ON DUPLICATE KEY UPDATE deleted = 0, tenant_id = VALUES(tenant_id), staff_user_id = VALUES(staff_user_id), '
            . 'staff_wrote_at = VALUES(staff_wrote_at), last_customer_at = VALUES(last_customer_at), '
            . 'last_business_at = VALUES(last_business_at), last_message_at = VALUES(last_message_at), message_count = VALUES(message_count)'
        );
        foreach (array_unique($ids) as $id) {
            $totals->execute(['id' => $id]);
            $row = $totals->fetch(\PDO::FETCH_ASSOC) ?: [];
            $staff->execute(['id' => $id]);
            $writer = $staff->fetch(\PDO::FETCH_ASSOC) ?: [];
            $upsert->execute([
                'id' => $id, 'staff' => $writer['user'] ?? null, 'staffAt' => $writer['at'] ?? null,
                'customer' => $row['customer'] ?? null, 'business' => $row['business'] ?? null,
                'last' => $row['last'] ?? null, 'count' => (int) ($row['count'] ?? 0),
            ]);
        }
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, mixed>> keyed by opportunity id
     */
    public function load(array $ids): array
    {
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->em->getPDO()->prepare(
            'SELECT id, staff_user_id, staff_wrote_at, last_customer_at, last_business_at, last_message_at, message_count '
            . "FROM catch_up_deal_state WHERE deleted = 0 AND id IN ($marks)"
        );
        $query->execute(array_values($ids));
        $rows = [];
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) $rows[$row['id']] = $row;
        return $rows;
    }

    /** Open deals with a linked conversation and no state row yet. Returns how many were filled. */
    public function backfill(): int
    {
        $ids = $this->em->getPDO()->query(
            "SELECT DISTINCT o.id FROM opportunity o JOIN chatwoot_conversation_opportunity r ON r.opportunity_id = o.id AND r.deleted = 0 "
            . "LEFT JOIN catch_up_deal_state s ON s.id = o.id WHERE o.deleted = 0 AND o.status NOT IN ('Won','Lost') AND s.id IS NULL"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach (array_chunk($ids, 200) as $chunk) $this->refresh($chunk);
        return count($ids);
    }
}
