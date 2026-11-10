<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** The Chatwoot workspace a Catch Up request is for: its tenant and its opt-in policy. */
class Workspace
{
    public function __construct(
        private EntityManager $em,
        private SelectBuilderFactory $select,
        private User $user,
        private UserTenantResolver $tenants,
        private Rollout $rollout,
    ) {}

    /** @return array{tenant: Entity, policy: array, account: Entity} */
    public function resolve(Request $request): array
    {
        ['tenant' => $tenant, 'account' => $account] = $this->account($request);
        return ['tenant' => $tenant, 'policy' => $this->rollout->forAccount($account), 'account' => $account];
    }

    /**
     * The workspace's account and tenant, checked against the reader, without asking Chatwoot whether the
     * account opted in. For reads of what Em dia already stored (the conversation sidebar), which Chatwoot
     * makes only on opted-in accounts and which must not cost a call to Chatwoot each time a chat opens.
     *
     * @return array{tenant: Entity, account: Entity}
     */
    public function account(Request $request): array
    {
        $accountId = (string) $request->getQueryParam('accountId');
        if ($accountId === '' && is_string($request->getParsedBody()->accountId ?? null)) {
            $accountId = $request->getParsedBody()->accountId;
        }
        if (!ctype_digit($accountId) || (int) $accountId < 1) throw new BadRequest('Workspace is required.');
        $accounts = $this->select->create()->from('ChatwootAccount')->withStrictAccessControl()->buildQueryBuilder()
            ->where(['chatwootAccountId' => (int) $accountId])->limit(0, 2)->build();
        $accounts = iterator_to_array($this->em->getRDBRepository('ChatwootAccount')->clone($accounts)->find());
        if (count($accounts) !== 1) throw new Forbidden('Workspace is unavailable.');
        $tenantId = reset($accounts)->get('tenantId');
        if (!$tenantId || (!$this->user->isAdmin() && !in_array($tenantId, $this->tenants->resolveTenantIds($this->user), true))) {
            throw new Forbidden();
        }
        $tenant = $this->em->getEntityById('Tenant', (string) $tenantId);
        if (!$tenant) throw new Forbidden();
        return ['tenant' => $tenant, 'account' => reset($accounts)];
    }

    public static function now(Request $request): DateTimeImmutable
    {
        $zone = $request->getQueryParam('timeZone');
        if (!$zone && is_string($request->getParsedBody()->timeZone ?? null)) $zone = $request->getParsedBody()->timeZone;
        try {
            return new DateTimeImmutable('now', new DateTimeZone($zone ?: 'UTC'));
        } catch (\Exception) {
            throw new BadRequest('Invalid time zone.');
        }
    }

    /** Badge counts ask for a short page; the deck asks for a full one. */
    public static function limit(Request $request, int $default): int
    {
        $limit = $request->getQueryParam('limit');
        if ($limit === null || $limit === '') return $default;
        if (!ctype_digit((string) $limit) || (int) $limit < 1) throw new BadRequest('Invalid limit.');
        return min($default, (int) $limit);
    }
}
