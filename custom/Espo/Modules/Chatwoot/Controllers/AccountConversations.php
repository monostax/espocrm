<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\ORM\Entity;

/** Aggregate linked contacts, retaining the viewer-token authorization in the proxy. */
class AccountConversations extends OpportunityConversations
{
    protected function entityType(): string
    {
        return 'Account';
    }

    protected function canReadRelationship(): bool
    {
        return $this->aclManager->checkLink($this->user, 'Account', 'contacts') &&
            $this->aclManager->checkField($this->user, 'Account', 'contacts') &&
            $this->aclManager->checkScope($this->user, 'Contact', 'read') &&
            $this->aclManager->checkLink($this->user, 'Contact', 'chatwootConversations') &&
            $this->aclManager->checkField($this->user, 'Contact', 'chatwootConversations');
    }

    protected function linkedConversations(Entity $record, Entity $workspace): iterable
    {
        $contacts = $this->entityManager->getRDBRepository('Account')->getRelation($record, 'contacts')
            ->where(['tenantId' => $record->get('tenantId')])->find();
        foreach ($contacts as $contact) {
            if (!$this->aclManager->checkEntityRead($this->user, $contact)) continue;
            yield from parent::linkedConversations($contact, $workspace);
        }
    }
}
