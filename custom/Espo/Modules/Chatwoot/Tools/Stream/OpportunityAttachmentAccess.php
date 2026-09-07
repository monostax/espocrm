<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

class OpportunityAttachmentAccess
{
    public function __construct(
        private EntityManager $entityManager,
        private AclManager $aclManager,
        private OpportunityAccess $access,
        private InjectableFactory $factory,
    ) {}

    /** Null preserves stock authorization for unrelated attachments and unlinked uploads. */
    public function check(User $user, Entity $attachment): ?bool
    {
        if ($user->isAdmin()) {
            return null;
        }
        $hasParent = $attachment->get('parentType') && $attachment->get('parentId');
        $type = $attachment->get($hasParent ? 'parentType' : 'relatedType');
        $id = $attachment->get($hasParent ? 'parentId' : 'relatedId');
        if (!$id || !in_array($type, ['Note', 'Opportunity'], true)) {
            return null;
        }
        $parent = $this->entityManager->getEntityById($type, $id);
        if (!$parent) {
            return false;
        }
        if ($type === 'Note' && $parent->get('parentType') === 'Opportunity') {
            return $this->access->canReadNote($user, $parent) && $this->aclManager->checkEntityRead($user, $parent);
        }
        if ($type === 'Opportunity' && !$this->aclManager->checkEntityRead($user, $parent)) {
            return false;
        }

        return null;
    }

    /** List endpoints must filter before pagination; they do not call attachment entity ACL per row. */
    public function where(User $user): array
    {
        if ($user->isAdmin()) {
            return [];
        }
        $factory = $this->factory->createWith(SelectBuilderFactory::class, ['user' => $user]);
        $notes = $this->aclManager->checkScope($user, 'Note', 'read')
            ? $factory->create()->from('Note')->withStrictAccessControl()->buildQueryBuilder()->select('id')->order([])->build()
            : SelectBuilder::create()->from('Note')->select('id')->where(['id' => []])->build();
        $opportunities = $this->aclManager->checkScope($user, 'Opportunity', 'read')
            ? $factory->create()->from('Opportunity')->withStrictAccessControl()->buildQueryBuilder()->select('id')->order([])->build()
            : SelectBuilder::create()->from('Opportunity')->select('id')->where(['id' => []])->build();

        $allowed = static fn (string $type, string $id) => ['OR' => [
            [$type . '!=' => ['Note', 'Opportunity']],
            [$type => null],
            [$id => null],
            [$id => ['', '0']],
            [$type => 'Note', $id . '=s' => $notes],
            [$type => 'Opportunity', $id . '=s' => $opportunities],
        ]];

        return ['OR' => [
            ['parentType!=' => null, 'parentId!=' => null, ['parentType!=' => ['', '0'], 'parentId!=' => ['', '0']], $allowed('parentType', 'parentId')],
            ['OR' => [['parentType' => null], ['parentId' => null], ['parentType' => ['', '0']], ['parentId' => ['', '0']]], $allowed('relatedType', 'relatedId')],
        ]];
    }
}
