<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Note;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\Modules\Chatwoot\Tools\PostContent;

/** Keep the rich representation paired with the portable post used by other clients. */
class ValidatePostEditorState implements BeforeSave
{
    public static int $order = 8;

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->get('type') !== 'Post') return;
        if (!$entity->isNew() && $entity->isAttributeChanged('post') && !$entity->isAttributeChanged('postEditorState')) {
            // Older clients edit Markdown only; never show their stale rich document.
            $entity->set('postEditorState', null);
        }
        $state = $entity->get('postEditorState');
        if ($state === null || $state === '') return;
        if (!$entity->isNew() && !$entity->isAttributeChanged('postEditorState') && !$entity->isAttributeChanged('post')) return;
        PostContent::validate($entity->get('post') ?? '', $state);
    }
}
