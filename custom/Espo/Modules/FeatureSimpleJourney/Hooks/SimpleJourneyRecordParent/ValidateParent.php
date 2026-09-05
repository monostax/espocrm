<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Hooks\SimpleJourneyRecordParent;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Modules\FeatureSimpleJourney\Services\JourneyAccess;
use Espo\Modules\FeatureSimpleJourney\Services\ValidationError;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/** One row per parent, allowing any number and mix of supported parent types. */
class ValidateParent implements BeforeSave, SaveHook
{
    public static int $order = 10;
    public const PARENT_TYPES = ['Account', 'Contact', 'ChatwootConversation', 'Task', 'SimpleJourneyRecord'];

    public function __construct(
        private EntityManager $entityManager,
        private JourneyAccess $journeyAccess,
        private TeamsAccess $teamsAccess,
        private TenantResolver $tenantResolver,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->process($entity);
    }

    public function process(Entity $entity): void
    {
        if (!$entity->isNew() && $entity->isAttributeChanged('recordId')) {
            throw ValidationError::badRequest('cannotChangeRecord', 'A parent link cannot be moved to another journey record.');
        }

        $recordId = $entity->get('recordId');
        $record = is_string($recordId) && $recordId !== ''
            ? $this->entityManager->getEntityById('SimpleJourneyRecord', $recordId)
            : null;

        if (!$record) {
            throw ValidationError::badRequest('recordRequired', 'An existing journey record is required.');
        }

        $this->journeyAccess->assertReadable($record, 'edit');

        if ($entity->get('journeyId') && $entity->get('journeyId') !== $record->get('journeyId')) {
            throw ValidationError::badRequest('linkJourneyMismatch', 'The link must belong to the record journey.');
        }

        $entity->set('journeyId', $record->get('journeyId'));
        $journey = $this->journeyAccess->requireParent($entity, 'read');

        $type = $entity->get('parentType');
        $id = $entity->get('parentId');

        if (!in_array($type, self::PARENT_TYPES, true) || !is_string($id) || $id === '') {
            throw ValidationError::badRequest('unsupportedTarget', 'Select a supported parent record.');
        }

        if ($type === 'SimpleJourneyRecord' && $id === $recordId) {
            throw ValidationError::badRequest('selfParent', 'A journey record cannot be its own parent.');
        }

        $parent = $this->entityManager->getEntityById($type, $id);

        if (!$parent || $this->parentTenantId($parent) !== $journey->get('tenantId')) {
            throw ValidationError::badRequest('targetTenantMismatch', 'The parent must belong to the journey tenant.');
        }

        $this->journeyAccess->assertReadable($parent);

        $where = ['recordId' => $recordId, 'parentType' => $type, 'parentId' => $id];

        if (!$entity->isNew()) {
            $where['id!='] = $entity->getId();
        }

        if ($this->entityManager->getRDBRepository('SimpleJourneyRecordParent')->where($where)->findOne()) {
            throw ValidationError::badRequest('duplicateParent', 'This parent is already linked to the journey record.');
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
