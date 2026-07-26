<?php
/************************************************************************
 * Monostax – FeatureKnowledgeBaseEditor
 ************************************************************************/

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Hooks\KnowledgeBaseArticle;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Markdown\Markdown;
use Espo\Modules\Crm\Entities\KnowledgeBaseArticle;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\Tools\Email\Util as EmailUtil;

/**
 * Rebuild bodyPlain from Markdown→HTML when bodyFormat is Markdown.
 * Runs after core SetBodyPlain (stripHtml on raw body would mangle MD).
 *
 * @implements BeforeSave<KnowledgeBaseArticle>
 */
class SetBodyPlainMarkdown implements BeforeSave
{
    public static int $order = 20;

    private const ATTR_BODY = 'body';
    private const ATTR_BODY_PLAIN = 'bodyPlain';
    private const ATTR_BODY_FORMAT = 'bodyFormat';
    private const FORMAT_MARKDOWN = 'Markdown';

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $format = $entity->get(self::ATTR_BODY_FORMAT) ?: 'Html';

        if ($format !== self::FORMAT_MARKDOWN) {
            return;
        }

        if (
            !$entity->isAttributeChanged(self::ATTR_BODY) &&
            !$entity->isAttributeChanged(self::ATTR_BODY_FORMAT)
        ) {
            return;
        }

        $body = $entity->get(self::ATTR_BODY);

        if (!$body) {
            $entity->set(self::ATTR_BODY_PLAIN, null);

            return;
        }

        $html = Markdown::transform((string) $body);
        $entity->set(self::ATTR_BODY_PLAIN, EmailUtil::stripHtml($html) ?: null);
    }
}
