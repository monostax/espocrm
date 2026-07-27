<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\Error;
use Espo\Entities\User;

/**
 * Immutable tenant + actor frame for guarded formula reads.
 *
 * `record\*` is blocked in RestrictedFormulaRunner because the core
 * implementations read straight from EntityManager with no tenant predicate and
 * no ACL. The guarded replacement needs to know *whose* tenant and *which* user
 * a read is on behalf of — and it must not learn that from formula scope:
 * `assign` is an allowed function and writes into the shared variables object,
 * so a definition author could set `$tenantId` to a victim tenant before calling
 * the fetch. Same reasoning as journey\signal deriving tenant from the target
 * rather than trusting a tenantId argument.
 *
 * So the caller that owns the real tenant/actor (the action runner) opens a
 * frame around the formula run, and the fetch function reads it from here.
 *
 * Fail-closed: with no frame open there is no tenant to scope to and no user to
 * check ACL against, so guarded reads refuse rather than fall back to anything.
 *
 * Stack-based so nesting restores the outer frame. Request-local static, same
 * lifetime rules as JourneyEffectDepth: resets per PHP worker request, jobs get
 * a fresh stack.
 */
class FormulaReadScope
{
    /** Depth ceiling — guards against runaway nesting holding stale frames. */
    private const MAX_DEPTH = 8;

    /** @var list<array{tenantId: string, actor: User}> */
    private static array $stack = [];

    /**
     * Run `$fn` with reads scoped to `$tenantId` on behalf of `$actor`.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(string $tenantId, User $actor, callable $fn): mixed
    {
        if ($tenantId === '') {
            throw new Error('FormulaReadScope: tenantId required.');
        }

        $actorId = $actor->getId();
        if ($actorId === null || $actorId === '') {
            throw new Error('FormulaReadScope: actor must be a stored user.');
        }

        if (count(self::$stack) >= self::MAX_DEPTH) {
            throw new Error('FormulaReadScope: max nested scope depth exceeded.');
        }

        self::$stack[] = [
            'tenantId' => $tenantId,
            'actor' => $actor,
        ];

        try {
            return $fn();
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * Innermost frame, or throw when reads are not permitted.
     *
     * @return array{tenantId: string, actor: User}
     */
    public static function requireFrame(string $context = 'guarded read'): array
    {
        $frame = self::$stack === [] ? null : self::$stack[count(self::$stack) - 1];

        if ($frame === null) {
            throw new Error(
                "FormulaReadScope: {$context} attempted outside a tenant scope."
            );
        }

        return $frame;
    }

    public static function isActive(): bool
    {
        return self::$stack !== [];
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$stack = [];
    }
}
