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

namespace Espo\Modules\Global\Tools\FeaturePack\Api;

use Espo\Core\Acl;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Global\Classes\Utils\FeatureVerticalChecker;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;

/**
 * Feature packs enabled for the tenant behind a Chatwoot account.
 *
 * GET /FeaturePack/action/forAccount?accountId=<chatwoot account id>
 * → {"tenantId": "...", "packs": ["calls", ...]}
 *
 * The Chatwoot shell uses this to hide frozen modules (tabs, menu items,
 * routes) for workspaces without the pack. Packs are the add-on roles on the
 * tenant's teams (app.featureVerticals). Instance admins get every pack.
 *
 * Authorization mirrors CustomField's account-based resolution: the account
 * must be provisioned, readable by the user, and its tenant one the user can
 * act for.
 *
 * @noinspection PhpUnused
 */
class GetForAccount implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private UserTenantResolver $userTenantResolver,
        private FeatureVerticalChecker $checker,
    ) {}

    public function process(Request $request): Response
    {
        $accountId = filter_var($request->getQueryParam('accountId'), FILTER_VALIDATE_INT);

        if (!$accountId || $accountId <= 0) {
            throw new BadRequest('accountId is required.');
        }

        $accounts = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where(['chatwootAccountId' => $accountId])
            ->limit(0, 2)
            ->find();

        if (count($accounts) !== 1) {
            throw new BadRequest('Workspace not found.');
        }

        $account = iterator_to_array($accounts, false)[0];
        $tenantId = $account->get('tenantId');

        if (
            !is_string($tenantId) ||
            trim($tenantId) === '' ||
            !$account->get('platformId') ||
            (!$this->user->isAdmin() && (
                !$this->acl->checkEntityRead($account) ||
                !$this->userTenantResolver->canActForTenant($this->user, trim($tenantId))
            ))
        ) {
            throw new Forbidden('No access to the requested workspace.');
        }

        $tenantId = trim($tenantId);

        $packs = $this->user->isAdmin()
            ? $this->checker->getAllVerticalNames()
            : $this->checker->getTenantVerticals($tenantId);

        return ResponseComposer::json([
            'tenantId' => $tenantId,
            'packs' => $packs,
        ]);
    }
}
