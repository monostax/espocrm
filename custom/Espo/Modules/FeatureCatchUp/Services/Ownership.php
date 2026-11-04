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
