<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Hooks\Note;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Entities\Note;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\EditorReferences;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;

/**
 * Most @-mentions are written in stream posts (opportunity and activity composers), which never
 * pass through EditorState. Log the record references the author added to a post as mentions.
 *
 * @implements AfterSave<Note>
 */
class LogMentions implements AfterSave
{
    public function __construct(private EditorReferences $references) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof Note || !$entity->isPost() || $options->get(SaveOption::SILENT) ||
            $options->get(SaveOption::IMPORT) || (!$entity->isNew() && !$entity->isAttributeChanged('post'))) return;
        try {
            $before = $entity->isNew() ? [] : self::records((string) $entity->getFetched('post'));
            $added = array_diff_key(self::records((string) $entity->getPost()), $before);
        } catch (BadRequest) {
            // Ranking history must never fail a post.
            return;
        }
        if ($added) $this->references->logMentions(array_values($added));
    }

    private static function records(string $post): array
    {
        if (!str_contains($post, '#crm-reference/')) return [];
        $refs = [];
        foreach (References::fromMarkdown($post) as $ref) {
            if ($ref['kind'] === 'record') $refs[References::url($ref)] = $ref;
        }
        return $refs;
    }
}
