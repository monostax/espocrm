<?php

declare(strict_types=1);

namespace Espo\Modules\GoogleGemini\Hooks\KnowledgeBaseCategory;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\GoogleGemini\Services\GeminiIndexingService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Turning AI search on for a category that already has published articles: index them into its new store.
 * Without this they stayed out of the AI's reach until someone edited each one.
 */
class IndexArticlesOnEnable
{
    public static int $order = 20;
    private const LIMIT = 500;

    public function __construct(private EntityManager $em, private GeminiIndexingService $indexing) {}

    public function afterSave(Entity $entity, array $options): void
    {
        if (!empty($options[SaveOption::SILENT]) || $entity->isNew() ||
            !$entity->isAttributeChanged('aiIndexEnabled') || !$entity->get('aiIndexEnabled')) return;
        $articles = $this->em->getRDBRepository('KnowledgeBaseCategory')->getRelation($entity, 'articles')
            ->select(['id'])->where(['status' => 'Published'])->limit(0, self::LIMIT)->find();
        foreach ($articles as $article) $this->indexing->queueArticleIndexing($article->getId(), 'update');
    }
}
