<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * What an article said before each change. The KeepRevision hook writes a revision on every save that changes the
 * title, text, format or status, whoever made it (a person in the editor, the API, the AI acting for a person).
 * Reading the history needs read access to the article and its text; restoring is an ordinary update, as the person.
 */
class ArticleRevisions
{
    public const FIELDS = ['name', 'status', 'bodyFormat', 'body'];
    public const KEEP = 30;
    private const LISTED = 10;

    public function __construct(private EntityManager $em, private Acl $acl, private ServiceContainer $records) {}

    /** @return list<object> newest first */
    public function list(string $articleId): array
    {
        $this->article($articleId);
        $list = [];
        $revisions = $this->em->getRDBRepository('KnowledgeBaseArticleRevision')->where(['articleId' => $articleId])
            ->order('number', 'DESC')->limit(0, self::LISTED)->find();
        foreach ($revisions as $revision) {
            $author = $revision->get('createdById') ? $this->em->getEntityById('User', $revision->get('createdById')) : null;
            $list[] = (object) [
                'id' => $revision->getId(),
                // When the change that replaced this content was saved, and by whom.
                'changedAt' => $revision->get('createdAt'),
                'changedByName' => $author?->get('name'),
                'name' => $revision->get('name'),
                'status' => $revision->get('status'),
                'bodyLength' => mb_strlen((string) $revision->get('body')),
            ];
        }
        return $list;
    }

    /** Puts the article back as the revision recorded it. The update itself becomes a new revision, so it can be undone. */
    public function restore(string $articleId, string $revisionId): object
    {
        if ($revisionId === '') throw new BadRequest('A revision is required.');
        $article = $this->article($articleId);
        if (!$this->acl->checkEntityEdit($article)) throw new Forbidden();
        $revision = $this->em->getEntityById('KnowledgeBaseArticleRevision', $revisionId);
        if (!$revision || $revision->get('articleId') !== $articleId) throw new NotFound();
        $patch = [];
        foreach (self::FIELDS as $field) {
            if ($revision->get($field) !== $article->get($field)) $patch[$field] = $revision->get($field);
        }
        if (!$patch) return (object) ['restored' => false, 'id' => $articleId];
        // The record service applies field-level access, validation and the save hooks (index, editor state).
        $saved = $this->records->get('KnowledgeBaseArticle')->update($articleId, (object) $patch, UpdateParams::create())->getEntity();
        return (object) [
            'restored' => true, 'id' => $articleId, 'fields' => array_keys($patch),
            'name' => $saved->get('name'), 'status' => $saved->get('status'), 'versionNumber' => $saved->get('versionNumber'),
        ];
    }

    private function article(string $id): Entity
    {
        $article = $id !== '' ? $this->em->getEntityById('KnowledgeBaseArticle', $id) : null;
        if (!$article || !$this->acl->checkEntityRead($article)) throw new NotFound();
        if (!$this->acl->checkField('KnowledgeBaseArticle', 'body')) throw new Forbidden();
        return $article;
    }
}
