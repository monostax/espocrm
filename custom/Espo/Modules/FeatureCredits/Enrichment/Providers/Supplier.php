<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

use Espo\Modules\FeatureCredits\Enrichment\QuoteProvider;

abstract class Supplier implements QuoteProvider
{
    public function __construct(protected Credentials $credentials, protected Transport $transport) {}
    abstract protected function provider(): string;
    abstract protected function eligible(string $type, string $product, array $identity): bool;
    abstract public function lookup(string $type, string $product, array $identity, int $timeoutMs = 30000): Result;

    public function revision(string $tenantId, string $targetType, string $product, array $identity): ?string
    {
        $connection = $this->credentials->get($this->provider());
        if (!$connection || !$this->eligible($targetType, $product, $identity)) return null;
        // Internal revision only: high-entropy credential rotation invalidates unstarted quotes.
        return hash('sha256', json_encode(['supplier-contract-2026-10-11-v1', 'retention-reuse-approved-max90d',
            $this->provider(), $tenantId, $targetType, $product, $connection], JSON_THROW_ON_ERROR));
    }

    public function poll(string $requestId, string $subjectId, int $timeoutMs = 30000): Result
    {
        return new Result('ambiguous');
    }

    protected function connection(string $type, string $product, array $identity): array
    {
        return $this->eligible($type, $product, $identity) ? $this->credentials->get($this->provider()) : [];
    }

    protected function result(array $emails, array $phones, string $product, bool $br = false): Result
    {
        $values = $this->normalize($product === 'email' ? $emails : $phones, $product, $br);
        $sibling = $product === 'email' ? 'phone' : 'email';
        $hidden = $this->normalize($sibling === 'email' ? $emails : $phones, $sibling, $br);
        return new Result($values ? 'match' : 'no_match', $values, hidden: $hidden ? [$sibling => $hidden] : []);
    }

    private function normalize(array $items, string $product, bool $br): array
    {
        $values = [];
        foreach ($items as $value) {
            if (!is_string($value)) continue;
            $value = trim($value);
            if ($product === 'email') {
                if (str_contains($value, '*') || !filter_var($value, FILTER_VALIDATE_EMAIL)) continue;
            } else {
                // Do not turn masks, placeholders, extensions or arbitrary text into purchased numbers.
                if (!preg_match('/^\+?[0-9() .-]+$/D', $value)) continue;
                $digits = preg_replace('/[^0-9]/', '', $value);
                if ($br && !str_starts_with($value, '+') && in_array(strlen($digits), [10, 11], true)) $digits = '55' . $digits;
                elseif (!str_starts_with($value, '+')) continue;
                if (!preg_match('/^[1-9][0-9]{7,14}$/D', $digits)) continue;
                $value = '+' . $digits;
            }
            $values[] = $value;
        }
        return array_values(array_unique($values));
    }

    protected function httpFailure(Response $response): ?Result
    {
        if (in_array($response->status, [400, 401, 403, 422, 429], true)) return new Result('failed');
        if ($response->status !== 200 || $response->data === null) return new Result('ambiguous');
        return null;
    }
}
