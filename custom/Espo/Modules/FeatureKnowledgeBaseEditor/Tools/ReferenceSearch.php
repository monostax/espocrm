<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Tools;

use Espo\Core\Utils\Language;

class ReferenceSearch
{
    public function __construct(private Language $language) {}

    /** @return array{types: string[], terms: string[]} */
    public function parse(string $query, array $types): array
    {
        $terms = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = array_map(self::normalize(...), $terms);
        $matches = [];
        $consumed = [];
        foreach ($types as $type) {
            $aliases = [$type, $this->language->translateLabel($type, 'scopeNames'),
                $this->language->translateLabel($type, 'scopeNamesPlural')];
            // Accept these common Portuguese qualifiers even with an English UI.
            $aliases = [...$aliases, ...match ($type) {
                'Opportunity' => ['opportunities', 'oportunidade', 'oportunidades'],
                'Contact' => ['contacts', 'contato', 'contatos'],
                default => [],
            }];
            foreach (array_unique($aliases) as $alias) {
                $words = preg_split('/\s+/u', self::normalize($alias), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (!$words) continue;
                for ($i = 0; $i <= count($terms) - count($words); $i++) {
                    if (array_slice($normalized, $i, count($words)) !== $words) continue;
                    $matches[$type] = true;
                    foreach (range($i, $i + count($words) - 1) as $index) $consumed[$index] = true;
                }
            }
        }
        // A label on its own remains a name search, avoiding surprising empty-query browsing.
        if (!$consumed || count($consumed) === count($terms)) return ['types' => $types, 'terms' => $terms];
        return ['types' => array_keys($matches), 'terms' => array_values(array_diff_key($terms, $consumed))];
    }

    public static function pattern(string $term): string
    {
        return '%' . strtr($term, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    }

    /** Literal prefix at the start of any Unicode word, not just the name. */
    public static function wordPattern(string $term): string
    {
        return '(^|[^[:alnum:]_])' . preg_quote($term, '~');
    }

    /** InnoDB does not index these default stopwords, or words shorter than three characters. */
    public static function fullTextTerm(string $term): ?string
    {
        $stopwords = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en',
            'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that',
            'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];
        return preg_match('/^[\p{L}\p{N}_]{3,84}$/u', $term) &&
            !in_array(self::normalize($term), $stopwords, true) ? '+' . $term . '*' : null;
    }

    private static function normalize(string $value): string
    {
        return strtr(mb_strtolower(trim($value)), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
    }
}
