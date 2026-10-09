<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Hooks\AiSession;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Modules\FeatureAiSession\Services\Access;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

class Validate implements BeforeSave
{
    public static int $order = 5;
    public function __construct(private User $user, private EntityManager $em, private Access $access) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew()) {
            $account = $this->em->getEntityById('ChatwootAccount', (string) $entity->get('chatwootAccountId'));
            $entity->set('assignedUserId', $this->user->getId());
            $entity->set('tenantId', $account?->get('tenantId'));
            $entity->set('titleInitialized', $entity->get('name') !== 'New chat');
        } else {
            foreach (['assignedUserId', 'tenantId', 'chatwootAccountId'] as $field) {
                if ($entity->isAttributeChanged($field)) throw new Forbidden('Session ownership and workspace are immutable.');
            }
            if ($entity->isAttributeChanged('name')) $entity->set('titleInitialized', true);
        }
        if (!$this->access->owner($this->user, $entity)) throw new Forbidden();
        if ($entity->isNew() || $entity->isAttributeChanged('aiAgentMembershipId')) {
            $this->access->agent($this->user, $entity, (string) $entity->get('aiAgentMembershipId'));
        }
    }
}
