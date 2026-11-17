<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Hooks\KnowledgeBaseArticle;

use Espo\Entities\User;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\ArticleRevisions;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Keeps what the article said before a change to its title, text, format or status, so the change can be undone
 * (people edit by hand; the AI edits for a person from an AI chat). The newest ArticleRevisions::KEEP are kept.
 */
class KeepRevision
{
    public static int $order = 5;

    public function __construct(private EntityManager $em, private User $user) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        if ($entity->isNew()) return;
        $changed = array_filter(ArticleRevisions::FIELDS, fn ($field) => $entity->isAttributeChanged($field));
        if (!$changed) return;
        $before = [];
        foreach (ArticleRevisions::FIELDS as $field) $before[$field] = $entity->getFetched($field);
        $this->em->createEntity('KnowledgeBaseArticleRevision', [
            'articleId' => $entity->getId(), ...$before,
            'createdAt' => date('Y-m-d H:i:s'), 'createdById' => $this->user->getId(),
        ]);
        $stale = $this->em->getRDBRepository('KnowledgeBaseArticleRevision')->select(['id'])
            ->where(['articleId' => $entity->getId()])->order('number', 'DESC')
            ->limit(ArticleRevisions::KEEP, 100)->find();
        foreach ($stale as $revision) $this->em->getRDBRepository('KnowledgeBaseArticleRevision')->deleteFromDb($revision->getId());
    }

    public function afterRemove(Entity $entity, array $options): void
    {
        $delete = $this->em->getQueryBuilder()->delete()->from('KnowledgeBaseArticleRevision')
            ->where(['articleId' => $entity->getId()])->build();
        $this->em->getQueryExecutor()->execute($delete);
    }
}
