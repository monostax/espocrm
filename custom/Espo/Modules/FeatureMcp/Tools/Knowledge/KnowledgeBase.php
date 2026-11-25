<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMcp\Tools\Knowledge;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ReadParams;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Select\SearchParams;
use Espo\Entities\User;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\ArticleRevisions;
use Espo\Modules\FeatureMcp\Tools\Agents\AgentDirectory;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\Entity;

/**
 * The AI knowledge base over MCP, with the AI Session's rules (backend `code-mode/knowledge-base.ts`): anyone who
 * can edit an article by hand can change it, changes apply at once and every one is a revision that can be restored
 * (FeatureKnowledgeBaseEditor). Everything goes through the user's record services, so ACL, field access, validation
 * and the save hooks (AI search indexing, revisions) run as in the CRM. An MCP call has no workspace: articles are
 * the ones the user can read, and an article reaches the AI agents of its own tenant.
 */
class KnowledgeBase
{
    public const int READ_CHARS = 12000;
    public const int MAX_BODY_CHARS = 200000;
    private const int MAP_ARTICLES = 60;
    private const int SEARCH_ARTICLES = 8;
    private const int MAX_CATEGORIES = 200;
    private const array STATUSES = ['Published', 'Archived', 'Draft'];

    /** @var ?list<array{id: string, name: string, tenantId: ?string, parentId: ?string, aiSearch: bool}> */
    private ?array $categories = null;

    public function __construct(
        private ServiceFactory $serviceFactory,
        private User $user,
        private ArticleRevisions $revisions,
        private TenantResolver $tenantResolver,
        private AgentDirectory $agentDirectory,
    ) {}

    /** Without $q: categories, AI agents and article titles. With $q: matching articles and the passage. */
    public function map(?string $q, ?string $categoryId): array
    {
        $where = $categoryId ? [['type' => 'linkedWith', 'attribute' => 'categories', 'value' => [$categoryId]]] : [];
        $raw = [
            'where' => $where,
            'select' => ['id', 'name', 'status', 'modifiedAt', ...($q ? ['bodyPlain'] : [])],
            'maxSize' => $q ? self::SEARCH_ARTICLES : self::MAP_ARTICLES,
            'orderBy' => 'modifiedAt',
            'order' => 'desc',
        ] + ($q ? ['textFilter' => $q] : []);

        $articles = [];

        foreach ($this->service('KnowledgeBaseArticle')->find(SearchParams::fromRaw($raw))->getCollection() as $row) {
            $articles[] = [
                'articleId' => $row->getId(),
                'title' => (string) $row->get('name'),
                'status' => (string) $row->get('status'),
                'changedAt' => $row->get('modifiedAt'),
            ] + ($q ? ['passage' => self::snippet((string) $row->get('bodyPlain'), $q)] : []);
        }

        if ($q) {
            return ['articles' => $articles, 'complete' => count($articles) < self::SEARCH_ARTICLES] + ($articles ? [] :
                ['hint' => 'No article matches. Try fewer or different words, or call without q to see the titles.']);
        }

        $agents = $this->agents();

        return [
            'categories' => array_map(fn (array $category) => [
                'categoryId' => $category['id'],
                'name' => $category['name'],
                'parentCategoryId' => $category['parentId'],
                'aiSearch' => $category['aiSearch'],
                'usedByAgents' => array_values(array_map(fn ($agent) => $agent['name'], array_filter(
                    $agents,
                    fn ($agent) => in_array($category['id'], $agent['categoryIds'], true)
                ))),
            ], $this->categories()),
            'agents' => array_map(fn (array $agent) => [
                'agentId' => $agent['id'],
                'name' => $agent['name'],
                'workspace' => $agent['workspace'],
                'categoryIds' => $agent['categoryIds'],
            ], $agents),
            'articles' => $articles,
            'complete' => count($articles) < self::MAP_ARTICLES,
        ];
    }

    /** @throws NotFound|Forbidden */
    public function read(string $articleId, int $offset): array
    {
        $article = $this->article($articleId);
        $body = (string) $article->get('body');
        $part = mb_substr($body, $offset, self::READ_CHARS);
        $total = mb_strlen($body);

        return [
            'articleId' => $articleId,
            'title' => (string) $article->get('name'),
            'status' => (string) $article->get('status'),
            'format' => $article->get('bodyFormat') === 'Markdown' ? 'Markdown' : 'HTML',
            'version' => $article->get('versionNumber'),
            'text' => $part,
            'totalChars' => $total,
        ] + ($offset + self::READ_CHARS < $total ? ['nextOffset' => $offset + self::READ_CHARS] : [])
            + ($offset === 0 ? $this->reach($article) : []);
    }

    /** @throws NotFound|Forbidden */
    public function history(string $articleId): array
    {
        return ['articleId' => $articleId, 'changes' => $this->revisions->list($articleId)];
    }

