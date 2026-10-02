<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\EditorReferences;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\ReferenceIndex;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\PromptPreparation;

/** Authenticated operations only; no generic reference-index CRUD. */
class EditorReference
{
    public function __construct(private EditorReferences $references, private ReferenceIndex $index, private PromptPreparation $prompts) {}

    public function getActionSearch(Request $request): object
    {
        return (object) ['list' => $this->references->search((string) $request->getQueryParam('q'))];
    }

    public function postActionResolve(Request $request): object
    {
        $refs = $request->getParsedBody()->references ?? null;
        if (!is_array($refs)) throw new BadRequest('References required.');
        return (object) ['list' => $this->references->resolve($refs)];
    }

    public function getActionBacklinks(Request $request): object
    {
        return (object) $this->index->list(
            (string) $request->getQueryParam('entityType'), (string) $request->getQueryParam('recordId'),
            (string) $request->getQueryParam('cursor'),
        );
    }

    public function postActionPrompt(Request $request): object
    {
        $body = $request->getParsedBody();
        return (object) ['prompt' => $this->prompts->prepare((string) ($body->membershipId ?? ''), (array) ($body->bindings ?? []))];
    }
}
