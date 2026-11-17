<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\RecordQuery;

use Espo\Core\Exceptions\BadRequest;

/** A record query the person can fix: a stable code, where it went wrong and the offending word. */
class QueryError extends BadRequest
{
    public string $errorCode = '';
    public int $position = 0;
    public ?string $token = null;

    public static function at(string $code, int $position, ?string $token = null): self
    {
        $error = new self("Record query: $code at $position.");
        $error->errorCode = $code;
        $error->position = $position;
        $error->token = $token;
        return $error;
    }
}
