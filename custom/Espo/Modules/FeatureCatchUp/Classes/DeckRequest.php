<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Classes;

use Espo\Core\Api\RequestNull;
use stdClass;

/**
 * The parameters a deck sends (accountId, timeZone, cursor, limit, and a body for actions), for work that uses the
 * deck without an HTTP request: the onboarding first read. Feed, ServiceFeed and Workspace read only query parameters
 * and the body.
 */
class DeckRequest extends RequestNull
{
    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $body
     */
    public function __construct(private array $query, private array $body = []) {}

    public function withQuery(string $name, ?string $value): self
    {
        $query = $this->query;
        if ($value === null) {
            unset($query[$name]);
        } else {
            $query[$name] = $value;
        }
        return new self($query, $this->body);
    }

    public function hasQueryParam(string $name): bool
    {
        return array_key_exists($name, $this->query);
    }

    public function getQueryParam(string $name): ?string
    {
        return $this->query[$name] ?? null;
    }

    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function getMethod(): string
    {
        return $this->body ? 'POST' : 'GET';
    }

    public function getParsedBody(): stdClass
    {
        return (object) $this->body;
    }
}
