<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\ORM\Entity;

/**
 * Single authority for the cross-tenant escape hatch.
 *
 * Tenant isolation in Journey/Automation reads is absolute by default: every
 * discovery query carries an explicit tenant predicate and an unresolved tenant
 * aborts the read (see TenantGuard::assertTenantScope).
 *
 * A genuinely instance-wide automation is still possible, but it must be opted
 * into deliberately and it must run under an identity that is actually allowed
 * to see every tenant:
 *
 *   1. `Automation.crossTenant` is true — settable only by an instance admin
 *      (enforced at save time by Hooks\Automation\ValidateCrossTenant), and
 *   2. the resolved run-as user is an admin / super-admin at run time.
 *
 * Both conditions are re-checked here on every run, so revoking the run-as
 * user's admin flag downgrades existing automations to tenant-scoped instead of
 * silently keeping instance-wide reach.
 */
class CrossTenantAccess
{
    public const FIELD = 'crossTenant';

    public function __construct(
        private Log $log,
    ) {}

    /**
     * Whether this run may skip the tenant predicate.
     */
    public function isAuthorized(Entity $automation, User $actor): bool
    {
        if (!$this->isFlagSet($automation)) {
            return false;
        }

        if (!$actor->isAdmin() && !$actor->isSuperAdmin()) {
            // Flag set but the identity cannot back it — stay tenant-scoped rather
            // than fail, so demoting a run-as user degrades safely.
            $this->log->warning(sprintf(
                'CrossTenantAccess: Automation %s requests crossTenant but run-as user %s '
                . 'is not an admin — falling back to tenant-scoped reads.',
                $automation->hasId() ? $automation->getId() : '(new)',
                $actor->getId(),
            ));

            return false;
        }

        $this->log->warning(sprintf(
            'CrossTenantAccess: Automation %s running WITHOUT a tenant predicate '
            . 'as admin run-as user %s (crossTenant opt-in).',
            $automation->hasId() ? $automation->getId() : '(new)',
            $actor->getId(),
        ));

        return true;
    }

    public function isFlagSet(Entity $automation): bool
    {
        return $automation->hasAttribute(self::FIELD) && (bool) $automation->get(self::FIELD);
    }
}
