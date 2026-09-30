<?php

declare(strict_types=1);

namespace Espo\Modules\FeaturePlaybook\Services;

use Espo\Core\Exceptions\BadRequest;
use stdClass;

/** The same bounded, plain-text definition is used for templates and ad hoc steps. */
class Definitions
{
    public static function name(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 200) {
            throw new BadRequest('A name of 1–200 characters is required.');
        }
        return trim($value);
    }

    public static function step(mixed $input, int $position): array
    {
        if (!$input instanceof stdClass || !in_array($input->kind ?? 'Check', ['Check', 'Task'], true)) {
            throw new BadRequest('A Check or Task step is required.');
        }
        $instructions = $input->instructions ?? '';
        $references = $input->references ?? [];
        if (!is_string($instructions) || mb_strlen($instructions) > 10000 ||
            !is_array($references) || count($references) > 10) {
            throw new BadRequest('Invalid step guidance.');
        }
        foreach ($references as $url) {
            if (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) ||
                !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw new BadRequest('References must be HTTP or HTTPS URLs.');
            }
        }
        return [
            'name' => self::name($input->name ?? null),
            'kind' => $input->kind ?? 'Check',
            'instructions' => $instructions,
            'references' => array_values($references),
            'position' => $position,
        ];
    }

    public static function steps(mixed $input): array
    {
        if (!is_array($input) || count($input) > 100) {
            throw new BadRequest('At most 100 steps are supported.');
        }
        return array_map(fn ($step, $position) => self::step($step, $position), $input, array_keys($input));
    }
}
