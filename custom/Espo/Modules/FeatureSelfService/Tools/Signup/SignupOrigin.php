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

namespace Espo\Modules\FeatureSelfService\Tools\Signup;

/**
 * Where a signup came from, as the monostax.ai signup link carries it (Self-Service PLG, site preview doc §11):
 *
 * - `job`: the funnel the visitor picked on the site: `whatsapp` (organize the number: connect, then the scan),
 *   `agente` (the AI agent: connect, then turn it on) or `funil` (the sales funnel: connect, then the deals);
 * - `connect`: the connect screen to open first, from how they said they use the number: `api` (Meta, with
 *   coexistence) or `qr`;
 * - `attribution`: the visit's UTMs and ad click ids, so the workspace record says which ad brought it.
 *
 * All of it is visitor input: anything outside the known values is dropped, never an error.
 */
final class SignupOrigin
{
    public const JOBS = ['whatsapp', 'agente', 'funil'];
    public const CONNECTS = ['api', 'qr'];
    /** The same keys the site keeps through its pages (landingPageContent.ts ATTRIBUTION_KEYS), plus fbclid. */
    public const ATTRIBUTION_KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id',
        'gclid', 'gbraid', 'wbraid', 'fbclid', 'keyword', 'search_term', 'matchtype', 'network', 'device',
    ];
    private const VALUE_MAX = 200;

    /**
     * @param array<string, string> $attribution
     */
    private function __construct(
        public readonly ?string $job,
        public readonly ?string $connect,
        public readonly array $attribution,
    ) {}

    public static function none(): self
    {
        return new self(null, null, []);
    }

    public static function fromInput(mixed $job, mixed $connect, mixed $attribution): self
    {
        $input = (is_object($attribution) || is_array($attribution)) ? (array) $attribution : [];
        $values = [];

        // In the known keys' order, so the same visit always gives the same request hash.
        foreach (self::ATTRIBUTION_KEYS as $key) {
            $value = $input[$key] ?? null;

            if (!is_string($value)) {
                continue;
            }

            $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

            if ($value !== '') {
                $values[$key] = mb_substr($value, 0, self::VALUE_MAX);
            }
        }

        return new self(
            in_array($job, self::JOBS, true) ? $job : null,
            in_array($connect, self::CONNECTS, true) ? $connect : null,
            $values,
        );
    }

    /**
     * What a stored record (signup, provisioning input) kept, read through the same filters.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return self::fromInput($data['job'] ?? null, $data['connect'] ?? null, $data['attribution'] ?? null);
    }

    public function isEmpty(): bool
    {
        return $this->job === null && $this->connect === null && $this->attribution === [];
    }

    /**
     * Only what is set, so a provisioning request without an origin keeps the hash it had before origins existed.
     *
     * @return array{job?: string, connect?: string, attribution?: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            ...($this->job !== null ? ['job' => $this->job] : []),
            ...($this->connect !== null ? ['connect' => $this->connect] : []),
            ...($this->attribution !== [] ? ['attribution' => $this->attribution] : []),
        ];
    }
}
