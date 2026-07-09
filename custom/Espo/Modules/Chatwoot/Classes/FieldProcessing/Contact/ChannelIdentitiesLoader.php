<?php

namespace Espo\Modules\Chatwoot\Classes\FieldProcessing\Contact;

use Espo\Core\Acl;
use Espo\Core\FieldProcessing\Loader as LoaderInterface;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Populates the virtual `channelIdentitiesData` field on Contact with the
 * related ContactChannelIdentity rows (WhatsApp, Instagram, etc.), mirroring
 * how the built-in PhoneNumber loader populates `phoneNumberData`.
 *
 * Respects ACL: users whose role denies read access to the
 * ContactChannelIdentity scope do not get the data.
 *
 * Wired via the `loaderClassName` field param in
 * Resources/metadata/entityDefs/Contact.json.
 *
 * @implements LoaderInterface<Entity>
 */
class ChannelIdentitiesLoader implements LoaderInterface
{
    private const FIELD = 'channelIdentitiesData';

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        if (!$entity->hasId()) {
            return;
        }

        if (!$this->user->isSystem() && !$this->acl->checkScope('ContactChannelIdentity', Acl\Table::ACTION_READ)) {
            return;
        }

        $identities = $this->entityManager
            ->getRDBRepository('ContactChannelIdentity')
            ->where(['contactId' => $entity->getId()])
            ->select(['id', 'channelType', 'sourceId', 'handle', 'label', 'isPrimary', 'whatsappLid'])
            ->order('isPrimary', 'DESC')
            ->order('createdAt', 'ASC')
            ->find();

        $data = [];

        foreach ($identities as $identity) {
            $data[] = (object) [
                'id' => $identity->getId(),
                'channelType' => $identity->get('channelType'),
                'sourceId' => $identity->get('sourceId'),
                'handle' => $identity->get('handle'),
                'label' => $identity->get('label'),
                'isPrimary' => (bool) $identity->get('isPrimary'),
                'whatsappLid' => $identity->get('whatsappLid'),
            ];
        }

        $entity->set(self::FIELD, $data);
        $entity->setFetched(self::FIELD, $data);
    }
}
