<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Entities\User;
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\ORM\EntityManager;

/**
 * Whose deck a deal belongs in. A deal owned by a person goes to that person. A deal owned by the AI,
 * or by nobody, goes to the last staff member who wrote in its conversation (CatchUpDealState).
 * Tenant admins see every open deal; the Opportunity ACL still applies to everything they get.
 */
class Ownership
{
    /** CRM users behind AI agent memberships. Their names are never used to detect them. */
    public const AI_USERS_SQL = 'SELECT cu.assigned_user_id FROM chatwoot_account_user_membership m '
        . 'JOIN chatwoot_user cu ON cu.id = m.chatwoot_user_id AND cu.deleted = 0 '
        . 'WHERE m.deleted = 0 AND m.is_a_i = 1 AND cu.assigned_user_id IS NOT NULL';

    /**
     * The CRM user whose deck a conversation without a deal (`c`, with its account `a`) belongs in: its
     * Chatwoot assignee when that is a person, otherwise the last staff member who wrote in it from the app
     * (CatchUpConversationState, kept by ConversationStates).
     */
    public const CONVERSATION_OWNER_SQL = '(COALESCE((SELECT cu.assigned_user_id FROM chatwoot_user cu WHERE cu.chatwoot_user_id = c.assignee_id '
        . 'AND cu.platform_id = a.platform_id AND cu.deleted = 0 AND cu.assigned_user_id NOT IN (' . self::AI_USERS_SQL . ') LIMIT 1), '
        . '(SELECT cs.staff_user_id FROM catch_up_conversation_state cs WHERE cs.id = c.id AND cs.deleted = 0)))';

    /** @var ?list<string> */
    private ?array $aiUserIds = null;

    public function __construct(private EntityManager $em, private User $user) {}

    public function seesAll(): bool
    {
        return TenantRoleAuth::hasTenantAdminRole($this->user, $this->em);
    }

    /** @return list<string> */
    public function aiUserIds(): array
    {
        return $this->aiUserIds ??= array_values(array_unique(array_map('strval',
            $this->em->getPDO()->query(self::AI_USERS_SQL)->fetchAll(\PDO::FETCH_COLUMN))));
    }

    /** The person whose deck the deal is in, or null when only admins see it. */
    public function deckOwnerId(?string $assignedUserId, ?string $staffUserId): ?string
    {
        if ($assignedUserId && !in_array($assignedUserId, $this->aiUserIds(), true)) return $assignedUserId;
        return $staffUserId ?: null;
    }

    /**
     * SQL restricting `c` (chatwoot_conversation) and `a` (chatwoot_account) to the reader's Atendimento deck.
     * Admins see every conversation; Chatwoot inbox access still applies to everything anyone gets.
     *
     * @return array{string, array<string, string>}
     */
    public function conversationClause(): array
    {
        if ($this->seesAll()) return ['', []];
        return [' AND ' . self::CONVERSATION_OWNER_SQL . ' = :deckOwner', ['deckOwner' => $this->user->getId()]];
    }

    /**
     * SQL restricting `o` (opportunity) and `s` (catch_up_deal_state) to the reader's deck.
     *
     * @return array{string, array<string, string>}
     */
    public function clause(): array
    {
        if ($this->seesAll()) return ['', []];
        $ai = self::AI_USERS_SQL;
        return [
            " AND ((o.assigned_user_id = :deckOwnerA AND o.assigned_user_id NOT IN ($ai))"
            . " OR ((o.assigned_user_id IS NULL OR o.assigned_user_id IN ($ai)) AND s.staff_user_id = :deckOwnerB))",
            ['deckOwnerA' => $this->user->getId(), 'deckOwnerB' => $this->user->getId()],
        ];
    }
}
