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

namespace Espo\Modules\FeatureSelfService\Classes\Authentication;

use Espo\Core\Api\Request;
use Espo\Core\Authentication\Login;
use Espo\Core\Authentication\Login\Data;
use Espo\Core\Authentication\Result;
use Espo\Core\Authentication\Result\FailReason;
use Espo\Core\InjectableFactory;
use Espo\Modules\Chatwoot\Classes\Authentication\AgentEmailLogin;
use Espo\Modules\FeatureSelfService\Tools\Signup\LoginTickets;

/**
 * The `Espo` login method, plus one-time self-service tickets.
 *
 * A ticket is sent as username `**self-service` with the ticket as password,
 * the way Espo's OIDC login sends its code. The SPA then gets a normal session
 * token and its usual post-login flow, including the Chatwoot redirect.
 * Everything else goes to Chatwoot's AgentEmailLogin unchanged.
 */
class TicketLogin implements Login
{
    public const USERNAME = '**self-service';

    private Login $passwordLogin;

    public function __construct(
        InjectableFactory $injectableFactory,
        private LoginTickets $tickets,
        private bool $isPortal = false,
    ) {
        $this->passwordLogin = $injectableFactory->createWith(AgentEmailLogin::class, ['isPortal' => $isPortal]);
    }

    public function login(Data $data, Request $request): Result
    {
        if ($this->isPortal || $data->getAuthToken() || $data->getUsername() !== self::USERNAME) {
            return $this->passwordLogin->login($data, $request);
        }

        $user = $this->tickets->redeem((string) $data->getPassword());

        return $user ? Result::success($user) : Result::fail(FailReason::WRONG_CREDENTIALS);
    }
}
