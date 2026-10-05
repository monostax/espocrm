<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class IdentityOwnership
{
    public function __construct(private EntityManager $entityManager) {}

    public function isRejected(Entity $contact, string $channel, ?string $source): bool
    {
        if (!$source) {
            return false;
        }
        $channels = in_array($channel, ['whatsapp', 'sms', 'phone'], true) ? ['whatsapp', 'sms'] : [$channel];
        $source = in_array($channel, ['whatsapp', 'sms', 'phone'], true)
            ? PhoneNormalizer::normalize($source)
            : ($channel === 'email' ? strtolower(trim($source)) : $source);
        if (!$source) {
            return false;
        }
        return (bool) $this->entityManager->getRDBRepository('ContactChannelIdentity')->where([
            'contactId' => $contact->getId(),
            'tenantId' => $contact->get('tenantId'),
            'channelType' => $channels,
            'sourceId' => $source,
            'ownershipStatus' => 'rejected',
        ])->findOne();
    }

    public function assertAllowed(Entity $contact, string $channel, ?string $source): void
    {
        if ($this->isRejected($contact, $channel, $source)) {
            throw new Forbidden('identity_wrong_person: This destination was reported as belonging to the wrong person.');
        }
    }
}
