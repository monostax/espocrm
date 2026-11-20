<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools;

use Espo\ORM\EntityManager;
use PDO;

/** Contact-specific outreach decisions outlive soft-delete cleanup and Chatwoot identity reconciliation. */
class WhatsAppRemovals
{
    public function __construct(private EntityManager $entityManager) {}

    /** @return array<string, true> */
    public function blocked(string $tenant, string $contact): array
    {
        $query = $this->entityManager->getPDO()->prepare(
            'SELECT phone FROM contact_wa_removal WHERE tenant_id = ? AND contact_id = ? AND blocked = TRUE'
        );
        $query->execute([$tenant, $contact]);

        return array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN), true);
    }

    public function block(?string $tenant, ?string $contact, ?string $source, bool $historical = false): void
    {
        $phone = PhoneNormalizer::normalize($source);
        if (!$tenant || !$contact || !$phone) {
            return;
        }

        $pdo = $this->entityManager->getPDO();
        $upsert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE ' . ($historical ? 'blocked = blocked' : 'blocked = TRUE')
            : ' ON CONFLICT (tenant_id, contact_id, phone) ' . ($historical ? 'DO NOTHING' : 'DO UPDATE SET blocked = TRUE');
        $pdo->prepare('INSERT INTO contact_wa_removal (id, tenant_id, contact_id, phone, blocked)
            VALUES (?, ?, ?, ?, TRUE)' . $upsert)->execute([bin2hex(random_bytes(8)), $tenant, $contact, $phone]);
    }

    public function allowReadded(string $tenant, string $contact, string $source): void
    {
        $this->entityManager->getPDO()->prepare(
            'UPDATE contact_wa_removal SET blocked = FALSE WHERE tenant_id = ? AND contact_id = ? AND phone = ?'
        )->execute([$tenant, $contact, PhoneNormalizer::normalize($source)]);
    }
}
