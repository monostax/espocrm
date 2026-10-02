<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Tools;

use Espo\Core\Exceptions\BadRequest;

/** Portable reference contract, shared by authoring, indexing and prompt preparation. */
class References
{
    public const TYPES = ['User', 'Account', 'Opportunity', 'Contact', 'KnowledgeBaseArticle', 'Document'];
    public const CONTEXT = [
        'currentOpportunity' => 'Current Opportunity',
        'opportunityOwner' => 'Opportunity owner',
        'primaryContact' => 'Primary contact',
    ];
    public const FIELDS = [
        'KnowledgeBaseArticle' => ['body', 'bodyEditorState'],
        'Document' => ['body', 'bodyEditorState'],
        'ChatwootAccountUserMembership' => ['aiPrompt', 'aiPromptEditorState'],
    ];

    public static function valid(mixed $ref): bool
    {
        return is_array($ref) && (
            (($ref['kind'] ?? null) === 'record' && in_array($ref['entityType'] ?? null, self::TYPES, true) &&
                is_string($ref['recordId'] ?? null) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $ref['recordId'])) ||
            (($ref['kind'] ?? null) === 'context' && is_string($ref['key'] ?? null) && isset(self::CONTEXT[$ref['key']]))
        );
    }

    public static function url(array $ref): string
    {
        return '#crm-reference/v1/' . ($ref['kind'] === 'record'
            ? "record/{$ref['entityType']}/{$ref['recordId']}" : "context/{$ref['key']}");
    }

    public static function fromUrl(string $url): ?array
    {
        if (!str_starts_with($url, '#crm-reference/v1/')) return null;
        $parts = explode('/', substr($url, strlen('#crm-reference/v1/')));
        $ref = match (true) {
            count($parts) === 3 && $parts[0] === 'record' => ['kind' => 'record', 'entityType' => $parts[1], 'recordId' => $parts[2]],
            count($parts) === 2 && $parts[0] === 'context' => ['kind' => 'context', 'key' => $parts[1]],
            default => null,
        };
        return self::valid($ref) ? $ref : null;
    }

    public static function fromState(?string $state): array
    {
        if (!$state) return [];
        if (strlen($state) > 2000000) throw new BadRequest('Editor state is too large.');
        try { $data = json_decode($state, true, 100, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new BadRequest('Invalid editor state.'); }
        if (!is_array($data['root']['children'] ?? null)) throw new BadRequest('Invalid editor state root.');
        $refs = [];
        $walk = function (array $node) use (&$walk, &$refs): void {
            if (($node['type'] ?? null) === 'crm-mention') {
                if (($node['version'] ?? null) !== 1 || !self::valid($node['reference'] ?? null)) {
                    throw new BadRequest('Invalid editor reference.');
                }
                $ref = $node['reference'];
                $refs[self::url($ref)] = $ref;
                if (count($refs) > 200) throw new BadRequest('At most 200 distinct references per document.');
            }
            foreach ($node['children'] ?? [] as $child) {
                if (!is_array($child)) throw new BadRequest('Invalid editor node.');
                $walk($child);
            }
        };
        $walk($data['root']);
        return array_values($refs);
    }
}
