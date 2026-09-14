<?php

namespace Espo\Modules\FeatureDocumentPages\Hooks\Document;

use Espo\Core\FieldValidation\Exceptions\ValidationError;
use Espo\Core\FieldValidation\Failure;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Markdown\Markdown;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * File validation also applies to API/ORM writes, independently of form dynamic logic.
 *
 * @implements BeforeSave<Entity>
 */
class PrepareContent implements BeforeSave
{
    public static int $order = 10;

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $contentType = $entity->get('contentType') ?: 'File';

        if (!in_array($contentType, ['File', 'Page'], true)) {
            throw ValidationError::create(new Failure('Document', 'contentType', 'valid'));
        }

        $entity->set('contentType', $contentType);

        if ($contentType === 'File' && !$entity->get('fileId')) {
            throw ValidationError::create(new Failure('Document', 'file', 'required'));
        }

        $format = $entity->get('bodyFormat') ?: 'Html';

        if (!in_array($format, ['Html', 'Markdown'], true)) {
            throw ValidationError::create(new Failure('Document', 'bodyFormat', 'valid'));
        }

        $entity->set('bodyFormat', $format);

        // Format-only API edits project the existing body instead of relabelling its syntax.
        if (!$entity->isNew() && $entity->isAttributeChanged('bodyFormat') &&
            !$entity->isAttributeChanged('body')) {
            $body = (string) ($entity->get('body') ?? '');
            $entity->set('body', $format === 'Markdown'
                ? (new HtmlConverter())->convert($body)
                : Markdown::transform($body));
        }

        $state = $entity->get('bodyEditorState');

        if ($state !== null && $state !== '' && $entity->isAttributeChanged('bodyEditorState')) {
            $decoded = is_string($state) ? json_decode($state, true) : null;

            if (!is_array($decoded) || ($decoded['root']['type'] ?? null) !== 'root' ||
                !is_array($decoded['root']['children'] ?? null)) {
                throw ValidationError::create(new Failure('Document', 'bodyEditorState', 'valid'));
            }
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('body') &&
            !$entity->isAttributeChanged('bodyFormat')) {
            return;
        }

        // API input filtering distinguishes omitted state from explicitly resubmitted state.
        // For direct ORM writes, discard stale state on a body-only edit.
        if (!$options->get(SaveOption::API) &&
            $entity->isAttributeChanged('body') && !$entity->isAttributeChanged('bodyEditorState') &&
            !$entity->isAttributeChanged('bodyFormat')) {
            $entity->set('bodyEditorState', null);
        }

        $body = (string) ($entity->get('body') ?? '');
        $html = $format === 'Markdown' ? Markdown::transform($body) : $body;

        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
        $html = preg_replace('~<(?:br|hr)\b[^>]*>|</(?:p|div|h[1-6]|li|tr|td|th|blockquote|pre)>~i', "\n", $html) ?? $html;
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $entity->set('bodyPlain', $plain !== '' ? $plain : null);

        if ($body === '') {
            $entity->set('bodyEditorState', null);
        }
    }
}
