<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\ORM\EntityManager;
use InvalidArgumentException;

/**
 * Who last acted on a subject's card in Em dia, how and when, so a link to a card someone already took care of (Avisos
 * on WhatsApp) can say so (Linked). Kept on the subject's state row, not on the verdict: the next analysis replaces the
 * verdict, and "Já resolvi" may turn it back into a quiet one (Verdicts::settleAs).
 */
class Acts
{
    /**
     * `reply`: a message sent from the card; `next`: the next step scheduled from it; `done`: "Já resolvi"; `deal`: the
     * found deal created; `label`: the contact labeled not a customer.
     */
    public const ACTIONS = ['reply', 'next', 'done', 'deal', 'label'];

    public function __construct(private EntityManager $em, private DealStates $deals) {}

    public function record(string $type, string $id, string $userId, string $action): void
    {
        if (!in_array($action, self::ACTIONS, true)) throw new InvalidArgumentException("Unknown act $action.");
        $values = ['id' => $id, 'user' => $userId, 'action' => $action];
        if ($type === 'Opportunity') {
            // The row also holds the deal's message facts; one written here first would keep Feed::snapshot from filling them.
            if (!$this->deals->load([$id])) $this->deals->refresh([$id]);
            $this->em->getPDO()->prepare(
                'UPDATE catch_up_deal_state SET acted_by_id = :user, acted_action = :action, acted_at = UTC_TIMESTAMP() WHERE id = :id'
            )->execute($values);
            return;
        }
        $this->em->getPDO()->prepare(
            'INSERT INTO catch_up_conversation_state (id, deleted, acted_by_id, acted_action, acted_at) VALUES (:id, 0, :user, :action, UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE deleted = 0, acted_by_id = VALUES(acted_by_id), acted_action = VALUES(acted_action), acted_at = VALUES(acted_at)'
        )->execute($values);
    }

    /** @return ?array{userId: string, action: string, at: string} the newest act, `at` in UTC ('Y-m-d H:i:s') */
    public function last(string $type, string $id): ?array
    {
        $table = $type === 'Opportunity' ? 'catch_up_deal_state' : 'catch_up_conversation_state';
        $query = $this->em->getPDO()->prepare(
            "SELECT acted_by_id, acted_action, acted_at FROM $table WHERE id = :id AND deleted = 0 AND acted_at IS NOT NULL"
        );
        $query->execute(['id' => $id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return $row ? ['userId' => (string) $row['acted_by_id'], 'action' => (string) $row['acted_action'], 'at' => (string) $row['acted_at']] : null;
    }
}
