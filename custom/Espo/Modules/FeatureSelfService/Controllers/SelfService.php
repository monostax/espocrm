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

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServiceUnavailable;
use Espo\Modules\FeatureSelfService\Tools\Signup\SignupRejected;
use Espo\Modules\FeatureSelfService\Tools\Signup\SignupRequest;
use Espo\Modules\FeatureSelfService\Tools\Signup\Signups;
use Espo\Modules\FeatureSelfService\Tools\Signup\SocialSignIn;

/**
 * Public (no-auth) signup API used by the login page.
 *
 * Errors carry a JSON body {"code": "..."}: SELF_SERVICE_DISABLED (503) or a
 * SignupRejected code (400, 403 or 409).
 */
class SelfService
{
    public function __construct(
        private SelfServicePolicy $policy,
        private Signups $signups,
        private SocialSignIn $social,
    ) {}

    public function getActionConfig(): object
    {
        $state = $this->policy->state();

        return (object) [
            'state' => $state,
            'providers' => $state === SelfServicePolicy::STATE_ENABLED ? $this->social->availableProviders() : [],
        ];
    }

    public function postActionOauthStart(Request $request): object
    {
        $body = $request->getParsedBody();

        return $this->call(fn () => $this->social->start((string) ($body->provider ?? ''), (string) ($body->nonce ?? '')));
    }

    public function postActionOauthFinish(Request $request): object
    {
        $body = $request->getParsedBody();

        return $this->call(fn () => $this->social->finish(
            (string) ($body->provider ?? ''),
            (string) ($body->code ?? ''),
            (string) ($body->state ?? ''),
            (string) ($body->nonce ?? ''),
        ));
    }

    public function postActionOauthComplete(Request $request): object
    {
        $body = $request->getParsedBody();

        return $this->call(fn () => $this->social->complete(
            (string) ($body->identity ?? ''),
            (string) ($body->phone ?? ''),
            (string) ($body->company ?? ''),
            ($body->acceptTerms ?? false) === true,
            (string) ($body->language ?? 'pt_BR'),
            $this->ipAddress($request),
        ));
    }

    public function postActionSignup(Request $request): object
    {
        $body = $request->getParsedBody();

        return $this->call(fn () => $this->signups->begin(
            new SignupRequest(
                (string) ($body->name ?? ''),
                (string) ($body->email ?? ''),
                (string) ($body->phone ?? ''),
                (string) ($body->company ?? ''),
                (string) ($body->password ?? ''),
                ($body->acceptTerms ?? false) === true,
                (string) ($body->language ?? 'pt_BR'),
            ),
            $this->ipAddress($request),
        ));
    }

    public function postActionResend(Request $request): object
    {
        return $this->call(function () use ($request): array {
            $this->signups->resend((string) $request->getRouteParam('id'), $this->token($request));

            return [];
        });
    }

    public function postActionVerify(Request $request): object
    {
        $code = (string) ($request->getParsedBody()->code ?? '');

        return $this->call(fn () => $this->signups->verify((string) $request->getRouteParam('id'), $this->token($request), $code));
    }

    /** A new code for the signup's WhatsApp check. */
    public function postActionPhone(Request $request): object
    {
        return $this->call(fn () => $this->signups->phoneCode((string) $request->getRouteParam('id'), $this->token($request)));
    }

    public function postActionStatus(Request $request): object
    {
        return $this->call(fn () => $this->signups->status((string) $request->getRouteParam('id'), $this->token($request)));
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function call(callable $action): object
    {
        try {
            return (object) $action();
        } catch (SelfServiceUnavailable) {
            throw ServiceUnavailable::createWithBody(
                SelfServiceUnavailable::CODE,
                $this->body(SelfServiceUnavailable::CODE)
            );
        } catch (SignupRejected $e) {
            $body = $this->body($e->reason);

            throw match ($e->reason) {
                SignupRejected::EMAIL_IN_USE => Conflict::createWithBody($e->reason, $body),
                SignupRejected::RATE_LIMITED, SignupRejected::RESEND_TOO_SOON,
                SignupRejected::TOO_MANY_ATTEMPTS, SignupRejected::NOT_FOUND => Forbidden::createWithBody($e->reason, $body),
                SignupRejected::EMAIL_NOT_SENT => ServiceUnavailable::createWithBody($e->reason, $body),
                default => BadRequest::createWithBody($e->reason, $body),
            };
        }
    }

    private function body(string $code): string
    {
        return (string) json_encode(['code' => $code]);
    }

    private function token(Request $request): string
    {
        return (string) ($request->getParsedBody()->token ?? '');
    }

    /**
     * Cloudflare sits in front of the CRM and passes the visitor's address.
     */
    private function ipAddress(Request $request): string
    {
        $forwarded = trim((string) $request->getHeader('CF-Connecting-IP'));

        return $forwarded !== '' ? $forwarded : (string) $request->getServerParam('REMOTE_ADDR');
    }
}
