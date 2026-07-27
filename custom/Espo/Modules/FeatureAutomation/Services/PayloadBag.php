<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Exceptions\Error;

/**
 * Dot-path get/set on AutomationRunItem payload bags.
 * Engine-reserved roots (leading "_") cannot be written by setPayload/assign.
 */
class PayloadBag
{
    private const MAX_PATH_DEPTH = 12;

    private const MAX_PATH_LENGTH = 200;

    /**
     * @param array<string, mixed> $root
     */
    public function getByPath(array $root, string $path): mixed
    {
        $parts = $this->parsePath($path, false);
        $cur = $root;

        foreach ($parts as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }

        return $cur;
    }

    /**
     * @param array<string, mixed> $root
     * @return array<string, mixed>
     */
    public function setByPath(array $root, string $path, mixed $value, bool $merge = false): array
    {
        $parts = $this->parsePath($path, true);
        $ref = &$root;

        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if ($i === $last) {
                if (
                    $merge &&
                    is_array($value) &&
                    isset($ref[$part]) &&
                    is_array($ref[$part])
                ) {
                    $ref[$part] = array_replace_recursive($ref[$part], $value);
                } else {
                    $ref[$part] = $value;
                }

                break;
            }

            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }

        unset($ref);

        return $root;
    }

    /**
     * @return list<string>
     */
    public function parsePath(string $path, bool $forWrite): array
    {
        $path = trim($path);
        if ($path === '') {
            throw new Error('Payload path is required.');
        }
        if (strlen($path) > self::MAX_PATH_LENGTH) {
            throw new Error('Payload path is too long.');
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/', $path)) {
            throw new Error(
                "Invalid payload path '{$path}'. Use dot segments like vars.digest or report.totals."
            );
        }

        $parts = explode('.', $path);
        if (count($parts) > self::MAX_PATH_DEPTH) {
            throw new Error('Payload path is too deep.');
        }

        if ($forWrite) {
            $root = $parts[0];
            if (str_starts_with($root, '_')) {
                throw new Error("Cannot write reserved payload root '{$root}'.");
            }
        }

        return $parts;
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    public function normalize(mixed $raw): array
    {
        if ($raw instanceof \stdClass) {
            $raw = json_decode(json_encode($raw) ?: '{}', true) ?: [];
        }

        if (!is_array($raw)) {
            return [];
        }

        // list arrays allowed as values; root should be object map
        if (array_is_list($raw) && $raw !== []) {
            return ['_list' => $raw];
        }

        return $raw;
    }
}
