<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMcp\Tools\Knowledge;

/**
 * Exact-passage edits of a stored text (an article body, an agent's instructions), as the AI Session applies them
 * (backend `code-mode/knowledge-base.ts` applyReplacements): the model copies a passage and its replacement instead of
 * resending the whole text, and the format (HTML or Markdown) is kept as stored.
 */
class Replacements
{
    /**
     * @param list<object{find?: mixed, replace?: mixed, all?: mixed}> $replacements
     * @return array{ok: true, text: string, changes: list<array{before: string, after: string, occurrences: int}>}|
     *     array{ok: false, problem: string, find: string, occurrences?: int, explanation: string}
     */
    public static function apply(string $text, array $replacements): array
    {
        $changes = [];

        foreach ($replacements as $item) {
            $find = is_string($item->find ?? null) ? $item->find : '';
            $replace = is_string($item->replace ?? null) ? $item->replace : '';
            $all = ($item->all ?? false) === true;
            $occurrences = $find === '' ? 0 : substr_count($text, $find);

            if (!$occurrences) {
                return self::problem('not_found', $find, null,
                    'That passage is not in the stored text. Copy it exactly as read (the text may be HTML).');
            }

            if ($occurrences > 1 && !$all) {
                return self::problem('ambiguous', $find, $occurrences,
                    'That passage appears more than once. Include more surrounding text, or set all to change every one.');
            }

            if ($find === $replace) {
                return self::problem('unchanged', $find, null, 'The replacement equals the current text.');
            }

            $at = strpos($text, $find);
            $before = self::around($text, (int) $at, strlen($find));
            $text = $all
                ? str_replace($find, $replace, $text)
                : substr($text, 0, (int) $at) . $replace . substr($text, (int) $at + strlen($find));
            $changes[] = [
                'before' => $before,
                'after' => self::around($text, (int) $at, strlen($replace)),
                'occurrences' => $all ? $occurrences : 1,
            ];
        }

        return ['ok' => true, 'text' => $text, 'changes' => $changes];
    }

    /** @return array{ok: false, problem: string, find: string, occurrences?: int, explanation: string} */
    private static function problem(string $problem, string $find, ?int $occurrences, string $explanation): array
    {
        return ['ok' => false, 'problem' => $problem, 'find' => mb_strcut($find, 0, 200)] +
            ($occurrences ? ['occurrences' => $occurrences] : []) + ['explanation' => $explanation];
    }

    private static function around(string $text, int $at, int $length): string
    {
        $start = max(0, $at - 40);

        return mb_strcut($text, $start, $at - $start + $length + 40);
    }
}
