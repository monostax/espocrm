<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Hooks\RecordRelation;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureRecordKnowledge\Services\Access;
use Espo\Modules\FeatureRecordKnowledge\Services\Tenancy;
use Espo\Modules\FeatureRecordKnowledge\Services\PredicateRegistry;
use Espo\Modules\FeatureRecordKnowledge\Tools\Evidence;

/** The same evidence/registry boundary also covers direct ORM/import integrations. */
class Validate
{
    public function __construct(private Access $access, private Tenancy $tenancy, private PredicateRegistry $registry, private User $user, private EntityManager $em) {}
    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            foreach (['tenantId', 'subjectType', 'subjectId', 'objectType', 'objectId', 'predicate', 'qualifiers', 'sourceDocumentId',
                'sourceRevisionId', 'evidenceQuote', 'evidenceStart', 'evidenceEnd', 'origin', 'submissionKey', 'submissionHash'] as $field) {
                if ($entity->isAttributeChanged($field)) throw new Conflict('Claim identity, evidence and predicate semantics are immutable.');
            }
            return;
        }
        $subject = $this->access->record($entity->get('subjectType'), $entity->get('subjectId'), $entity->get('origin') === 'manual' ? 'edit' : 'read');
        $object = $this->access->record($entity->get('objectType'), $entity->get('objectId'));
        $revision = $this->access->revision($entity->get('sourceRevisionId'));
        $document = $this->access->document($revision->get('documentId'));
        $tenant = $this->tenancy->derive($subject, $object, $document, $entity->get('tenantId'));
        $this->registry->lock($tenant);
        [$key, $qualifiers] = $this->registry->validate($entity->get('predicate'), $tenant, $entity->get('subjectType'), $entity->get('objectType'), $entity->get('qualifiers') ?? (object) []);
        $span = Evidence::validate((string) $revision->get('body'), $entity->get('evidenceQuote'), $entity->get('evidenceStart'), $entity->get('evidenceEnd'));
        if ($document->getId() !== $entity->get('sourceDocumentId')) throw new BadRequest('Evidence document mismatch.');
        if (!in_array($entity->get('origin'), ['manual', 'assistant'], true) ||
            ($entity->get('origin') === 'assistant' && !in_array($entity->get('status'), ['suggested', 'stale'], true)) ||
            ($entity->get('origin') === 'manual' && $this->user->isApi())) throw new BadRequest('Invalid claim origin/status.');
        $current = $this->em->getRDBRepository('DocumentRevision')->where(['documentId' => $document->getId(), 'revisionNumber' => $document->get('bodyRevisionNumber')])->findOne();
        $anchor = $current?->getId() === $revision->getId() ? $span : Evidence::anchor((string) $document->get('body'), $entity->get('evidenceQuote'));
        if (!$anchor && $entity->get('origin') === 'manual') throw new Conflict('Manual evidence is no longer supported.');
        $entity->set(['tenantId' => $tenant, 'predicate' => $key, 'qualifiers' => $qualifiers,
            'anchorRevisionId' => $anchor ? $current?->getId() : null, 'anchorStart' => $anchor[0] ?? null, 'anchorEnd' => $anchor[1] ?? null,
            'status' => $anchor ? ($entity->get('origin') === 'manual' ? 'confirmed' : 'suggested') : 'stale',
            'createdById' => $this->user->getId(), 'decidedById' => $entity->get('origin') === 'manual' ? $this->user->getId() : null,
            'decidedAt' => $entity->get('origin') === 'manual' ? gmdate('Y-m-d H:i:s') : null]);
    }
    public function afterSave(Entity $entity, array $options): void
    {
        if ($entity->isNew()) $this->registry->changed($entity->get('tenantId'));
    }
}
