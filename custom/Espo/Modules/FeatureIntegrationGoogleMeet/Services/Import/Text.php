<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import;

/** Literal text -> ordinary Lexical nodes and matching Markdown. No HTML or media fetches. */
class Text
{
    public static function tabs(array $document): array
    {
        $result = [];
        $walk = function (array $tabs, ?string $parent, int $depth) use (&$walk, &$result): void {
            // Google supplies stable IDs and source indices; titles are never selection keys.
            usort($tabs, static fn ($a, $b) => ($a['tabProperties']['index'] ?? 0) <=> ($b['tabProperties']['index'] ?? 0));
            foreach ($tabs as $tab) {
                $id = $tab['tabProperties']['tabId'] ?? null;
                if (!is_string($id) || isset($result[$id]) || !isset($tab['documentTab'])) throw new Failure('invalidSource');
                $result[$id] = ['id' => $id, 'title' => $tab['tabProperties']['title'] ?? '', 'parentId' => $parent,
                    'depth' => $depth, 'content' => $tab['documentTab']];
                $walk($tab['childTabs'] ?? [], $id, $depth + 1);
            }
        };
        $walk($document['tabs'] ?? [], null, 0);
        return $result;
    }

    public static function escape(string $text): string
    {
        self::valid($text);
        return preg_replace('/([\\\\`*_{}\[\]()<>#+.!|~\-])/u', '\\\\$1', $text);
    }

    private static function valid(string $text): void
    {
        if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) throw new Failure('invalidSource');
    }

    public static function node(string $text, string $type = 'paragraph', ?string $tag = null): array
    {
        self::valid($text);
        $children = [];
        foreach (explode("\n", $text) as $index => $line) {
            if ($index) $children[] = ['type' => 'linebreak', 'version' => 1];
            if ($line !== '') $children[] = ['type' => 'text', 'version' => 1, 'text' => $line, 'format' => 0,
                'style' => '', 'mode' => 'normal', 'detail' => 0];
        }
        $node = ['type' => $type, 'version' => 1, 'children' => $children, 'direction' => null, 'format' => '', 'indent' => 0];
        if ($tag) $node['tag'] = $tag;
        if ($type === 'paragraph') $node += ['textFormat' => 0, 'textStyle' => ''];
        return $node;
    }

    public static function section(string $label): array
    {
        return ['nodes' => [self::node($label, 'heading', 'h2')], 'markdown' => '## ' . self::escape($label) . "\n\n", 'warnings' => []];
    }

    public static function add(array &$output, array $node, string $markdown): void
    {
        $output['nodes'][] = $node;
        $output['markdown'] .= $markdown . "\n\n";
        if (strlen($output['markdown']) > 2000000) throw new Failure('tooLarge');
    }

    public static function notes(array $tab, string $source): array
    {
        $out = self::section($source . ' — ' . $tab['title']);
        $counters = [];
        self::elements($tab['content']['body']['content'] ?? [], $out, $tab['content']['lists'] ?? [], $counters);
        if (!empty($tab['content']['inlineObjects']) || !empty($tab['content']['positionedObjects'])) $out['warnings'][] = 'mediaOmitted';
        $out['warnings'] = array_values(array_unique($out['warnings']));
        return $out;
    }

    private static function elements(array $elements, array &$out, array $lists, array &$counters): void
    {
        foreach ($elements as $element) {
            if (isset($element['paragraph'])) {
                $paragraph = $element['paragraph'];
                $text = '';
                foreach ($paragraph['elements'] ?? [] as $run) {
                    if (isset($run['textRun']['content'])) $text .= $run['textRun']['content'];
                    elseif (isset($run['person']['personProperties']['name'])) $text .= $run['person']['personProperties']['name'];
                    elseif (isset($run['richLink']['richLinkProperties']['title'])) $text .= $run['richLink']['richLinkProperties']['title'];
                    elseif (!isset($run['startIndex']) || count($run) > 2) $out['warnings'][] = 'mediaOmitted';
                }
                $text = preg_replace('/\n$/D', '', $text); // Docs' paragraph terminator is structural.
                $style = $paragraph['paragraphStyle']['namedStyleType'] ?? '';
                if (preg_match('/^HEADING_([1-6])$/D', $style, $match)) {
                    self::add($out, self::node($text, 'heading', 'h' . $match[1]), str_repeat('#', (int) $match[1]) . ' ' . self::escape($text));
                } elseif (isset($paragraph['bullet'])) {
                    $bullet = $paragraph['bullet'];
                    $level = (int) ($bullet['nestingLevel'] ?? 0);
                    $properties = $lists[$bullet['listId'] ?? '']['listProperties']['nestingLevels'][$level] ?? [];
                    $ordered = isset($properties['glyphType']) && $properties['glyphType'] !== 'GLYPH_TYPE_UNSPECIFIED';
                    $item = self::node($text, 'listitem');
                    $listId = $bullet['listId'] ?? '';
                    $item['value'] = $counters[$listId][$level] ?? (int) ($properties['startNumber'] ?? 1);
                    $counters[$listId][$level] = $item['value'] + 1;
                    foreach (array_keys($counters[$listId]) as $nested) {
                        if ($nested > $level) unset($counters[$listId][$nested]);
                    }
                    $list = ['type' => 'list', 'version' => 1, 'children' => [$item], 'direction' => null,
                        'format' => '', 'indent' => $level, 'listType' => $ordered ? 'number' : 'bullet',
                        'tag' => $ordered ? 'ol' : 'ul', 'start' => $item['value']];
                    self::add($out, $list, str_repeat('  ', $level) . ($ordered ? $item['value'] . '. ' : '- ') . self::escape($text));
                } else self::add($out, self::node($text), self::escape($text));
                if (!empty($paragraph['positionedObjectIds'])) $out['warnings'][] = 'mediaOmitted';
            } elseif (isset($element['table'])) {
                foreach ($element['table']['tableRows'] ?? [] as $row) {
                    foreach ($row['tableCells'] ?? [] as $index => $cell) {
                        self::add($out, self::node('[' . ($index + 1) . ']'), '\[' . ($index + 1) . '\]');
                        self::elements($cell['content'] ?? [], $out, $lists, $counters);
                    }
                }
            } elseif (isset($element['tableOfContents'])) {
                self::elements($element['tableOfContents']['content'] ?? [], $out, $lists, $counters);
            }
        }
    }

    public static function append(?string $state, string $body, array $parts): array
    {
        if ($state === null) {
            if ($body !== '') throw new Failure('editorRequired');
            $tree = ['root' => ['type' => 'root', 'version' => 1, 'children' => [], 'direction' => null, 'format' => '', 'indent' => 0]];
        } else {
            try { $tree = json_decode($state, true, 512, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { throw new Failure('editorRequired'); }
            if (($tree['root']['type'] ?? null) !== 'root' || !is_array($tree['root']['children'] ?? null)) throw new Failure('editorRequired');
        }
        $warnings = [];
        foreach ($parts as $part) {
            array_push($tree['root']['children'], ...$part['nodes']);
            $body .= ($body === '' ? '' : "\n\n") . $part['markdown'];
            $warnings = array_merge($warnings, $part['warnings']);
            if (strlen($body) > 2000000) throw new Failure('tooLarge');
        }
        return ['body' => $body, 'bodyEditorState' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'warnings' => array_values(array_unique($warnings))];
    }
}
