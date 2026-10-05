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
        if (!$id || !in_array($type, ['Note', 'Opportunity', 'Initiative', 'Contact', 'Account'], true)) {
            return null;
        }
        $parent = $this->entityManager->getEntityById($type, $id);
        if (!$parent) {
            return false;
        }
        if ($type === 'Note' && in_array($parent->get('parentType'), ['Opportunity', 'Initiative', 'Contact', 'Account'], true)) {
            return $this->access->canReadNote($user, $parent) && $this->aclManager->checkEntityRead($user, $parent);
        }
        if (in_array($type, ['Opportunity', 'Initiative', 'Contact', 'Account'], true) && !$this->aclManager->checkEntityRead($user, $parent)) {
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
        $initiatives = $this->aclManager->checkScope($user, 'Initiative', 'read')
            ? $factory->create()->from('Initiative')->withStrictAccessControl()->buildQueryBuilder()->select('id')->order([])->build()
            : SelectBuilder::create()->from('Initiative')->select('id')->where(['id' => []])->build();
        $contacts = $this->aclManager->checkScope($user, 'Contact', 'read')
            ? $factory->create()->from('Contact')->withStrictAccessControl()->buildQueryBuilder()->select('id')->order([])->build()
            : SelectBuilder::create()->from('Contact')->select('id')->where(['id' => []])->build();

        $accounts = $this->aclManager->checkScope($user, 'Account', 'read')
            ? $factory->create()->from('Account')->withStrictAccessControl()->buildQueryBuilder()->select('id')->order([])->build()
            : SelectBuilder::create()->from('Account')->select('id')->where(['id' => []])->build();

        $allowed = static fn (string $type, string $id) => ['OR' => [
            [$type . '!=' => ['Note', 'Opportunity', 'Initiative', 'Contact', 'Account']],
            [$type => null],
            [$id => null],
            [$id => ['', '0']],
            [$type => 'Note', $id . '=s' => $notes],
            [$type => 'Opportunity', $id . '=s' => $opportunities],
            [$type => 'Initiative', $id . '=s' => $initiatives],
            [$type => 'Contact', $id . '=s' => $contacts],
            [$type => 'Account', $id . '=s' => $accounts],
        ]];

        return ['OR' => [
            ['parentType!=' => null, 'parentId!=' => null, ['parentType!=' => ['', '0'], 'parentId!=' => ['', '0']], $allowed('parentType', 'parentId')],
            ['OR' => [['parentType' => null], ['parentId' => null], ['parentType' => ['', '0']], ['parentId' => ['', '0']]], $allowed('relatedType', 'relatedId')],
        ]];
    }
}
