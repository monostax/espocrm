<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Services;

use Espo\Core\Exceptions\Conflict;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;
use Espo\Modules\FeatureRecordKnowledge\Tools\Markdown;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\ReferenceIndex;

class Overviews
{
    public function __construct(private EntityManager $em, private Scopes $scopes, private ReferenceIndex $index) {}

    public function eligible(Entity $record): bool
    {
        return $this->scopes->supports($record->getEntityType()) && !$record->get('knowledgeRecordType') && !$record->get('deleted');
    }

    public function binding(string $type, string $id): ?Entity
    {
        return $this->em->getRDBRepository('RecordDocument')->where(['recordType' => $type, 'recordId' => $id])->findOne();
    }

    /** Parent save calls this within its transaction; backfill also uses the same row lock. */
    public function ensure(Entity $record): ?Entity
    {
        if (!$this->eligible($record)) return null;
        return $this->em->getTransactionManager()->run(function () use ($record) {
            // Serialize concurrent backfills/provisioning before creating any content nodes.
            $locked = $this->em->getRDBRepository($record->getEntityType())->select(['id'])
                ->where(['id' => $record->getId()])->forUpdate()->findOne();
            if (!$locked) throw new Conflict('Parent record no longer exists.');
            $parent = $this->em->getEntityById($record->getEntityType(), $record->getId());
            $binding = $this->binding($record->getEntityType(), $record->getId());
            if ($binding) return $binding;
            $values = [
                'name' => mb_substr((string) ($parent->get('name') ?: $parent->getEntityType()) . ' — Overview', 0, 255),
                'contentType' => 'Page', 'bodyFormat' => 'Markdown', 'bodyAuthoringMode' => 'Markdown',
                'body' => $this->initialBody($parent), 'bodyEditorState' => null,
                'knowledgeRecordType' => $parent->getEntityType(), 'knowledgeRecordId' => $parent->getId(),
                'assignedUserId' => $parent->get('assignedUserId'), 'createdById' => $parent->get('createdById'),
            ];
            $document = $this->em->createEntity('Document', $values);
            $this->syncAccess($parent, $document);
            return $this->em->createEntity('RecordDocument', [
                'recordType' => $parent->getEntityType(), 'recordId' => $parent->getId(), 'overviewDocumentId' => $document->getId(),
            ]);
        });
    }

    private function initialBody(Entity $record): string
    {
        $description = $record->get('description');
        // Use authoring metadata, not HTML-looking text: Markdown autolinks and
        // fenced HTML examples are valid source and must not disappear on creation.
        $source = $this->scopes->hasSourceDescription($record->getEntityType()) || $record->get('descriptionFormat') === 'Markdown';
        return is_string($description) && $source ? Markdown::source($description) : '';
    }

    public function syncAccess(Entity $parent, Entity $document): void
    {
        if ($document->get('assignedUserId') !== $parent->get('assignedUserId')) {
            $document->set('assignedUserId', $parent->get('assignedUserId'));
            $this->em->saveEntity($document);
        }
        if ($parent->hasRelation('teams')) {
            $teams = [];
            foreach ($this->em->getRelation($parent, 'teams')->find() as $team) $teams[] = $team->getId();
            $relation = $this->em->getRelation($document, 'teams');
            $existing = [];
            foreach ($relation->find() as $team) {
                $existing[] = $team->getId();
                if (!in_array($team->getId(), $teams, true)) $relation->unrelate($team);
            }
            foreach (array_diff($teams, $existing) as $id) $relation->relateById($id);
        }
    }

    public function saved(Entity $record): void
    {
        if (!$this->eligible($record)) return;
        $binding = $this->ensure($record);
        $document = $this->em->getEntityById('Document', $binding->get('overviewDocumentId'));
        if ($document && !$record->isNew()) $this->syncAccess($record, $document);
    }

    public function removed(Entity $record): void
    {
        if (!$this->scopes->supports($record->getEntityType()) || $record->get('knowledgeRecordType')) return;
        $binding = $this->binding($record->getEntityType(), $record->getId());
        $document = $binding ? $this->em->getEntityById('Document', $binding->get('overviewDocumentId')) : null;
        if ($document) $this->em->removeEntity($document, ['knowledgeParentRemoval' => true]);
    }

    public function restored(Entity $record): void
    {
        $binding = $this->binding($record->getEntityType(), $record->getId());
        if (!$binding) { $this->ensure($record); return; }
        $this->em->getRDBRepository('Document')->restoreDeleted($binding->get('overviewDocumentId'));
        $document = $this->em->getEntityById('Document', $binding->get('overviewDocumentId'));
        if (!$document) throw new Conflict('Owned overview is missing.');
        $this->syncAccess($record, $document);
        $this->index->replace($document);
    }
}