    /**
     * @param ?list<object> $replacements
     * @param ?string[] $categoryIds
     * @throws NotFound|Forbidden|Conflict|BadRequest
     */
    public function update(
        string $articleId,
        int $version,
        ?array $replacements,
        ?string $title,
        ?string $status,
        ?array $categoryIds,
    ): array {

        $article = $this->article($articleId);
        $current = $article->get('versionNumber');

        if ($current !== null && (int) $current !== $version) {
            return ['status' => 'changed_meanwhile', 'version' => $current,
                'explanation' => 'The article changed since it was read. Read it again, then retry.'];
        }

        $patch = [];
        $changes = [];

        if ($replacements) {
            $outcome = Replacements::apply((string) $article->get('body'), $replacements);

            if (!$outcome['ok']) {
                unset($outcome['ok']);

                return ['status' => 'not_applied'] + $outcome;
            }

            if (mb_strlen($outcome['text']) > self::MAX_BODY_CHARS) {
                return ['status' => 'not_applied', 'problem' => 'too_long', 'explanation' => 'The article would be too long.'];
            }

            $patch['body'] = $outcome['text'];
            $changes = $outcome['changes'];
        }

        if ($title !== null && $title !== $article->get('name')) {
            $patch['name'] = $title;
        }

        if ($status !== null && $status !== $article->get('status')) {
            if (!in_array($status, self::STATUSES, true)) {
                throw new BadRequest('Unknown status.');
            }

            $patch['status'] = $status;
        }

        if ($categoryIds !== null) {
            $now = $article->getLinkMultipleIdList('categories');
            sort($now);
            $wanted = array_values(array_unique($categoryIds));
            sort($wanted);

            if ($now !== $wanted) {
                $problem = $this->categoryProblem($wanted, $article->get('tenantId'));

                if ($problem) {
                    return $problem;
                }

                $patch['categoriesIds'] = $wanted;
            }
        }

        if (!$patch) {
            return ['status' => 'nothing_to_change', 'explanation' => 'The article already says that.'];
        }

        $saved = $this->service('KnowledgeBaseArticle')
            ->update($articleId, (object) $patch, UpdateParams::create()->withVersionNumber(
                $current !== null ? (int) $current : null
            ))
            ->getEntity();

        $revisionId = array_intersect(['body', 'name', 'status'], array_keys($patch))
            ? ($this->revisions->list($articleId)[0]->id ?? null)
            : null;

        return [
            'status' => 'updated',
            'articleId' => $articleId,
            'title' => (string) $saved->get('name'),
            'articleStatus' => (string) $saved->get('status'),
            'version' => $saved->get('versionNumber'),
            'changed' => array_map(
                fn ($field) => ['body' => 'text', 'name' => 'title', 'categoriesIds' => 'categories'][$field] ?? $field,
                array_keys($patch)
            ),
        ] + ($changes ? ['textChanges' => $changes] : [])
            + (isset($patch['name']) ? ['titleBefore' => (string) $article->get('name')] : [])
            + (isset($patch['status']) ? ['statusBefore' => (string) $article->get('status')] : [])
            + $this->reach($saved)
            + ['undo' => $revisionId
                ? ['revisionId' => $revisionId, 'how' => 'To undo, restore this revision with KnowledgeBaseEdit.']
                : 'No earlier version was stored for this change; to undo, edit it back.'];
    }

