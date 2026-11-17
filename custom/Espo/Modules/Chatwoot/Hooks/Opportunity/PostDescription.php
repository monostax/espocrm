<?php

namespace Espo\Modules\Chatwoot\Hooks\Opportunity;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * A new opportunity's description opens its stream as the first post, signed by
 * whoever created the opportunity — the same text that seeds its document.
 */
class PostDescription implements AfterSave
{
    // After the read-state anchor, so the post counts as unread for the assignee.
    public static int $order = 20;

    public function __construct(private EntityManager $entityManager, private Log $log) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew()) return;
        $description = $entity->get('description');
        if (!is_string($description) || trim($description) === '') return;
        try {
            $this->entityManager->createEntity('Note', [
                'type' => 'Post',
                'parentType' => 'Opportunity',
                'parentId' => $entity->getId(),
                'post' => trim($description),
                'isInternal' => true,
                'createdById' => $entity->get('createdById'),
            ]);
        } catch (Throwable $e) {
            // The opportunity itself must still be created.
            $this->log->warning('PostDescription: could not post on Opportunity/' . $entity->getId() . ': ' . $e->getMessage());
        }
    }
}
