<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Modules\Chatwoot\Tools\ContactFieldSync;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\Modules\Chatwoot\Tools\WhatsAppRemovals;
use Espo\ORM\EntityManager;
use PDO;

class BackfillWhatsAppRemovals implements RebuildAction
{
    public function __construct(private EntityManager $entityManager, private WhatsAppRemovals $removals,
        private ContactFieldSync $fields) {}

    public function process(): void
    {
        $rows = $this->entityManager->getPDO()->query("SELECT DISTINCT i.tenant_id, i.contact_id, i.source_id
            FROM contact_channel_identity i JOIN contact c ON c.id = i.contact_id AND c.tenant_id = i.tenant_id
            WHERE c.deleted = FALSE AND i.deleted = TRUE AND i.channel_type = 'whatsapp'
            AND NOT EXISTS (SELECT 1 FROM contact_channel_identity live WHERE live.tenant_id = i.tenant_id
                AND live.contact_id = i.contact_id AND live.channel_type = 'whatsapp'
                AND live.source_id = i.source_id AND live.deleted = FALSE)
            AND NOT EXISTS (SELECT 1 FROM contact_wa_removal r WHERE r.tenant_id = i.tenant_id
                AND r.contact_id = i.contact_id AND r.phone = i.source_id)");
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $phone = PhoneNormalizer::normalize($row['source_id']);
            if (!$phone) {
                continue;
            }
            $this->entityManager->getTransactionManager()->run(function () use ($row, $phone): void {
                $tenant = $row['tenant_id'];
                $contact = $row['contact_id'];
                $this->removals->block($tenant, $contact, $phone, historical: true);
                if (isset($this->removals->blocked($tenant, $contact)[$phone])) {
                    $this->fields->removePhone($tenant, $contact, $phone);
                }
            });
        }
    }
}
