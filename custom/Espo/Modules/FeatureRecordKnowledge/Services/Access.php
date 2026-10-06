<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;
use Espo\Modules\FeatureRecordKnowledge\Tools\Evidence;

class Access
{
    public function __construct(private EntityManager $em, private Acl $acl, private Scopes $scopes, private SelectBuilderFactory $select,
        private Tenancy $tenancy, private PredicateRegistry $registry) {}

    public function record(string $type, string $id, string $action = 'read'): Entity
    {
        if (!$this->scopes->supports($type) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id)) throw new BadRequest('Unsupported record identity.');
        if (!$this->acl->checkScope($type, 'read') || !$this->acl->checkField($type, 'name')) throw new Forbidden();
        $query = $this->select->create()->from($type)->withStrictAccessControl()->buildQueryBuilder()->where(['id' => $id])->build();
        $entity = $this->em->getRDBRepository($type)->clone($query)->findOne();
        if (!$entity) throw new NotFound();
        if (!$this->acl->checkEntityRead($entity) || ($action !== 'read' && !$this->acl->checkEntity($entity, $action))) throw new Forbidden();
        return $entity;
    }

    public function document(string $id, string $action = 'read'): Entity
    {
        $document = $this->record('Document', $id, $action);
        if ($document->get('contentType') !== 'Page' || !$this->acl->checkField('Document', 'body', $action)) throw new Forbidden();
        if ($document->get('knowledgeRecordType')) {
            $this->record($document->get('knowledgeRecordType'), $document->get('knowledgeRecordId'), $action);
        }
        return $document;
    }

    public function revision(string $id): Entity
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id)) throw new BadRequest('Invalid revision ID.');
        $revision = $this->em->getEntityById('DocumentRevision', $id);
        if (!$revision) throw new NotFound();
        $this->document($revision->get('documentId'));
        return $revision;
    }

    public function claim(Entity $claim, bool $edit = false): void
    {
        if (!$claim->get('tenantId')) throw new Forbidden('Claim tenancy is missing.');
        $subject = $this->record($claim->get('subjectType'), $claim->get('subjectId'), $edit ? 'edit' : 'read');
        $object = $this->record($claim->get('objectType'), $claim->get('objectId'));
        $document = null;
        $hasEvidence = Evidence::provided($claim->get('sourceRevisionId'), $claim->get('evidenceQuote'),
            $claim->get('evidenceStart'), $claim->get('evidenceEnd'), $claim->get('origin') !== 'manual');
        if ($hasEvidence) {
            $document = $this->document($claim->get('sourceDocumentId'));
            $revision = $this->revision($claim->get('sourceRevisionId'));
            if ($revision->get('documentId') !== $document->getId()) throw new Forbidden();
        } elseif ($claim->get('sourceDocumentId')) {
            throw new Forbidden('Incomplete relation evidence.');
        }
        $tenantId = $this->tenancy->derive($subject, $object, $document, $claim->get('tenantId'));
        $this->registry->resolve($claim->get('predicate'), $tenantId, false);
    }
}
