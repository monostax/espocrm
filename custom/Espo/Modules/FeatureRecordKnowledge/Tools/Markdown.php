<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Tools;

use Espo\Core\Exceptions\BadRequest;

class Markdown
{
    /** Validate, never trim, convert syntax, strip frontmatter or normalize whitespace. */
    public static function source(mixed $body): string
    {
        if ($body === null) return '';
        if (!is_string($body) || strlen($body) > 2000000 || !mb_check_encoding($body, 'UTF-8') || str_contains($body, "\0")) {
            throw new BadRequest('Markdown must be UTF-8 text of at most 2 MB.');
        }
        return $body;
    }

    public static function export(string $body, string $type, string $id, string $documentId): string
    {
        return '<!-- crm-record-knowledge/v1 ' . json_encode([
            'recordType' => $type, 'recordId' => $id, 'documentId' => $documentId,
        ], JSON_UNESCAPED_SLASHES) . " -->\n" . $body;
    }

    public static function import(string $artifact, string $type, string $id, string $documentId): string
    {
        if (!preg_match('/\A<!-- crm-record-knowledge\/v1 ([^\r\n]+) -->\r?\n/', $artifact, $match)) {
            throw new BadRequest('Missing record knowledge identity metadata.');
        }
        $identity = json_decode($match[1], true);
        if (!is_array($identity) || count($identity) !== 3 || ($identity['recordType'] ?? null) !== $type ||
            ($identity['recordId'] ?? null) !== $id || ($identity['documentId'] ?? null) !== $documentId) {
            throw new BadRequest('Markdown belongs to another record or document.');
        }
        return self::source(substr($artifact, strlen($match[0])));
    }
}
