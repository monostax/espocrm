<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Hooks\Document;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\Modules\FeatureRecordKnowledge\Tools\Markdown;

class MarkdownSource
{
    public static int $order = 1;

    public function beforeSave(Entity $entity, array $options): void
    {
        if (($options['api'] ?? false) && ($entity->isNew()
            ? ($entity->get('knowledgeRecordType') || $entity->get('knowledgeRecordId') || $entity->get('bodyRevisionNumber'))
            : ($entity->isAttributeChanged('knowledgeRecordType') || $entity->isAttributeChanged('knowledgeRecordId') ||
                $entity->isAttributeChanged('bodyRevisionNumber')))) {
            throw new Forbidden('Overview identity and revision counters are server-managed.');
        }
        $mode = $entity->get('bodyAuthoringMode') ?: 'Lexical';
        if (!in_array($mode, ['Lexical', 'Markdown'], true)) throw new BadRequest('Invalid authoring mode.');
        if ($entity->get('knowledgeRecordType')) {
            if ($entity->get('contentType') !== 'Page' || $entity->get('bodyFormat') !== 'Markdown') {
                throw new BadRequest('Owned overviews must remain Markdown pages.');
            }
            $entity->set('body', Markdown::source($entity->get('body')));
        }
        if (!$entity->get('knowledgeRecordType') && !$entity->isNew() &&
            $entity->getFetched('bodyAuthoringMode') === 'Markdown' && $mode !== 'Markdown') {
            throw new BadRequest('Markdown source pages cannot be converted through a lossy editor.');
        }
        if ($mode !== 'Markdown') return;
        if (!$entity->isNew() && $entity->getFetched('bodyAuthoringMode') !== 'Markdown' &&
            $entity->getFetched('bodyFormat') !== 'Markdown' && !$entity->isAttributeChanged('body')) {
            throw new BadRequest('Supply Markdown source when changing authoring mode.');
        }
        $entity->set('body', Markdown::source($entity->get('body')));
        $entity->set('bodyFormat', 'Markdown');
        $entity->set('bodyEditorState', null);
    }

    public function beforeRemove(Entity $entity, array $options): void
    {
        if ($entity->get('knowledgeRecordType') && !($options['knowledgeParentRemoval'] ?? false)) {
            throw new Forbidden('Clear the overview by editing; its canonical document cannot be deleted.');
        }
    }
}
