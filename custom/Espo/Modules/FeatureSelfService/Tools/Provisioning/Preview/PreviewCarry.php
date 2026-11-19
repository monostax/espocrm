<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureSelfService\Tools\Provisioning\Preview;

/**
 * What the monostax.ai site preview (/previa) built from a visitor's public website, as a new workspace starts
 * with it: the silent AI agent's instructions and knowledge, and the sales funnel's stages.
 *
 * The site serves it at GET /api/previa/{id} as `preview.carry` (contract version 1, see the site's
 * src/previa/shared.ts). It is AI output from third-party text, so everything is clamped here and an unusable
 * part is dropped rather than trusted.
 */
final class PreviewCarry
{
    public const VERSION = 1;
    public const MAX_OPEN_STAGES = 6;
    public const MAX_LOADING_LINES = 20;

    /**
     * @param list<array{title: string, body: string}> $knowledge
     * @param list<array{name: string, description: string}> $openStages In funnel order.
     * @param array{name: string, description: string} $won
     * @param array{name: string, description: string} $lost
     * @param list<string> $loadingLines The onboarding's import lines, written for this business (additive in
     *     version 1: older carries have none).
     */
    private function __construct(
        public readonly string $companyName,
        public readonly string $agentName,
        public readonly string $instructions,
        public readonly array $knowledge,
        public readonly array $openStages,
        public readonly array $won,
        public readonly array $lost,
        public readonly array $loadingLines = [],
    ) {}

    /**
     * The carry from the site's JSON, or null when it isn't one we can use (wrong version, no instructions and no
     * stages, or the "unavailable" marker provisioning stores after a failed fetch).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (($data['version'] ?? null) !== self::VERSION) {
            return null;
        }

        $agent = is_array($data['agent'] ?? null) ? $data['agent'] : [];
        $funnel = is_array($data['funnel'] ?? null) ? $data['funnel'] : [];

        $knowledge = [];

        foreach (is_array($agent['knowledge'] ?? null) ? $agent['knowledge'] : [] as $article) {
            $title = self::text($article['title'] ?? null, 100);
            $body = self::text($article['body'] ?? null, 4000, true);

            if ($title !== '' && $body !== '' && count($knowledge) < 5) {
                $knowledge[] = ['title' => $title, 'body' => $body];
            }
        }

        $open = [];
        $won = null;
        $lost = null;
        $names = [];

        foreach (is_array($funnel['stages'] ?? null) ? $funnel['stages'] : [] as $stage) {
            $name = self::text($stage['name'] ?? null, 60);
            $key = mb_strtolower($name);

            if ($name === '' || isset($names[$key]) || preg_match('/[<>]/', $name)) {
                continue;
            }

            $entry = ['name' => $name, 'description' => self::text($stage['description'] ?? null, 300)];
            $kind = $stage['kind'] ?? 'open';

            if ($kind === 'won' && !$won) {
                $won = $entry;
            } elseif ($kind === 'lost' && !$lost) {
                $lost = $entry;
            } elseif ($kind === 'open' && count($open) < self::MAX_OPEN_STAGES) {
                $open[] = $entry;
            } else {
                continue;
            }

            $names[$key] = true;
        }

        $instructions = self::text($agent['instructions'] ?? null, 6000, true);

        if ($instructions === '' && !$open) {
            return null;
        }

        return new self(
            self::text($data['companyName'] ?? null, 100),
            self::text($agent['name'] ?? null, 40),
            $instructions,
            $knowledge,
            $open,
            $won ?? ['name' => 'Ganho', 'description' => ''],
            $lost ?? ['name' => 'Perdido', 'description' => ''],
            self::lines($data['loadingLines'] ?? null),
        );
    }

    /**
     * @return list<string> Up to MAX_LOADING_LINES short single lines; anything else is dropped.
     */
    private static function lines(mixed $value): array
    {
        $lines = [];

        foreach (is_array($value) ? $value : [] as $line) {
            $line = self::text($line, 80);

            if ($line !== '' && !in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }

        return array_slice($lines, 0, self::MAX_LOADING_LINES);
    }

    /**
     * The carry as SelfServiceProvisioning.previewCarry keeps it (a JSON string, or decoded); null when there is none
     * or it was stored as unavailable.
     */
    public static function fromStored(mixed $value): ?self
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        } elseif (is_object($value)) {
            $value = json_decode((string) json_encode($value), true);
        }

        return is_array($value) ? self::fromArray($value) : null;
    }

    /**
     * The same shape the site serves, so a stored carry reads back through fromArray().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'companyName' => $this->companyName,
            'agent' => ['name' => $this->agentName, 'instructions' => $this->instructions, 'knowledge' => $this->knowledge],
            'funnel' => [
                'stages' => [
                    ...array_map(fn (array $stage) => [...$stage, 'kind' => 'open'], $this->openStages),
                    [...$this->won, 'kind' => 'won'],
                    [...$this->lost, 'kind' => 'lost'],
                ],
            ],
            ...($this->loadingLines !== [] ? ['loadingLines' => $this->loadingLines] : []),
        ];
    }

    private static function text(mixed $value, int $max, bool $multiline = false): string
    {
        if (!is_string($value)) {
            return '';
        }

        // Control characters out; newlines kept only where they mean something (instructions, article bodies).
        $value = (string) preg_replace($multiline ? '/[^\P{C}\n]+/u' : '/\p{C}+/u', $multiline ? '' : ' ', $value);
        $value = $multiline
            ? trim((string) preg_replace("/[ \t]+/u", ' ', $value))
            : trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, $max);
    }
}
