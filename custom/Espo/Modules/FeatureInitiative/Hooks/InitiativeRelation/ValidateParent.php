<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Hooks\InitiativeRelation;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Modules\FeatureInitiative\Services\InitiativeTypeAccess;
use Espo\Modules\FeatureInitiative\Services\ValidationError;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/** One row per parent, allowing any number and mix of supported parent types. */
class ValidateParent implements BeforeSave, SaveHook
{
    public static int $order = 10;
    public const PARENT_TYPES = ['Account', 'Contact', 'ChatwootConversation', 'Task', 'Initiative', 'Opportunity'];

    public function __construct(
        private EntityManager $entityManager,
        private InitiativeTypeAccess $initiativeTypeAccess,
        private TeamsAccess $teamsAccess,
        private TenantResolver $tenantResolver,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->process($entity);
    }

    public function process(Entity $entity): void
    {
        if (!$entity->isNew() && $entity->isAttributeChanged('initiativeId')) {
            throw ValidationError::badRequest('cannotChangeInitiative', 'A relation cannot be moved to another initiative.');
        }

        $initiativeId = $entity->get('initiativeId');
        $initiative = is_string($initiativeId) && $initiativeId !== ''
            ? $this->entityManager->getEntityById('Initiative', $initiativeId)
            : null;

        if (!$initiative) {
            throw ValidationError::badRequest('initiativeRequired', 'An existing initiative is required.');
        }

        $this->initiativeTypeAccess->assertReadable($initiative, 'edit');

        if ($entity->get('initiativeTypeId') && $entity->get('initiativeTypeId') !== $initiative->get('initiativeTypeId')) {
            throw ValidationError::badRequest('relationTypeMismatch', 'The relation must belong to the initiative type.');
        }

        $entity->set('initiativeTypeId', $initiative->get('initiativeTypeId'));
        $initiativeType = $this->initiativeTypeAccess->requireParent($entity, 'read');

        $type = $entity->get('parentType');
        $id = $entity->get('parentId');

        if (!in_array($type, self::PARENT_TYPES, true) || !is_string($id) || $id === '') {
            throw ValidationError::badRequest('unsupportedTarget', 'Select a supported parent record.');
        }

        if ($type === 'Initiative' && $id === $initiativeId) {
            throw ValidationError::badRequest('selfParent', 'An initiative cannot be related to itself.');
        }

        $parent = $this->entityManager->getEntityById($type, $id);

        if (!$parent || $this->parentTenantId($parent) !== $initiativeType->get('tenantId')) {
            throw ValidationError::badRequest('targetTenantMismatch', 'The related record must belong to the initiative tenant.');
        }

        $this->initiativeTypeAccess->assertReadable($parent);

        $where = ['initiativeId' => $initiativeId, 'parentType' => $type, 'parentId' => $id];

        if (!$entity->isNew()) {
            $where['id!='] = $entity->getId();
        }

        if ($this->entityManager->getRDBRepository('InitiativeRelation')->where($where)->findOne()) {
            throw ValidationError::badRequest('duplicateParent', 'This related record is already linked to the initiative.');
        }

        $entity->set('name', $parent->get('name') ?: "$type $id");
    }

    private function parentTenantId(Entity $parent): ?string
    {
        if ($parent->getEntityType() === 'Task') {
            // Tasks have team ownership, not a tenantId column. Refuse ambiguous teams.
            return $this->tenantResolver->resolveUniqueFromTeamIds($this->teamsAccess->entityTeamIds($parent));
        }

        if ($parent->getEntityType() === 'ChatwootConversation') {
            // Conversation ownership comes from its Chatwoot account.
            $accountId = $parent->get('chatwootAccountId');
            $account = is_string($accountId) && $accountId !== ''
                ? $this->entityManager->getEntityById('ChatwootAccount', $accountId)
                : null;

            return $account?->get('tenantId') ?: null;
        }

        return $parent->get('tenantId') ?: null;
    }
}
