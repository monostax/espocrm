<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\Core\Exceptions\Conflict;
use InvalidArgumentException;
use PDO;

/** Supplied only by a trusted admission caller, never inferred from telemetry keys. */
final readonly class SourceReference
{
    public const TABLES = ['ChatwootAiAgentRun' => 'chatwoot_ai_agent_run', 'Opportunity' => 'opportunity'];

    public function __construct(public string $type, public string $id)
    {
        if (!isset(self::TABLES[$type])) {
            throw new InvalidArgumentException('Unsupported credit source.');
        }
        GrantInput::identity($id);
    }

    public function assertTenantLocked(PDO $pdo, string $tenantId): void
    {
        $query = $pdo->prepare('SELECT tenant_id, deleted FROM ' . self::TABLES[$this->type] . ' WHERE id = ? FOR UPDATE');
        $query->execute([$this->id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['deleted'] || $row['tenant_id'] !== $tenantId) {
            throw new Conflict('Credit source must exist in the admitted tenant.');
        }
    }
}
