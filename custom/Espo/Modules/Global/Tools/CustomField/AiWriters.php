<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Tools\CustomField;

use Espo\ORM\EntityManager;
use PDO;

/**
 * The CRM users that an AI agent acts as: the user behind a Chatwoot membership marked AI. A save by one of them
 * never fails on an empty required custom field (user decision, 2026-11-24). The AI fills what the conversation
 * tells, and a person completes the rest the next time they edit the record. People are checked as before, and so
 * are AI Session requests, which run as the person who asked.
 */
class AiWriters
{
    private const SQL = 'SELECT 1 FROM chatwoot_account_user_membership m '
        . 'JOIN chatwoot_user cu ON cu.id = m.chatwoot_user_id AND cu.deleted = 0 '
        . 'WHERE m.deleted = 0 AND m.is_a_i = 1 AND cu.assigned_user_id = ? LIMIT 1';

    /** @var array<string, bool> */
    private array $known = [];

    public function __construct(private EntityManager $entityManager) {}

    public function isAi(?string $userId): bool
    {
        if ($userId === null || $userId === '' || $userId === 'system') return false;
        if (!array_key_exists($userId, $this->known)) {
            $statement = $this->entityManager->getPDO()->prepare(self::SQL);
            $statement->execute([$userId]);
            $this->known[$userId] = $statement->fetchColumn() !== false;
        }
        return $this->known[$userId];
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }
}
