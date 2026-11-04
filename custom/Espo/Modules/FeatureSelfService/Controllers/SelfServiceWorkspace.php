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

namespace Espo\Modules\FeatureSelfService\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Jobs\SyncInboxesFromChatwoot;
use Espo\Modules\Chatwoot\Services\WahaInboxAdoption;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceTrial;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The self-service state of the workspace behind a Chatwoot account, for the
 * Chatwoot shell: the onboarding gate now, the trial banner and lock next.
 *
 *   GET  /SelfService/workspace?accountId=<chatwootAccountId>
 *   POST /SelfService/workspace/refresh {accountId}
 *        While onboarding, picks up a WhatsApp inbox connected in Chatwoot at
 *        once (instead of the minute sync), brings adopted QR channels up to
 *        their WAHA status and starts the trial.
 *
 * Access follows the feature-pack endpoint: the user must be able to read the
 * account and act for its tenant.
 */
class SelfServiceWorkspace
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private UserTenantResolver $userTenantResolver,
        private WorkspaceTrial $trial,
        private InjectableFactory $injectableFactory,
    ) {}

    public function getActionState(Request $request): object
    {
        [, $tenant] = $this->resolve($request->getQueryParam('accountId'));

        return (object) $this->trial->state($tenant);
    }

    public function postActionRefresh(Request $request): object
    {
        [$account, $tenant] = $this->resolve($request->getParsedBody()->accountId ?? null);

        if ($this->trial->isConnecting($tenant)) {
            // One adoption instance, so the WAHA sessions are listed once.
            $adoption = $this->injectableFactory->create(WahaInboxAdoption::class);
            $this->injectableFactory
                ->createWith(SyncInboxesFromChatwoot::class, ['wahaInboxAdoption' => $adoption])
                ->syncAccountInboxes($account);
            $adoption->refreshAdoptedStatuses($account);
            $this->trial->startIfConnected($tenant);
            $tenant = $this->entityManager->getEntityById('Tenant', (string) $tenant->getId()) ?? $tenant;
        }

        return (object) $this->trial->state($tenant);
    }

    /**
     * @return array{0: Entity, 1: Entity}
     * @throws BadRequest
     * @throws Forbidden
     */
    private function resolve(mixed $accountId): array
    {
        $accountId = filter_var($accountId, FILTER_VALIDATE_INT);

        if (!$accountId || $accountId <= 0) {
            throw new BadRequest('accountId is required.');
        }

        $accounts = iterator_to_array(
            $this->entityManager->getRDBRepository('ChatwootAccount')
                ->where(['chatwootAccountId' => $accountId])
                ->limit(0, 2)
                ->find(),
            false
        );

        $account = count($accounts) === 1 ? $accounts[0] : null;
        $tenantId = trim((string) $account?->get('tenantId'));
        $tenant = $tenantId !== '' ? $this->entityManager->getEntityById('Tenant', $tenantId) : null;

        if (!$account || !$tenant || (!$this->user->isAdmin() && (
            !$this->acl->checkEntityRead($account) ||
            !$this->userTenantResolver->canActForTenant($this->user, $tenantId)
        ))) {
            throw new Forbidden('No access to the requested workspace.');
        }

        return [$account, $tenant];
    }
}
