<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Classes\Authentication;

use Espo\Core\Api\Request;
use Espo\Core\Authentication\Helper\UserFinder;
use Espo\Core\Authentication\Login;
use Espo\Core\Authentication\Login\Data;
use Espo\Core\Authentication\Logins\Espo;
use Espo\Core\Authentication\Result;
use Espo\Modules\Chatwoot\Services\ChatwootAgentIdentity;
use Espo\ORM\EntityManager;

/** Accept the invitation email as an alias for a linked agent's CRM username. */
class AgentEmailLogin implements Login
{
    public function __construct(
        private Espo $login,
        private UserFinder $userFinder,
        private EntityManager $entityManager,
        private ChatwootAgentIdentity $agentIdentity,
        private bool $isPortal = false
    ) {}

    public function login(Data $data, Request $request): Result
    {
        $username = $data->getUsername();

        // Preserve explicit usernames, portal login and token/password-version
        // validation. The SPA uses the canonical username after initial login.
        if ($this->isPortal || $data->getAuthToken() || !$username || !$data->getPassword() ||
            !filter_var(trim($username), FILTER_VALIDATE_EMAIL) || $this->userFinder->find($username)) {
            return $this->login->login($data, $request);
        }

        $user = $this->agentIdentity->findCrmUser($username);
        if (!$user) {
            return $this->login->login($data, $request);
        }

        $identity = $this->entityManager->getRDBRepository('ChatwootUser')
            ->leftJoin('conciergeForAccount')
            ->where([
                'assignedUserId' => $user->getId(),
                'emailAddress' => strtolower(trim($username)),
                'conciergeForAccount.id' => null,
            ])
            ->findOne();

        if (!$identity) {
            return $this->login->login($data, $request);
        }

        // Espo still verifies the CRM password; Authentication subsequently
        // enforces active status, tenant hooks and the normal second-factor flow.
        return $this->login->login(new Data($user->getUserName(), $data->getPassword()), $request);
    }
}
