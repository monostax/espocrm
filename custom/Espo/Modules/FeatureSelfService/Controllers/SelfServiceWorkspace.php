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
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Jobs\SyncInboxesFromChatwoot;
use Espo\Modules\Chatwoot\Services\WahaInboxAdoption;
use Espo\Modules\FeatureAiUsage\Services\Access as BillingAccess;
use Espo\Modules\FeatureSelfService\Tools\Plans\PlanCheckout;
use Espo\Modules\FeatureSelfService\Tools\Plans\PlanOffers;
use Espo\Modules\FeatureSelfService\Tools\Plans\PlanRecommendation;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceAccess;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceTrial;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceWallet;
use InvalidArgumentException;
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
 *        their WAHA status and starts the trial; then, while the number's
 *        history comes in, reads its import progress and opens the workspace
 *        once the newest window is in. Afterwards, applies a payment the
 *        billing engine already recorded without waiting for the job.
 *   GET  /SelfService/plans?accountId=
 *        Plans, monthly AI packs and what the checkout preselects.
 *   POST /SelfService/checkout {accountId, plan, seats, pack, payer: {name, cpfCnpj, email}}
 *        Starts the monthly subscription; returns the secure payment page.
 *        Billing administrators only (the tenant-admin role). Errors are
 *        {"code": ...}: ALREADY_SUBSCRIBED, CHECKOUT_IN_PROGRESS, PAYER_CHANGED,
 *        INVALID_DOCUMENT, INVALID_REQUEST, PLANS_UNAVAILABLE, PAYMENT_PENDING.
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

        if ($this->trial->isImportingHistory($tenant)) {
            return (object) $this->trial->state($this->settleImport($account, $tenant));
        }

        if (!$this->trial->isConnecting($tenant)) {
            $access = $this->injectableFactory->create(WorkspaceAccess::class);
            $access->applyBilling((string) $tenant->getId());
            $access->syncChatwoot();

            return (object) $this->trial->state(
                $this->entityManager->getEntityById('Tenant', (string) $tenant->getId()) ?? $tenant
            );
        }

        if ($this->trial->isConnecting($tenant)) {
            // One adoption instance, so the WAHA sessions are listed once.
            $adoption = $this->injectableFactory->create(WahaInboxAdoption::class);
            $this->injectableFactory
                ->createWith(SyncInboxesFromChatwoot::class, ['wahaInboxAdoption' => $adoption])
                ->syncAccountInboxes($account);
            $adoption->refreshAdoptedStatuses($account);

            if ($this->trial->startIfConnected($tenant)) {
                // The trial's credits now, not at the next job run; a failure here
                // is retried by ApplyWorkspaceAccess and must not fail onboarding.
                try {
                    $this->injectableFactory->create(WorkspaceWallet::class)->grantTrialCredits(
                        $this->entityManager->getEntityById('Tenant', (string) $tenant->getId()) ?? $tenant
                    );
                } catch (\Throwable) {
                }
            }
            $tenant = $this->entityManager->getEntityById('Tenant', (string) $tenant->getId()) ?? $tenant;
            // A number without history to bring (Cloud API) opens at once.
            $this->trial->finishImportIfSettled($tenant);
        }

        return (object) $this->trial->state($tenant);
    }

    /**
     * The inboxes' import progress straight from Chatwoot, then the step.
     */
    private function settleImport(Entity $account, Entity $tenant): Entity
    {
        $this->injectableFactory->create(SyncInboxesFromChatwoot::class)->syncAccountInboxes($account);
        $tenant = $this->entityManager->getEntityById('Tenant', (string) $tenant->getId()) ?? $tenant;
        $this->trial->finishImportIfSettled($tenant);

        return $tenant;
    }

    public function getActionPlans(Request $request): object
    {
        [$account, $tenant] = $this->resolve($request->getQueryParam('accountId'));

        try {
            $offer = $this->injectableFactory->create(PlanOffers::class)->list((string) $tenant->getId());
        } catch (ServiceUnavailable) {
            throw ServiceUnavailable::createWithBody('PLANS_UNAVAILABLE', $this->code('PLANS_UNAVAILABLE'));
        }

        $recommendation = $this->injectableFactory->create(PlanRecommendation::class)
            ->recommend($tenant, $account, $offer['plans'], $offer['packs']);

        return (object) ($offer + [
            'recommendation' => $recommendation,
            'payer' => ['name' => (string) $tenant->get('name'), 'email' => (string) $this->user->get('emailAddress')],
        ]);
    }

    public function postActionCheckout(Request $request): object
    {
        $body = $request->getParsedBody();
        [, $tenant] = $this->resolve($body->accountId ?? null);
        $tenantId = (string) $tenant->getId();

        // The billing engine's own rule for who may buy: tenant administrators.
        $this->injectableFactory->create(BillingAccess::class)->assertTenant($tenantId);

        $payer = $body->payer ?? null;
        $seats = $body->seats ?? null;

        if (!is_string($body->plan ?? null) || !is_string($body->pack ?? null) || !is_int($seats) ||
            !is_object($payer) || !is_string($payer->name ?? null) || !is_string($payer->cpfCnpj ?? null) ||
            !is_string($payer->email ?? null)) {
            throw BadRequest::createWithBody('INVALID_REQUEST', $this->code('INVALID_REQUEST'));
        }

        try {
            return (object) $this->injectableFactory->create(PlanCheckout::class)->start(
                $tenantId, (string) $this->user->getId(), $body->plan, $seats, $body->pack,
                ['name' => trim($payer->name), 'cpfCnpj' => preg_replace('/\D/', '', $payer->cpfCnpj), 'email' => trim($payer->email)]
            );
        } catch (InvalidArgumentException $e) {
            $code = str_contains($e->getMessage(), 'CPF/CNPJ') ? 'INVALID_DOCUMENT' : 'INVALID_REQUEST';

            throw BadRequest::createWithBody($code, $this->code($code));
        } catch (Conflict $e) {
            $code = in_array($e->getMessage(), ['ALREADY_SUBSCRIBED', 'CHECKOUT_IN_PROGRESS', 'PAYER_CHANGED'], true)
                ? $e->getMessage() : 'CHECKOUT_IN_PROGRESS';

            throw Conflict::createWithBody($code, $this->code($code));
        } catch (ServiceUnavailable) {
            throw ServiceUnavailable::createWithBody('PAYMENT_PENDING', $this->code('PAYMENT_PENDING'));
        }
    }

    private function code(string $code): string
    {
        return (string) json_encode(['code' => $code]);
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
