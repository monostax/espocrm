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

namespace Espo\Modules\FeatureCredential\Tools\AgentEgress;

use Espo\Core\Exceptions\BadRequest;
use stdClass;

/**
 * Shared shape/format checks for Credential and OAuthAccount.agentEgress.
 */
class AgentEgressShape
{
    public const ENV_NAME_PATTERN = '/^[A-Z][A-Z0-9_]*$/';
    public const PATH_SEGMENT_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @return stdClass{enabled: bool, placeholderMode: string, secrets: list<stdClass>}|null
     * @throws BadRequest
     */
    public static function parseAndValidateShape(mixed $raw): ?stdClass
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw);
            if (!($decoded instanceof stdClass)) {
                throw new BadRequest('agentEgress must be a JSON object.');
            }
            $raw = $decoded;
        }

        if ($raw instanceof stdClass) {
            $egress = clone $raw;
        } elseif (is_array($raw)) {
            $egress = (object) $raw;
        } else {
            throw new BadRequest('agentEgress must be a JSON object.');
        }

        $enabled = !isset($egress->enabled) || $egress->enabled !== false;

        if (!$enabled) {
            $out = new stdClass();
            $out->enabled = false;
            $out->placeholderMode =
                ($egress->placeholderMode ?? null) === 'unique' ? 'unique' : 'shared';
            $out->secrets = [];

            return $out;
        }

        $placeholderMode =
            ($egress->placeholderMode ?? null) === 'unique' ? 'unique' : 'shared';

        $secretsRaw = $egress->secrets ?? null;

        if (!is_array($secretsRaw) && !($secretsRaw instanceof stdClass)) {
            throw new BadRequest(
                'agentEgress.secrets is required when injection is enabled.'
            );
        }

        $secretList = is_array($secretsRaw) ? $secretsRaw : array_values(get_object_vars($secretsRaw));

        if (count($secretList) === 0) {
            throw new BadRequest(
                'agentEgress must include at least one secret when injection is enabled.'
            );
        }

        $secrets = [];
        $seenEnv = [];

        foreach ($secretList as $index => $secretRaw) {
            if ($secretRaw instanceof stdClass) {
                $secret = $secretRaw;
            } elseif (is_array($secretRaw)) {
                $secret = (object) $secretRaw;
            } else {
                throw new BadRequest("agentEgress.secrets[{$index}] must be an object.");
            }

            $envName = isset($secret->envName) ? trim((string) $secret->envName) : '';
            $configPath = isset($secret->configPath) ? trim((string) $secret->configPath) : '';
            $hosts = self::normalizeHosts($secret->hosts ?? null);
            $replaceInQuery = !empty($secret->replaceInQuery);

            if ($envName === '' || !preg_match(self::ENV_NAME_PATTERN, $envName)) {
                throw new BadRequest(
                    "agentEgress.secrets[{$index}].envName must match " .
                    "^[A-Z][A-Z0-9_]*$ (e.g. ACCESS_TOKEN)."
                );
            }

            if (isset($seenEnv[$envName])) {
                throw new BadRequest(
                    "agentEgress envName '{$envName}' is duplicated."
                );
            }

            $seenEnv[$envName] = true;

            if ($configPath === '') {
                throw new BadRequest(
                    "agentEgress.secrets[{$index}].configPath is required."
                );
            }

            if (!self::isValidDotPath($configPath)) {
                throw new BadRequest(
                    "agentEgress.secrets[{$index}].configPath '{$configPath}' is invalid. " .
                    "Use dot segments of [A-Za-z_][A-Za-z0-9_]* (e.g. accessToken, data.someKey)."
                );
            }

            if (count($hosts) === 0) {
                throw new BadRequest(
                    "agentEgress.secrets[{$index}].hosts must include at least one host."
                );
            }

            $row = new stdClass();
            $row->envName = $envName;
            $row->configPath = $configPath;
            $row->hosts = $hosts;
            $row->replaceInQuery = $replaceInQuery;
            $secrets[] = $row;
        }

        $out = new stdClass();
        $out->enabled = true;
        $out->placeholderMode = $placeholderMode;
        $out->secrets = $secrets;

        return $out;
    }

    public static function isValidDotPath(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') ||
            str_starts_with($path, '.') || str_ends_with($path, '.')
        ) {
            return false;
        }

        $parts = explode('.', $path);

        if (count($parts) === 0) {
            return false;
        }

        foreach ($parts as $part) {
            if ($part === '' || !preg_match(self::PATH_SEGMENT_PATTERN, $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Walk a nested bag (array or stdClass) with a dotted path.
     *
     * @param array<string, mixed>|stdClass $bag
     */
    public static function readPath(array|stdClass $bag, string $path): ?string
    {
        $parts = array_values(array_filter(explode('.', $path), static fn ($p) => $p !== ''));
        $cur = $bag;

        foreach ($parts as $part) {
            if (is_array($cur)) {
                if (!array_key_exists($part, $cur)) {
                    return null;
                }
                $cur = $cur[$part];
                continue;
            }

            if ($cur instanceof stdClass) {
                if (!property_exists($cur, $part)) {
                    return null;
                }
                $cur = $cur->$part;
                continue;
            }

            return null;
        }

        if (is_string($cur) && $cur !== '') {
            return $cur;
        }

        if (is_int($cur) || is_float($cur) || is_bool($cur)) {
            return (string) $cur;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function normalizeHosts(mixed $hosts): array
    {
        if (is_string($hosts)) {
            $parts = preg_split('/[\s,]+/', trim($hosts)) ?: [];

            return array_values(array_filter(array_map('strval', $parts), static fn ($h) => $h !== ''));
        }

        if ($hosts instanceof stdClass) {
            $hosts = array_values(get_object_vars($hosts));
        }

        if (!is_array($hosts)) {
            return [];
        }

        $out = [];

        foreach ($hosts as $h) {
            if (!is_string($h) && !is_int($h) && !is_float($h)) {
                continue;
            }
            $s = trim((string) $h);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }
}
