<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Controllers;

use Espo\Core\Api\Request;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\ArticleRevisions;

/** Article history: list and restore only; no generic CRUD on the stored revisions. */
class KnowledgeBaseArticleRevision
{
    public function __construct(private ArticleRevisions $revisions) {}

    public function getActionList(Request $request): object
    {
        return (object) ['list' => $this->revisions->list((string) $request->getRouteParam('id'))];
    }

    public function postActionRestore(Request $request): object
    {
        return $this->revisions->restore((string) $request->getRouteParam('id'),
            (string) ($request->getParsedBody()->revisionId ?? ''));
    }
}
