<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Chatwoot\Tools\RecordQuery\Compiler;
use Espo\Modules\Chatwoot\Tools\RecordQuery\QueryError;
use Espo\Modules\Chatwoot\Tools\RecordQuery\Schema;

/** Editor support for record queries: what can be named, and whether a query is valid before it is applied. */
class RecordQuery
{
    public function __construct(
        private Acl $acl,
        private Metadata $metadata,
        private Schema $schema,
        private Compiler $compiler,
    ) {}

    private function entityType(mixed $type): string
    {
        if (!is_string($type) || !$this->metadata->get(['scopes', $type, 'acl']) ||
            !$this->acl->checkScope($type, Acl\Table::ACTION_READ)) {
            throw new Forbidden();
        }
        return $type;
    }

    public function getActionSchema(Request $request): object
    {
        return $this->schema->describe($this->entityType($request->getQueryParam('entityType')));
    }

    public function postActionCheck(Request $request): object
    {
        $body = $request->getParsedBody();
        $type = $this->entityType($body->entityType ?? null);
        $timeZone = $body->timeZone ?? null;
        try {
            $this->compiler->compile($type, is_string($body->query ?? null) ? $body->query : '', is_string($timeZone) ? $timeZone : null);
        } catch (QueryError $error) {
            return (object) ['ok' => false, 'code' => $error->errorCode, 'position' => $error->position, 'token' => $error->token];
        }
        return (object) ['ok' => true];
    }
}
