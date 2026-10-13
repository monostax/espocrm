<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\ContactChannelIdentity;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Global\Tools\LinkedInProfile as Profile;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Normalize direct CRUD too; serialize LinkedIn edits/deletes with enrichment's Contact lock. */
final class LinkedInProfile
{
    public static int $order = 2;
    public function __construct(private EntityManager $entityManager) {}
    public function beforeSave(Entity $entity, array $options): void
    {
        $this->lockParents($entity);
        if ($entity->get('channelType') !== 'linkedin') return;
        $profile = Profile::profile('Contact',$entity->get('sourceId'));
        if ($profile === null || strlen($profile) > 255) throw new BadRequest('A personal LinkedIn profile URL is required.');
        $entity->set('sourceId',$profile);
        $entity->set('handle',null);
    }
    public function beforeRemove(Entity $entity, array $options): void { $this->lockParents($entity); }
    private function lockParents(Entity $entity): void
    {
        if ($entity->get('channelType') !== 'linkedin' && $entity->getFetched('channelType') !== 'linkedin') return;
        $pdo = $this->entityManager->getPDO();
        if (!$pdo->inTransaction()) throw new \LogicException('LinkedIn identity mutation requires transactional save.');
        $ids = array_values(array_unique(array_filter([$entity->get('contactId'),$entity->getFetched('contactId')], 'is_string')));
        sort($ids,SORT_STRING);
        foreach ($ids as $id) {
            $q = $pdo->prepare('SELECT tenant_id FROM contact WHERE id=? AND deleted=FALSE FOR UPDATE');
            $q->execute([$id]); $tenant = $q->fetchColumn();
            if ($tenant === false || ($id === $entity->get('contactId') && $tenant !== $entity->get('tenantId'))) {
                throw new BadRequest('LinkedIn identity must belong to its Contact tenant.');
            }
        }
        if ($ids === []) throw new BadRequest('LinkedIn identity requires a Contact.');
    }
}
