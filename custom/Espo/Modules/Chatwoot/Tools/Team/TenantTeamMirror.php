<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Tools\Team;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\TenantTeamProvisioner;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Keeps every ChatwootTeam 1:1 with a CRM Team of its Tenant, named
 * "{tenantName} / {chatwootTeamName}" (Chatwoot "Comercial" in tenant "Acme"
 * -> CRM Team "Acme / Comercial").
 *
 * IDENTITY
 * --------
 * The CRM Team is found by a unique provisioning key built from the
 * ChatwootTeam record id, never by name: two tenants may share a name, and a
 * Chatwoot team may be called "Admin" like the tenant's own admin team.
 *
 * NAME
 * ----
 * The name is derived, not editable: it is rewritten whenever the Chatwoot
 * team or the Tenant is renamed, so the pair cannot drift apart.
 *
 * TENANCY AND ACCESS
 * ------------------
 * The Team is registered under `Tenant.otherUserTeams`, which is what every
 * team->tenant resolver matches (see TenantAdminTeamProvisioner). It holds NO
 * role: tenant users get their role from the base team, and roles merge to the
 * most permissive grant, so a role here could only widen access. Membership is
 * not managed here.
 */
class TenantTeamMirror
{
    public const KEY_PREFIX = 'chatwoot-team:';

    private const SEPARATOR = ' / ';

    /** Team.name is varchar(100). */
    private const TEAM_NAME_MAX_LENGTH = 100;

    /** Tenant names keep at least this many characters when the name is clamped. */
    private const TENANT_NAME_MIN_BUDGET = 30;

    public function __construct(
        private EntityManager $entityManager,
        private TenantTeamProvisioner $teamProvisioner,
        private TenantResolver $tenantResolver,
        private Log $log,
    ) {}

    public static function provisioningKey(string $chatwootTeamRecordId): string
    {
        return self::KEY_PREFIX . $chatwootTeamRecordId;
    }

    /**
     * Idempotent. Returns the CRM Team id, or null when the Chatwoot team has
     * no name or its tenant cannot be told with certainty.
     */
    public function ensureFor(Entity $chatwootTeam): ?string
    {
        $tenant = $this->resolveTenant($chatwootTeam);

        if (!$tenant) {
            $this->log->warning(sprintf(
                'Chatwoot: ChatwootTeam %s resolves to no single Tenant; no CRM team mirrored.',
                (string) $chatwootTeam->getId(),
            ));

            return null;
        }

        return $this->ensure($chatwootTeam, $tenant);
    }

    /**
     * Ensures the CRM Team of every ChatwootTeam of the Tenant's Chatwoot
     * accounts. Returns how many teams are mirrored.
     */
    public function ensureForTenant(Entity $tenant): int
    {
        $accountIds = [];

        $accounts = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->select(['id'])
            ->where(['tenantId' => $tenant->getId()])
            ->find();

        foreach ($accounts as $account) {
            $accountIds[] = (string) $account->getId();
        }

        if ($accountIds === []) {
            return 0;
        }

        $count = 0;

        $chatwootTeams = $this->entityManager
            ->getRDBRepository('ChatwootTeam')
            ->where(['accountId' => $accountIds])
            ->find();

        foreach ($chatwootTeams as $chatwootTeam) {
            if ($this->ensure($chatwootTeam, $tenant) !== null) {
                $count++;
            }
        }

        return $count;
    }

    public function findTeamId(Entity $chatwootTeam): ?string
    {
        $team = $this->teamProvisioner->findTeam(self::provisioningKey((string) $chatwootTeam->getId()));

        return $team ? (string) $team->getId() : null;
    }

    /**
     * Removes the CRM Team of a deleted ChatwootTeam.
     */
    public function removeFor(Entity $chatwootTeam): void
    {
        $team = $this->teamProvisioner->findTeam(self::provisioningKey((string) $chatwootTeam->getId()));

        if (!$team) {
            return;
        }

        $tenants = $this->entityManager
            ->getRDBRepository('Team')
            ->getRelation($team, 'tenantsAsOtherUserTeam');

        foreach ($tenants->find() as $tenant) {
            $tenants->unrelate($tenant, ['skipHooks' => true]);
        }

        $this->entityManager->removeEntity($team);

        $this->log->info(sprintf(
            'Chatwoot: removed CRM team %s of deleted ChatwootTeam %s.',
            (string) $team->getId(),
            (string) $chatwootTeam->getId(),
        ));
    }

    /**
     * Tenant.name is varchar(100) and ChatwootTeam.name varchar(255), so the
     * joined name can overflow Team.name. The tenant part gives way first (down
     * to a readable minimum), then the whole name is cut.
     */
    public function teamName(string $tenantName, string $chatwootTeamName): string
    {
        $name = $tenantName . self::SEPARATOR . $chatwootTeamName;

        if (mb_strlen($name) <= self::TEAM_NAME_MAX_LENGTH) {
            return $name;
        }

        $budget = max(
            self::TEAM_NAME_MAX_LENGTH - mb_strlen(self::SEPARATOR) - mb_strlen($chatwootTeamName),
            self::TENANT_NAME_MIN_BUDGET,
        );

        if (mb_strlen($tenantName) > $budget) {
            $tenantName = rtrim(mb_substr($tenantName, 0, $budget));
        }

        return rtrim(mb_substr($tenantName . self::SEPARATOR . $chatwootTeamName, 0, self::TEAM_NAME_MAX_LENGTH));
    }

    private function ensure(Entity $chatwootTeam, Entity $tenant): ?string
    {
        $chatwootTeamName = trim((string) ($chatwootTeam->get('name') ?? ''));
        $tenantName = trim((string) ($tenant->get('name') ?? ''));

        if ($chatwootTeamName === '' || $tenantName === '') {
            return null;
        }

        $name = $this->teamName($tenantName, $chatwootTeamName);

        $team = $this->teamProvisioner->ensureTeam(
            self::provisioningKey((string) $chatwootTeam->getId()),
            $name,
        );

        if ((string) ($team->get('name') ?? '') !== $name) {
            $team->set('name', $name);

            $this->entityManager->saveEntity($team, ['skipHooks' => true, 'silent' => true]);
        }

        $teamId = (string) $team->getId();

        $otherUserTeams = $this->entityManager
            ->getRDBRepository('Tenant')
            ->getRelation($tenant, 'otherUserTeams');

        if (!$otherUserTeams->isRelatedById($teamId)) {
            $otherUserTeams->relateById($teamId, null, ['skipHooks' => true]);
        }

        return $teamId;
    }

    /**
     * The account's own tenant, else the single tenant its teams belong to.
     * Ambiguity is a refusal: a team filed under a guessed tenant would hand
     * that tenant a team that is not theirs.
     */
    private function resolveTenant(Entity $chatwootTeam): ?Entity
    {
        $accountId = (string) ($chatwootTeam->get('accountId') ?? '');

        if ($accountId === '') {
            return null;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);

        if (!$account) {
            return null;
        }

        $tenantId = trim((string) ($account->get('tenantId') ?? ''));

        if ($tenantId === '' && $account instanceof CoreEntity) {
            $tenantId = (string) $this->tenantResolver->resolveUniqueFromTeamIds(
                $account->getLinkMultipleIdList('teams'),
            );
        }

        return $tenantId === '' ? null : $this->entityManager->getEntityById('Tenant', $tenantId);
    }
}
