<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Modules\FeatureCatchUp\Classes\CardGone;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Removing or losing a deal is a decision about the conversation up to that instant. Only new messages reopen it. */
class ConversationCycle
{
    public function __construct(private EntityManager $em) {}

    public const CURRENT_SQL = 'AND (c.catch_up_discarded_at IS NULL OR c.last_message_received_at > c.catch_up_discarded_at '
        . 'OR c.last_message_sent_at > c.catch_up_discarded_at) ';

    public static function requireCurrent(Entity $conversation): void
    {
        $cutoff = $conversation->get('catchUpDiscardedAt');
        if ($cutoff && max($conversation->get('lastMessageReceivedAt'), $conversation->get('lastMessageSentAt')) <= $cutoff) {
            throw new CardGone();
        }
    }

    /** A new cycle can't inherit the previous cycle's open promises. Keep the existing scope for untouched chats. */
    public static function scope(Entity $conversation): string
    {
        $scope = Records::scope([$conversation->getId()]);
        $cutoff = $conversation->get('catchUpDiscardedAt');
        return $cutoff ? hash('sha256', "$scope:$cutoff") : $scope;
    }

    public function discardForDeal(Entity $deal): void
    {
        $rows = $this->em->getPDO()->prepare('SELECT c.id FROM chatwoot_conversation_opportunity r
            JOIN chatwoot_conversation c ON c.id = r.chatwoot_conversation_id AND c.deleted = 0
            JOIN chatwoot_account a ON a.id = c.chatwoot_account_id AND a.tenant_id = ?
            WHERE r.opportunity_id = ? AND r.deleted = 0 ORDER BY c.id');
        $rows->execute([$deal->get('tenantId'), $deal->getId()]);
        $cutoff = gmdate('Y-m-d H:i:s');
        foreach ($rows->fetchAll(\PDO::FETCH_COLUMN) as $id) $this->discardThrough($id, $cutoff);
    }

    public function discardThrough(string $id, string $cutoff): void
    {
        $pdo = $this->em->getPDO();
        $update = $pdo->prepare('UPDATE chatwoot_conversation SET catch_up_discarded_at = ?
            WHERE id = ? AND (catch_up_discarded_at IS NULL OR catch_up_discarded_at < ?)');
        $update->execute([$cutoff, $id, $cutoff]);
        if (!$update->rowCount()) return;
        $pdo->prepare('DELETE FROM catch_up_verdict WHERE conversation_id = ?')->execute([$id]);
        $pdo->prepare("DELETE FROM catch_up_record WHERE subject_type = 'ChatwootConversation' AND subject_id = ?")->execute([$id]);
    }
}
