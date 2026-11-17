<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Enrichment\EmailOwners;
use Espo\Modules\FeatureCredits\Enrichment\MobilePhones;
use Espo\Modules\FeatureCredits\Enrichment\QuoteProvider;

abstract class Supplier implements QuoteProvider
{
    /** Tries per lookup, and the pauses between them (jittered so parallel lookups do not collide again). */
    private const TRIES = 3;
    private const PAUSE_MS = [700, 1500];
    /** A resend needs at least this much of the attempt's lease left. */
    private const MINIMUM_MS = 5000;

    public function __construct(protected Credentials $credentials, protected Transport $transport, protected ?Log $log = null) {}
    abstract protected function provider(): string;
    abstract protected function eligible(string $type, string $product, array $identity): bool;
    abstract public function lookup(string $type, string $product, array $identity, int $timeoutMs = 30000): Result;

    public function revision(string $tenantId, string $targetType, string $product, array $identity): ?string
    {
        $connection = $this->credentials->get($this->provider());
        if (!$connection || !$this->eligible($targetType, $product, $identity)) return null;
        // Internal revision only: high-entropy credential rotation invalidates unstarted quotes.
        $terms = ['supplier-contract-2026-10-11-v1', 'retention-reuse-approved-max90d',
            $this->provider(), $tenantId, $targetType, $product, $connection];
        // Contact phones are mobile-only; earlier revisions and their unchecked sibling caches no longer apply.
        if ($targetType === 'Contact' && $product === 'phone') $terms[] = MobilePhones::REVISION;
        // Contact emails are the contact's own only; same retirement for unchecked sibling emails.
        if ($targetType === 'Contact' && $product === 'email') $terms[] = EmailOwners::REVISION;
        return hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR));
    }

    public function poll(string $requestId, string $subjectId, int $timeoutMs = 30000): Result
    {
        return new Result('ambiguous');
    }

    protected function connection(string $type, string $product, array $identity): array
    {
        return $this->eligible($type, $product, $identity) ? $this->credentials->get($this->provider()) : [];
    }

    protected function result(array $emails, array $phones, string $product, bool $br = false, ?string $dateOfBirth = null): Result
    {
        $values = $this->normalize($product === 'email' ? $emails : $phones, $product, $br);
        $sibling = $product === 'email' ? 'phone' : 'email';
        $hidden = $this->normalize($sibling === 'email' ? $emails : $phones, $sibling, $br);
        return new Result($values ? 'match' : 'no_match', $values, hidden: $hidden ? [$sibling => $hidden] : [], dateOfBirth: $dateOfBirth);
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
        if (in_array($response->status, [400, 401, 403, 422, 429], true)) return $this->rejected($response);
        if ($response->status !== 200 || $response->data === null) {
            $this->log?->warning('Enrichment supplier answer is unusable.', ['provider' => $this->provider(), 'status' => $response->status]);
            return new Result('ambiguous');
        }
        return null;
    }

    /** The supplier refused the lookup, so nothing was bought. Only the status and its own error code are logged. */
    protected function rejected(Response $response, int|string|null $code = null): Result
    {
        $this->log?->warning('Enrichment supplier rejected a lookup.', ['provider' => $this->provider(),
            'status' => $response->status, 'code' => $code]);
        return new Result('failed');
    }

    /**
     * Resend a refused lookup while the lease lasts. Suppliers throttle bursts by refusing the call outright;
     * a refusal buys nothing, so resending cannot pay twice. Unknown outcomes (ambiguous) are never resent.
     *
     * @param callable(int): array{Result, bool} $once returns the result and whether a refusal may be resent
     */
    protected function resending(int $timeoutMs, callable $once): Result
    {
        $started = hrtime(true);
        for ($try = 1; ; $try++) {
            $left = $timeoutMs - intdiv(hrtime(true) - $started, 1_000_000);
            [$result, $again] = $once(max(1, $left));
            if (!$again || $result->state !== 'failed' || $try >= self::TRIES) return $result;
            $pause = self::PAUSE_MS[$try - 1] + random_int(0, 400);
            if ($left - $pause < self::MINIMUM_MS) return $result;
            $this->transport->pause($pause);
        }
    }
}
