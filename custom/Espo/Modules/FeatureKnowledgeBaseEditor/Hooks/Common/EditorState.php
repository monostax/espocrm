<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Hooks\Common;

use Espo\ORM\Entity;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\ReferenceIndex;

class EditorState
{
    public static int $order = 90;
    public function __construct(private ReferenceIndex $index) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        $fields = References::FIELDS[$entity->getEntityType()] ?? null;
        if (!$fields) return;
        [$projection, $state] = $fields;
        // Projection-only integrations invalidate the old canonical state.
        if (($entity->isAttributeChanged($projection) || $entity->isAttributeChanged('bodyFormat')) &&
            !$entity->isAttributeChanged($state)) $entity->set($state, null);
        if (!$entity->get($projection)) $entity->set($state, null);
        if ($entity->isAttributeChanged($state)) References::fromState($entity->get($state));
    }

    public function afterSave(Entity $entity, array $options): void
    {
        $fields = References::FIELDS[$entity->getEntityType()] ?? null;
        if (!$fields) return;
        if ($entity->isNew() || $entity->isAttributeChanged($fields[1]) || $entity->isAttributeChanged($fields[0])) {
            $this->index->replace($entity);
        }
    }

    public function afterRemove(Entity $entity, array $options): void
    {
        if (isset(References::FIELDS[$entity->getEntityType()])) $this->index->remove($entity);
    }
}
