<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\ContactChannelIdentity;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\ContactFieldSync;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\Modules\Chatwoot\Tools\WhatsAppRemovals;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class RememberWhatsAppRemoval
{
    public function __construct(private WhatsAppRemovals $removals, private ContactFieldSync $fields,
        private EntityManager $entityManager, private Acl $acl, private User $user) {}

    public function afterRemove(Entity $entity, array $options): void
    {
        if ($entity->get('channelType') === 'whatsapp') {
            $this->remove($entity->get('tenantId'), $entity->get('contactId'), $entity->get('sourceId'));
        }
    }

    public function beforeSave(Entity $entity, array $options): void
    {
        if ($entity->isNew() || $entity->getFetched('channelType') !== 'whatsapp') {
            return;
        }
        // Editing/reassigning an identity also leaves the old native phone behind.
        if ($entity->isAttributeChanged('contactId') || $entity->isAttributeChanged('tenantId') ||
            $entity->isAttributeChanged('channelType') ||
            PhoneNormalizer::normalize($entity->getFetched('sourceId')) !== PhoneNormalizer::normalize($entity->get('sourceId'))) {
            $this->remove($entity->getFetched('tenantId'), $entity->getFetched('contactId'), $entity->getFetched('sourceId'));
        }
    }

    private function remove(?string $tenant, ?string $contact, ?string $source): void
    {
        $phone = PhoneNormalizer::normalize($source);
        if ($tenant && $contact && $phone) {
            if (!$this->user->isSystem()) {
                $owner = $this->entityManager->getEntityById('Contact', $contact);
                if ($owner && ($owner->get('tenantId') !== $tenant || !$this->acl->checkEntityEdit($owner)
                    || !$this->acl->checkField('Contact', 'phoneNumber', 'edit'))) {
                    throw new Forbidden('Editing the Contact phone is required to remove its WhatsApp number.');
                }
            }
            $this->removals->block($tenant, $contact, $phone);
            $this->fields->removePhone($tenant, $contact, $phone);
        }
    }
}
