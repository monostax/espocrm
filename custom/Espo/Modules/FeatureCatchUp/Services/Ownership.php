<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Entities\User;
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\ORM\EntityManager;

/**
 * Whose deck a deal or a conversation without one belongs in.
 * - A deal owned by a person goes to that person; a conversation assigned to a person in Chatwoot goes to them.
 * - A deal owned by the AI or by nobody, and a conversation with no person assigned, go to the whole team
 *   (WHOLE_TEAM; before 2026-11-10, to the last staff member who wrote in it).
 * - Tenant admins (the Tenant Admin role, TenantRoleAuth) see every deal and conversation of the tenant.
 * Nobody, tenant admins included, gets anything built from a conversation Chatwoot's inbox rules don't let them
 * open (ChatwootAccess), and the CRM ACL applies on top.
 */
class Ownership
{
    /**
     * Who gets deals owned by the AI or by nobody, and conversations with no person assigned. Decided 2026-11-10
     * (the user): the whole team, meaning everyone who may open the conversation in Chatwoot (ChatwootAccess) and
     * read the record in the CRM, instead of only the last staff member who wrote in it. The user may change this
     * back later: set it to false to restore the last-writer rule. CatchUpDealState and CatchUpConversationState
     * are still kept up to date for that, and cards still name the last staff writer as the owner.
     */
    public const WHOLE_TEAM = true;

    /** CRM users behind AI agent memberships. Their names are never used to detect them. */
    public const AI_USERS_SQL = 'SELECT cu.assigned_user_id FROM chatwoot_account_user_membership m '
        . 'JOIN chatwoot_user cu ON cu.id = m.chatwoot_user_id AND cu.deleted = 0 '
        . 'WHERE m.deleted = 0 AND m.is_a_i = 1 AND cu.assigned_user_id IS NOT NULL';

    /** The CRM user behind the Chatwoot assignee of `c` (with its account `a`) when that is a person, else NULL. */
    public const CONVERSATION_PERSON_SQL = '(SELECT cu.assigned_user_id FROM chatwoot_user cu WHERE cu.chatwoot_user_id = c.assignee_id '
        . 'AND cu.platform_id = a.platform_id AND cu.deleted = 0 AND cu.assigned_user_id NOT IN (' . self::AI_USERS_SQL . ') LIMIT 1)';

    /**
     * The person a conversation without a deal is shown as owned by: its Chatwoot assignee when that is a person,
     * otherwise the last staff member who wrote in it from the app (CatchUpConversationState, kept by
     * ConversationStates). With WHOLE_TEAM it only names the owner on the card; it no longer decides the deck.
     */
    public const CONVERSATION_OWNER_SQL = '(COALESCE(' . self::CONVERSATION_PERSON_SQL . ', '
        . '(SELECT cs.staff_user_id FROM catch_up_conversation_state cs WHERE cs.id = c.id AND cs.deleted = 0)))';

    /** @var ?list<string> */
    private ?array $aiUserIds = null;

    public function __construct(private EntityManager $em, private User $user) {}

    /** Tenant admins see every deal and conversation of the tenant that Chatwoot lets them open. */
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

    /** The person a deal's card names as its owner: its assigned person, else the last staff writer. */
    public function deckOwnerId(?string $assignedUserId, ?string $staffUserId): ?string
    {
        if ($assignedUserId && !in_array($assignedUserId, $this->aiUserIds(), true)) return $assignedUserId;
        return $staffUserId ?: null;
    }

    /**
     * SQL restricting `c` (chatwoot_conversation) and `a` (chatwoot_account) to the reader's Atendimento deck.
     * Chatwoot access (ChatwootAccess) is applied to whatever this returns.
     *
     * @return array{string, array<string, string>}
     */
    public function conversationClause(): array
    {
        if ($this->seesAll()) return ['', []];
        // The whole team gets conversations with no person assigned (see WHOLE_TEAM); the else branch is the last-writer rule.
        if (self::WHOLE_TEAM) return [' AND COALESCE(' . self::CONVERSATION_PERSON_SQL . ", '') IN ('', :deckOwner)", ['deckOwner' => $this->user->getId()]];
        return [' AND ' . self::CONVERSATION_OWNER_SQL . ' = :deckOwner', ['deckOwner' => $this->user->getId()]];
    }

    /**
     * SQL restricting `o` (opportunity) and `s` (catch_up_deal_state) to the reader's deck. Chatwoot access
     * (ChatwootAccess) is applied to whatever this returns.
     *
     * @return array{string, array<string, string>}
     */
    public function clause(): array
    {
        if ($this->seesAll()) return ['', []];
        $ai = self::AI_USERS_SQL;
        $mine = "(o.assigned_user_id = :deckOwnerA AND o.assigned_user_id NOT IN ($ai))";
        $aiOrNobody = "(o.assigned_user_id IS NULL OR o.assigned_user_id IN ($ai))";
        // The whole team gets the AI's and nobody's deals (see WHOLE_TEAM); the else branch is the last-writer rule.
        if (self::WHOLE_TEAM) return [" AND ($mine OR $aiOrNobody)", ['deckOwnerA' => $this->user->getId()]];
        return [
            " AND ($mine OR ($aiOrNobody AND s.staff_user_id = :deckOwnerB))",
            ['deckOwnerA' => $this->user->getId(), 'deckOwnerB' => $this->user->getId()],
        ];
    }
}
