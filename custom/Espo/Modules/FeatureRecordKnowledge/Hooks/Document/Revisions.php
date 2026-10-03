<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Hooks\Document;

use Espo\Core\Exceptions\Conflict;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureRecordKnowledge\Tools\Evidence;

class Revisions
{
    public static int $order = 80;
    public function __construct(private EntityManager $em) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        if ($entity->get('bodyAuthoringMode') !== 'Markdown' && !$entity->get('knowledgeRecordType')) return;
        if (!$entity->isNew()) {
            $current = $this->em->getRDBRepository('Document')->select(['id', 'bodyRevisionNumber'])->where(['id' => $entity->getId()])->forUpdate()->findOne();
            if (!$current || (int) $current->get('bodyRevisionNumber') !== (int) $entity->getFetched('bodyRevisionNumber')) {
                throw new Conflict('Document revision changed.');
            }
        }
        if ($entity->isNew() || $entity->isAttributeChanged('body') || !$entity->get('bodyRevisionNumber')) {
            $entity->set('bodyRevisionNumber', (int) $entity->getFetched('bodyRevisionNumber') + 1);
        }
    }

    public function afterSave(Entity $entity, array $options): void
    {
        if (($entity->get('bodyAuthoringMode') !== 'Markdown' && !$entity->get('knowledgeRecordType')) ||
            !$entity->isAttributeChanged('bodyRevisionNumber')) return;
        $body = (string) $entity->get('body');
        $revision = $this->em->createEntity('DocumentRevision', [
            'name' => $entity->getId() . ' / ' . $entity->get('bodyRevisionNumber'),
            'documentId' => $entity->getId(), 'revisionNumber' => $entity->get('bodyRevisionNumber'),
            'body' => $body, 'contentHash' => hash('sha256', $body),
        ]);
        foreach ($this->em->getRDBRepository('RecordRelation')->where([
            'sourceDocumentId' => $entity->getId(), 'status' => ['suggested', 'confirmed'],
        ])->find() as $claim) {
            $span = Evidence::anchor($body, (string) $claim->get('evidenceQuote'));
            if ($span === null) {
                $claim->set(['status' => 'stale', 'anchorRevisionId' => null, 'anchorStart' => null, 'anchorEnd' => null]);
            } else {
                $claim->set(['anchorRevisionId' => $revision->getId(), 'anchorStart' => $span[0], 'anchorEnd' => $span[1]]);
            }
            $this->em->saveEntity($claim);
        }
    }
}
