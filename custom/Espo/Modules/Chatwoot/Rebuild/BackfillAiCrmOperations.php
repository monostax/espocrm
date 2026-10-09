<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\ORM\EntityManager;

/** Initialize unset memberships with the default operations; preserve all explicit selections. */
class BackfillAiCrmOperations implements RebuildAction
{
    public function __construct(private EntityManager $entityManager) {}

    public function process(): void
    {
        // Rebuild actions run after schema creation. Shared SQL works on MariaDB and PostgreSQL.
        $statement = $this->entityManager->getPDO()->prepare(
            'UPDATE chatwoot_account_user_membership SET ai_crm_operations = :operations ' .
            'WHERE ai_crm_operations IS NULL AND deleted = false'
        );
        $statement->execute(['operations' => '["read","create","update"]']);
        $this->entityManager->getPDO()->exec(
            'UPDATE chatwoot_account_user_membership ' .
            'SET ai_can_execute = COALESCE(ai_can_execute, true), ai_can_send = COALESCE(ai_can_send, true) ' .
            'WHERE deleted = false AND (ai_can_execute IS NULL OR ai_can_send IS NULL)'
        );
    }
}
