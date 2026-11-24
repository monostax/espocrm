<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Saved filters of the record lists (contacts, companies, initiatives, opportunities), per workspace.
 *
 * A saved filter is only a definition. Whoever opens it gets the list evaluated under their own access, so sharing
 * one with the workspace shares the question, never the answer. A private filter belongs to its owner alone. A shared
 * one may be renamed and edited by anyone in the workspace; only its owner or a workspace administrator removes it, and
 * only the owner shares or unshares it (2026-11-24).
 */
class RecordFilters
{
    private const TYPES = ['Contact', 'Account', 'Initiative', 'Opportunity'];
    private const MAX_PER_USER = 50;
    private const MAX_DEFINITION_BYTES = 20000;

    public function __construct(
        private EntityManager $em,
        private User $user,
        private Acl $acl,
        private OpportunityBulkPostAccess $workspaces,
    ) {}

    public function list(int $accountId): object
    {
        $workspace = $this->workspaces->workspace($accountId);
        $types = array_values(array_filter(self::TYPES, fn (string $type) => $this->acl->checkScope($type, Acl\Table::ACTION_READ)));
        if (!$types) return (object) ['list' => []];
        $filters = $this->em->getRDBRepository('RecordFilter')->where([
            'accountId' => $workspace->getId(), 'tenantId' => $workspace->get('tenantId'), 'entityType' => $types,
            'OR' => [['userId' => $this->user->getId()], ['shared' => true]],
        ])->order('name')->find();
        $admin = $this->administers($workspace);
        $list = [];
        foreach ($filters as $filter) $list[] = $this->present($filter, $admin);
        return (object) ['list' => $list];
    }

    public function create(int $accountId, object $data): object
    {
        $workspace = $this->workspaces->workspace($accountId);
        $values = $this->values($data, true);
        $count = $this->em->getRDBRepository('RecordFilter')
            ->where(['accountId' => $workspace->getId(), 'userId' => $this->user->getId()])->count();
        if ($count >= self::MAX_PER_USER) throw new BadRequest('Too many saved filters.');
        $filter = $this->em->createEntity('RecordFilter', $values + [
            'userId' => $this->user->getId(), 'accountId' => $workspace->getId(), 'tenantId' => $workspace->get('tenantId'),
        ]);
        return $this->present($filter, false);
    }

    public function update(int $accountId, string $id, object $data): object
    {
        $workspace = $this->workspaces->workspace($accountId);
        // Found means this person's own filter or a shared one, and both may be edited.
        $filter = $this->find($workspace, $id);
        $values = $this->values($data, false);
        if (array_key_exists('shared', $values) && $filter->get('userId') !== $this->user->getId()) throw new Forbidden();
        $filter->set($values);
        $this->em->saveEntity($filter);
        return $this->present($filter, $this->administers($workspace));
    }

    public function delete(int $accountId, string $id): void
    {
        $workspace = $this->workspaces->workspace($accountId);
        $filter = $this->find($workspace, $id);
        if ($filter->get('userId') !== $this->user->getId() && !$this->administers($workspace)) throw new Forbidden();
        $this->em->removeEntity($filter);
    }

    /** Visible to this user in this workspace: their own, or shared. Anything else does not exist for them. */
    private function find(Entity $workspace, string $id): Entity
    {
        $filter = preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) ? $this->em->getEntityById('RecordFilter', $id) : null;
        if (!$filter || $filter->get('accountId') !== $workspace->getId() || $filter->get('tenantId') !== $workspace->get('tenantId') ||
            ($filter->get('userId') !== $this->user->getId() && !$filter->get('shared')) ||
            !$this->acl->checkScope((string) $filter->get('entityType'), Acl\Table::ACTION_READ)) {
            throw new NotFound();
        }
        return $filter;
    }

    /** @return array<string, mixed> */
    private function values(object $data, bool $creating): array
    {
        if (array_diff(array_keys(get_object_vars($data)), ['name', 'entityType', 'definition', 'shared'])) {
            throw new BadRequest('Unknown saved filter properties.');
        }
        $values = [];
        if ($creating || property_exists($data, 'name')) {
            $name = is_string($data->name ?? null) ? trim($data->name) : '';
            if ($name === '' || mb_strlen($name) > 80) throw new BadRequest('A name of up to 80 characters is required.');
            $values['name'] = $name;
        }
        if ($creating) {
            $type = $data->entityType ?? null;
            if (!in_array($type, self::TYPES, true) || !$this->acl->checkScope($type, Acl\Table::ACTION_READ)) throw new Forbidden();
            $values['entityType'] = $type;
        } elseif (property_exists($data, 'entityType')) {
            throw new BadRequest('A saved filter cannot change lists.');
        }
        if ($creating || property_exists($data, 'definition')) {
            $definition = $data->definition ?? null;
            if (!is_object($definition) || strlen((string) json_encode($definition)) > self::MAX_DEFINITION_BYTES) {
                throw new BadRequest('Invalid filter definition.');
            }
            $values['definition'] = $definition;
        }
        if (property_exists($data, 'shared')) {
            if (!is_bool($data->shared)) throw new BadRequest('Invalid sharing choice.');
            $values['shared'] = $data->shared;
        }
        return $values;
    }

    /** A Chatwoot administrator of the whole workspace, or a CRM administrator. */
    private function administers(Entity $workspace): bool
    {
        if ($this->user->isAdmin()) return true;
        return (bool) $this->em->getRDBRepository('ChatwootAccountUserMembership')->join('chatwootUser')->where([
            'chatwootUser.assignedUserId' => $this->user->getId(), 'chatwootAccountId' => $workspace->getId(),
            'role' => 'administrator', 'globalAdmin' => true,
        ])->findOne();
    }

    private function present(Entity $filter, bool $admin): object
    {
        $mine = $filter->get('userId') === $this->user->getId();
        return (object) [
            'id' => $filter->getId(), 'name' => $filter->get('name'), 'entityType' => $filter->get('entityType'),
            'definition' => $filter->get('definition'), 'shared' => (bool) $filter->get('shared'),
            'mine' => $mine, 'canEdit' => $mine || (bool) $filter->get('shared'), 'canDelete' => $mine || $admin,
        ];
    }
}