    /**
     * @param string[] $categoryIds
     * @throws Forbidden|BadRequest
     */
    public function create(string $title, string $text, array $categoryIds, string $status): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new BadRequest('Unknown status.');
        }

        $categoryIds = array_values(array_unique($categoryIds));
        $tenantId = null;

        foreach ($this->categories() as $category) {
            if (in_array($category['id'], $categoryIds, true)) {
                $tenantId ??= $category['tenantId'];
            }
        }

        $problem = $this->categoryProblem($categoryIds, $tenantId);

        if ($problem) {
            return $problem;
        }

        $teamIds = array_values(array_filter(
            $this->user->getTeamIdList(),
            fn (string $teamId) => $tenantId === null || $this->tenantResolver->resolveFromTeamId($teamId) === $tenantId
        ));

        if (!$teamIds) {
            return ['status' => 'not_permitted',
                'explanation' => 'You have no team in the workspace of those categories to own the article.'];
        }

        $created = $this->service('KnowledgeBaseArticle')->create((object) [
            'name' => $title,
            'body' => $text,
            'bodyFormat' => 'Markdown',
            'status' => $status,
            'categoriesIds' => $categoryIds,
            'teamsIds' => [$teamIds[0]],
        ], CreateParams::create())->getEntity();

        return [
            'status' => 'created',
            'articleId' => $created->getId(),
            'title' => $title,
            'articleStatus' => $status,
        ] + $this->reach($created) + ['undo' => 'To take it back, set its status to Archived with KnowledgeBaseEdit.'];
    }

    /** @throws NotFound|Forbidden|BadRequest */
    public function restore(string $articleId, string $revisionId): array
    {
        $result = $this->revisions->restore($articleId, $revisionId);
        $article = $this->article($articleId);

        return [
            'status' => ($result->restored ?? false) ? 'restored' : 'already_same',
            'articleId' => $articleId,
            'title' => (string) $article->get('name'),
            'articleStatus' => (string) $article->get('status'),
            'version' => $article->get('versionNumber'),
        ] + $this->reach($article);
    }

    /** @return array{categories: list<array>, usedByAgents: string[], note: string} which agents find the article */
    private function reach(Entity $article): array
    {
        $own = array_values(array_filter(
            $this->categories(),
            fn ($category) => in_array($category['id'], $article->getLinkMultipleIdList('categories'), true)
        ));
        $searchable = array_column(array_filter($own, fn ($category) => $category['aiSearch']), 'id');
        $tenantId = $article->get('tenantId');
        $readers = array_values(array_filter(
            $this->agents(),
            fn ($agent) => ($tenantId === null || $agent['tenantId'] === $tenantId) &&
                array_intersect($agent['categoryIds'], $searchable)
        ));
        $published = $article->get('status') === 'Published';

        return [
            'categories' => array_map(fn ($category) => [
                'id' => $category['id'], 'name' => $category['name'], 'aiSearch' => $category['aiSearch'],
            ], $own),
            'usedByAgents' => $published ? array_column($readers, 'name') : [],
            'note' => match (true) {
                !$published => 'Not published: no AI agent finds it until it is published.',
                !$own => 'It is in no category, so no AI agent finds it. Put it in a category an agent uses.',
                !$searchable => 'None of its categories has AI search on, so no AI agent finds it.',
                !$readers => 'No AI agent uses its categories yet.',
                default => 'These agents find the new content after indexing, usually within a couple of minutes.',
            },
        ];
    }

    /**
     * Categories named in a write must be ones the user can read, all in the article's tenant.
     *
     * @param string[] $categoryIds
     * @return ?array<string, mixed>
     */
    private function categoryProblem(array $categoryIds, ?string $tenantId): ?array
    {
        $known = [];

        foreach ($this->categories() as $category) {
            $known[$category['id']] = $category;
        }

        foreach ($categoryIds as $categoryId) {
            $category = $known[$categoryId] ?? null;

            if (!$category || ($tenantId !== null && $category['tenantId'] !== $tenantId)) {
                return ['status' => 'not_applied', 'problem' => 'category_unavailable', 'categoryId' => $categoryId,
                    'explanation' => 'That category does not exist, you cannot open it, or it is in another workspace.'];
            }
        }

        return null;
    }

    /** @return list<array{id: string, name: string, tenantId: ?string, parentId: ?string, aiSearch: bool}> */
    private function categories(): array
    {
        if ($this->categories !== null) {
            return $this->categories;
        }

        $rows = $this->service('KnowledgeBaseCategory')->find(SearchParams::fromRaw([
            'select' => ['id', 'name', 'parentId', 'aiIndexEnabled', 'tenantId'],
            'maxSize' => self::MAX_CATEGORIES,
            'orderBy' => 'name',
            'order' => 'asc',
        ]))->getCollection();

        $this->categories = [];

        foreach ($rows as $row) {
            $this->categories[] = [
                'id' => $row->getId(),
                'name' => (string) $row->get('name'),
                'tenantId' => $row->get('tenantId'),
                'parentId' => $row->get('parentId'),
                'aiSearch' => (bool) $row->get('aiIndexEnabled'),
            ];
        }

        return $this->categories;
    }

    /** @return list<array{id: string, name: string, workspace: string, tenantId: ?string, categoryIds: string[]}> */
    private function agents(): array
    {
        return $this->agentDirectory->list();
    }

    /** @throws NotFound|Forbidden */
    private function article(string $articleId): Entity
    {
        return $this->service('KnowledgeBaseArticle')->read($articleId, ReadParams::create())->getEntity();
    }

    /** @return Service<Entity> */
    private function service(string $entityType): Service
    {
        return $this->serviceFactory->createForUser($entityType, $this->user);
    }

    private static function snippet(string $text, string $q): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $at = mb_stripos($text, $q);

        if ($at === false) {
            foreach (preg_split('/\s+/u', $q) ?: [] as $word) {
                $at = mb_strlen($word) > 2 ? mb_stripos($text, $word) : false;

                if ($at !== false) {
                    break;
                }
            }
        }

        $start = max(0, ($at === false ? 0 : $at) - 80);

        return ($start > 0 ? '…' : '') . mb_substr($text, $start, 240) . (mb_strlen($text) > $start + 240 ? '…' : '');
    }
}
