<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use InvalidArgumentException;
use PDO;

/** Supplied only by a trusted admission caller, never inferred from telemetry keys. */
final readonly class SourceReference
{
    public const TABLES = ['ChatwootAiAgentRun' => 'chatwoot_ai_agent_run', 'Opportunity' => 'opportunity', 'Contact' => 'contact'];
    public const LINKED_TYPES = ['ChatwootConversation', 'Note', 'ChatwootAccountUserMembership', 'Task'];
    private const PARENTS = ['Opportunity' => 'opportunity', 'Initiative' => 'initiative',
        'Account' => 'account', 'Contact' => 'contact', 'AiSession' => 'ai_session'];

    public function __construct(public string $type, public string $id, public ?string $requestId = null)
    {
        if (!self::supports($type)) {
            throw new InvalidArgumentException('Unsupported credit source.');
        }
        GrantInput::identity($id);
        if ($requestId !== null && (!in_array($type, ['Opportunity', 'ChatwootConversation', 'Task'], true) ||
            !preg_match('/^[a-f0-9]{64}$/D', $requestId))) {
            throw new InvalidArgumentException('Invalid summary request identity.');
        }
    }

    public static function supports(string $type): bool
    {
        return isset(self::TABLES[$type]) || in_array($type, self::LINKED_TYPES, true);
    }

    public function assertTenantLocked(PDO $pdo, string $tenantId): void
    {
        if (in_array($this->type, self::LINKED_TYPES, true)) {
            if (!$this->belongsToTenant($pdo, $tenantId, true)) {
                throw new Conflict('Credit source must exist in the admitted tenant.');
            }
            return;
        }
        $query = $pdo->prepare('SELECT tenant_id, deleted FROM ' . self::TABLES[$this->type] . ' WHERE id = ? FOR UPDATE');
        $query->execute([$this->id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['deleted'] || $row['tenant_id'] !== $tenantId) {
            throw new Conflict('Credit source must exist in the admitted tenant.');
        }
    }

    /** Same ownership contract at admission and navigation. No telemetry lookup.
     * The caller already owns the tenant lock when $locked is true. */
    public function belongsToTenant(PDO $pdo, string $tenantId, bool $locked = false): bool
    {
        $read = static function (string $sql, array $params) use ($pdo, $locked): array|false {
            $query = $pdo->prepare($sql . ($locked ? ' FOR UPDATE' : ''));
            $query->execute($params);
            return $query->fetch(PDO::FETCH_ASSOC);
        };
        if ($this->type === 'ChatwootConversation' || $this->type === 'ChatwootAccountUserMembership') {
            $table = $this->type === 'ChatwootConversation' ? 'chatwoot_conversation' : 'chatwoot_account_user_membership';
            $source = $read("SELECT chatwoot_account_id FROM $table WHERE id = ? AND deleted = FALSE", [$this->id]);
            if (!$source) return false;
            return (bool) $read('SELECT id FROM chatwoot_account WHERE id = ? AND tenant_id = ? AND deleted = FALSE',
                [$source['chatwoot_account_id'], $tenantId]);
        }
        // Catch Up task cards are charged to the Task itself.
        if ($this->type === 'Task') return self::activityBelongsToTenant($pdo, $read, 'Task', $this->id, $tenantId);
        if ($this->type !== 'Note') {
            return (bool) $read('SELECT id FROM ' . self::TABLES[$this->type] . ' WHERE id = ? AND tenant_id = ? AND deleted = FALSE', [$this->id, $tenantId]);
        }
        $note = $read('SELECT parent_type, parent_id FROM note WHERE id = ? AND deleted = FALSE AND type = ?', [$this->id, 'Post']);
        if (!$note) return false;
        $type = $note['parent_type'];
        if (isset(self::PARENTS[$type])) {
            return (bool) $read('SELECT id FROM ' . self::PARENTS[$type] . ' WHERE id = ? AND tenant_id = ? AND deleted = FALSE', [$note['parent_id'], $tenantId]);
        }
        if (!in_array($type, ['Task', 'Meeting', 'Call'], true)) return false;
        return self::activityBelongsToTenant($pdo, $read, $type, (string) $note['parent_id'], $tenantId);
    }

    /** Task/Meeting tenancy uses the existing activity team contract. Calls
     * with an explicit tenant must use that tenant, never a team fallback. */
    private static function activityBelongsToTenant(PDO $pdo, callable $read, string $type, string $id, string $tenantId): bool
    {
        $table = strtolower($type);
        if ($type === 'Call') $table = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`call`' : '"call"';
        $activity = $read('SELECT ' . ($type === 'Call' ? 'tenant_id' : 'id') . " FROM $table WHERE id = ? AND deleted = FALSE", [$id]);
        if (!$activity) return false;
        if ($type === 'Call' && $activity['tenant_id'] !== null) return $activity['tenant_id'] === $tenantId;
        return (bool) $read('SELECT et.id FROM entity_team et JOIN team t ON t.id = et.team_id AND t.deleted = FALSE
            WHERE et.entity_id = ? AND et.entity_type = ? AND et.deleted = FALSE AND
            (et.team_id IN (SELECT base_user_team_id FROM tenant WHERE id = ?) OR
             et.team_id IN (SELECT team_id FROM tenant_other_user_team WHERE tenant_id = ? AND deleted = FALSE))',
            [$id, $type, $tenantId, $tenantId]);
    }
}
