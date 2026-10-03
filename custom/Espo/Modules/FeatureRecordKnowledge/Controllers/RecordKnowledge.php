<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Markdown\Markdown;
use Espo\Modules\FeatureRecordKnowledge\Services\Access;
use Espo\Modules\FeatureRecordKnowledge\Services\Knowledge;
use Espo\Modules\FeatureRecordKnowledge\Services\Relations;
use Espo\Modules\FeatureRecordKnowledge\Tools\Predicates;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;

/** All routes use Espo's normal authentication; no generic business-data CRUD. */
class RecordKnowledge
{
    public function __construct(private Knowledge $knowledge, private Relations $relations, private Scopes $scopes, private Access $access) {}

    private function identity(Request $request): array
    {
        return [(string) $request->getQueryParam('recordType'), (string) $request->getQueryParam('recordId')];
    }

    private function version(Request $request): ?int
    {
        $value = $request->getHeader('X-Version-Number');
        if ($value === null || $value === '') return null;
        if (!ctype_digit($value)) throw new BadRequest('Invalid X-Version-Number.');
        return (int) $value;
    }

    public function getActionSchema(Request $request): object
    {
        return (object) ['supportedScopes' => $this->scopes->all(), 'predicates' => (object) Predicates::schema(), 'spanEncoding' => 'utf8-bytes-end-exclusive'];
    }
    public function getActionOverview(Request $request): object { return (object) $this->knowledge->read(...$this->identity($request)); }
    public function putActionOverview(Request $request): object
    {
        [$type, $id] = $this->identity($request);
        $body = $request->getParsedBody();
        if (!property_exists($body, 'body')) throw new BadRequest('body is required.');
        return (object) $this->knowledge->write($type, $id, $body->body, $this->version($request));
    }
    public function getActionExport(Request $request): object { return (object) $this->knowledge->export(...$this->identity($request)); }
    public function postActionImport(Request $request): object
    {
        [$type, $id] = $this->identity($request);
        return (object) $this->knowledge->import($type, $id, $request->getParsedBody()->content ?? null, $this->version($request));
    }
    public function postActionPreview(Request $request): object
    {
        [$type, $id] = $this->identity($request);
        $this->access->record($type, $id);
        $this->knowledge->read($type, $id);
        $body = \Espo\Modules\FeatureRecordKnowledge\Tools\Markdown::source($request->getParsedBody()->body ?? null);
        return (object) ['html' => Markdown::transform($body)];
    }
    public function getActionRevision(Request $request): object { return (object) $this->knowledge->revision((string) $request->getQueryParam('id')); }
    public function getActionRevisions(Request $request): object
    {
        return (object) $this->knowledge->revisions((string) $request->getQueryParam('documentId'), (int) $request->getQueryParam('before'));
    }
    public function getActionRelations(Request $request): object
    {
        [$type, $id] = $this->identity($request);
        return (object) $this->relations->list($type, $id, (string) ($request->getQueryParam('direction') ?: 'all'),
            (string) $request->getQueryParam('status'), (string) $request->getQueryParam('cursor'));
    }
    public function postActionPropose(Request $request): object { return (object) $this->relations->submit($request->getParsedBody()); }
    public function postActionAuthor(Request $request): object { return (object) $this->relations->submit($request->getParsedBody(), true); }
    public function postActionDecide(Request $request): object
    {
        $body = $request->getParsedBody();
        return (object) $this->relations->decide((string) ($body->id ?? ''), (string) ($body->status ?? ''));
    }
}
