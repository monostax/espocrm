<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

/**
 * The AI's Markdown as WhatsApp formats it: *bold*, _italic_, ~strike~, `code`, ``` blocks, "- " lists and "> " quotes.
 * Headings become bold lines, tables become "a · b" lines, external links show their URL, and links WhatsApp can't
 * open (CRM mentions, app routes) keep only their label.
 */
class WhatsAppText
{
    /** Meta's limit for a text message is 4,096 characters; the rest is room for the "continues in the app" line. */
    public const CHUNK = 3900;
    public const MAX_CHUNKS = 3;

    public static function fromMarkdown(string $markdown): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        $kept = [];
        $keep = function (string $value) use (&$kept): string {
            $kept[] = $value;
            return "\x02" . (count($kept) - 1) . "\x03";
        };
        $text = (string) preg_replace_callback('/```[^\n]*\n(.*?)```/s', fn ($m) => $keep("```\n" . rtrim($m[1], "\n") . "\n```"), $text);
        $text = (string) preg_replace_callback('/`[^`\n]+`/', fn ($m) => $keep($m[0]), $text);

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $lines[] = "\x01" . str_replace(['**', '__'], '', $m[1]) . "\x01";
            } elseif (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
                $lines[] = '';
            } elseif (preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $line) && str_contains($line, '-')
                && str_contains($line, '|')) {
                continue;
            } elseif (preg_match('/^\s*\|(.*)\|\s*$/', $line, $m)) {
                $lines[] = implode(' · ', array_map('trim', explode('|', $m[1])));
            } else {
                $lines[] = (string) preg_replace('/^(\s*)[*+]\s+/', '$1- ', $line);
            }
        }
        $text = implode("\n", $lines);

        $text = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)[^)]*\)/', fn ($m) => $m[2], $text);
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)[^)]*\)/', function (array $m): string {
            $label = $m[1];
            $url = $m[2];
            if (!preg_match('~^https?://~i', $url)) return $label;
            $plain = trim(str_replace(['\\', '*', '_'], '', $label));
            return $plain === $url || $plain === preg_replace('~^https?://~i', '', $url) ? $url : "{$label} ({$url})";
        }, $text);

        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', "\x01$1\x01", $text);
        $text = (string) preg_replace('/(?<![\w\\\\])__(?=\S)(.+?)(?<=\S)__(?!\w)/s', "\x01$1\x01", $text);
        $text = (string) preg_replace('/(?<![\w*\\\\])\*(?=[^\s*])(.+?)(?<=[^\s*\\\\])\*(?![\w*])/', '_$1_', $text);
        $text = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '~$1~', $text);
        $text = str_replace("\x01", '*', $text);
        $text = (string) preg_replace('/\\\\([\\\\`*_{}\[\]()#+\-.!|~>])/', '$1', $text);
        $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));

        return (string) preg_replace_callback('/\x02(\d+)\x03/', fn ($m) => $kept[(int) $m[1]], $text);
    }

    /**
     * The text in messages of at most $limit characters, cut at paragraphs, then lines, then words.
     *
     * @return list<string>
     */
    public static function chunks(string $text, int $limit = self::CHUNK): array
    {
        $chunks = [];
        $current = '';
        foreach (self::pieces($text, $limit) as [$piece, $separator]) {
            $joined = $current === '' ? $piece : $current . $separator . $piece;
            if (mb_strlen($joined) <= $limit) {
                $current = $joined;
                continue;
            }
            if ($current !== '') $chunks[] = $current;
            $current = $piece;
        }
        if ($current !== '') $chunks[] = $current;
        return $chunks;
    }

    /** @return list<array{string, string}> pieces no longer than $limit, each with the separator that preceded it */
    private static function pieces(string $text, int $limit): array
    {
        $pieces = [];
        foreach (explode("\n\n", $text) as $paragraph) {
            if (mb_strlen($paragraph) <= $limit) {
                $pieces[] = [$paragraph, "\n\n"];
                continue;
            }
            $separator = "\n\n";
            foreach (explode("\n", $paragraph) as $line) {
                while (mb_strlen($line) > $limit) {
                    $cut = mb_strrpos(mb_substr($line, 0, $limit), ' ');
                    $cut = $cut === false || $cut < $limit / 2 ? $limit : $cut;
                    $pieces[] = [rtrim(mb_substr($line, 0, $cut)), $separator];
                    $line = ltrim(mb_substr($line, $cut));
                    $separator = ' ';
                }
                $pieces[] = [$line, $separator];
                $separator = "\n";
            }
        }
        return $pieces;
    }
}
