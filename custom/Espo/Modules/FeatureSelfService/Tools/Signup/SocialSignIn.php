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

namespace Espo\Modules\FeatureSelfService\Tools\Signup;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuth\ConfigDataProvider;
use Throwable;

/**
 * "Continue with Google / Microsoft" on the login page.
 *
 * Uses the OAuth apps the CRM already has (the `google-gmail` and
 * `microsoft-365` OAuthProviders) and their registered redirect URI
 * (`<siteUrl>/oauth/callback/`), asking only for `openid email profile`.
 *
 *   start    → the provider's authorization URL, with a signed state bound
 *              to a nonce the browser keeps (stops login CSRF)
 *   finish   → code exchanged for the verified email. An existing user gets a
 *              login ticket; a new email gets a signed identity token
 *   complete → identity + company, WhatsApp and terms → the signup, without
 *              an email code
 */
class SocialSignIn
{
    private const PROVIDER_TYPES = [
        SocialIdentity::GOOGLE => 'google-gmail',
        SocialIdentity::MICROSOFT => 'microsoft-365',
    ];

    private const STATE_TTL_SECONDS = 600;
    private const IDENTITY_TTL_SECONDS = 1800;

    public function __construct(
        private EntityManager $entityManager,
        private SelfServicePolicy $policy,
        private Crypt $crypt,
        private Config $config,
        private ConfigDataProvider $oauthConfig,
        private LoginTickets $tickets,
        private Signups $signups,
        private Log $log,
    ) {}

    /**
     * @return list<string>
     */
    public function availableProviders(): array
    {
        $available = [];

        foreach (array_keys(self::PROVIDER_TYPES) as $provider) {
            if ($this->findProvider($provider)) {
                $available[] = $provider;
            }
        }

        return $available;
    }

    /**
     * @return array{url: string}
     * @throws SignupRejected
     */
    public function start(string $provider, string $nonce): array
    {
        $this->policy->assertEnabled();
        $this->assertNonce($nonce);

        $app = $this->app($provider);

        $params = [
            'client_id' => $app['clientId'],
            'redirect_uri' => $this->oauthConfig->getRedirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'prompt' => 'select_account',
            'state' => $this->sign(['k' => 'state', 'p' => $provider, 'n' => hash('sha256', $nonce)], self::STATE_TTL_SECONDS),
        ];

        if ($provider === SocialIdentity::MICROSOFT) {
            $params['response_mode'] = 'query';
        }

        return ['url' => $app['authorizationEndpoint'] . '?' . http_build_query($params)];
    }

    /**
     * @return array{status: 'ready', ticket: string}|array{status: 'needsDetails', identity: string, email: string, name: string}
     * @throws SignupRejected
     */
    public function finish(string $provider, string $code, string $state, string $nonce): array
    {
        $this->policy->assertEnabled();
        $this->assertNonce($nonce);

        $claims = $this->verify($state, 'state');

        if (($claims['p'] ?? null) !== $provider || !hash_equals((string) ($claims['n'] ?? ''), hash('sha256', $nonce))) {
            throw new SignupRejected(SignupRejected::SOCIAL_FAILED);
        }

        $identity = $this->exchange($provider, $code);
        $user = $this->findUser($identity->email);

        if ($user) {
            // Instance admins and API users log in with their password only.
            if (!$user->isActive() || $user->getType() !== User::TYPE_REGULAR) {
                throw new SignupRejected(SignupRejected::EMAIL_IN_USE);
            }

            return ['status' => 'ready', 'ticket' => $this->tickets->issue((string) $user->getId())];
        }

        return [
            'status' => 'needsDetails',
            'identity' => $this->sign(
                ['k' => 'identity', 'email' => $identity->email, 'name' => $identity->name],
                self::IDENTITY_TTL_SECONDS
            ),
            'email' => $identity->email,
            'name' => $identity->name,
        ];
    }

    /**
     * @return array{signupId: string, token: string, status: string, ticket?: string}
     * @throws SignupRejected
     */
    public function complete(
        string $identity,
        string $phone,
        string $company,
        bool $acceptTerms,
        string $language,
        string $ipAddress,
    ): array {
        $claims = $this->verify($identity, 'identity');

        // The provider verified the email; the random password only satisfies
        // the account. The owner can set one later with "Forgot password".
        return $this->signups->beginVerified(new SignupRequest(
            (string) $claims['name'],
            (string) $claims['email'],
            $phone,
            $company,
            bin2hex(random_bytes(24)),
            $acceptTerms,
            $language,
        ), $ipAddress);
    }

    /**
     * @throws SignupRejected
     */
    private function exchange(string $provider, string $code): SocialIdentity
    {
        $app = $this->app($provider);

        $curl = curl_init($app['tokenEndpoint']);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->oauthConfig->getRedirectUri(),
                'client_id' => $app['clientId'],
                'client_secret' => $app['clientSecret'],
            ]),
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $response = is_string($body) ? json_decode($body, true) : null;
        $idToken = is_array($response) ? ($response['id_token'] ?? null) : null;

        if ($status !== 200 || !is_string($idToken)) {
            $this->log->warning("SelfService: {$provider} sign-in code exchange failed (HTTP {$status}).");

            throw new SignupRejected(SignupRejected::SOCIAL_FAILED);
        }

        return SocialIdentity::fromIdToken($provider, $idToken, $app['clientId'], time());
    }

    /**
     * @return array{clientId: string, clientSecret: string, authorizationEndpoint: string, tokenEndpoint: string}
     * @throws SignupRejected
     */
    private function app(string $provider): array
    {
        $record = $this->findProvider($provider) ?? throw new SignupRejected(SignupRejected::SOCIAL_UNAVAILABLE);

        try {
            $secret = $this->crypt->decrypt((string) $record->get('clientSecret'));
        } catch (Throwable) {
            throw new SignupRejected(SignupRejected::SOCIAL_UNAVAILABLE);
        }

        return [
            'clientId' => (string) $record->get('clientId'),
            'clientSecret' => $secret,
            'authorizationEndpoint' => (string) $record->get('authorizationEndpoint'),
            'tokenEndpoint' => (string) $record->get('tokenEndpoint'),
        ];
    }

    private function findProvider(string $provider): ?\Espo\ORM\Entity
    {
        $type = self::PROVIDER_TYPES[$provider] ?? null;

        if (!$type) {
            return null;
        }

        return $this->entityManager
            ->getRDBRepository('OAuthProvider')
            ->where([
                'provider' => $type,
                'isActive' => true,
                'AND' => [
                    ['clientId!=' => null], ['clientId!=' => ''],
                    ['clientSecret!=' => null], ['clientSecret!=' => ''],
                    ['authorizationEndpoint!=' => null], ['tokenEndpoint!=' => null],
                ],
            ])
            ->order('createdAt')
            ->findOne();
    }

    private function findUser(string $email): ?User
    {
        /** @var ?User */
        return $this->entityManager
            ->getRDBRepository(User::ENTITY_TYPE)
            ->where(['OR' => [['userName' => $email], ['emailAddress' => $email]]])
            ->findOne();
    }

    /**
     * @throws SignupRejected
     */
    private function assertNonce(string $nonce): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)) {
            throw new SignupRejected(SignupRejected::SOCIAL_FAILED);
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function sign(array $claims, int $ttl): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode([...$claims, 'e' => time() + $ttl])), '+/', '-_'), '=');

        return $payload . '.' . hash_hmac('sha256', $payload, $this->key());
    }

    /**
     * @return array<string, mixed>
     * @throws SignupRejected
     */
    private function verify(string $token, string $kind): array
    {
        [$payload, $mac] = array_pad(explode('.', $token, 2), 2, '');

        if ($mac === '' || !hash_equals(hash_hmac('sha256', $payload, $this->key()), $mac)) {
            throw new SignupRejected(SignupRejected::SOCIAL_FAILED);
        }

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        if (!is_array($claims) || ($claims['k'] ?? null) !== $kind || (int) ($claims['e'] ?? 0) < time()) {
            throw new SignupRejected(SignupRejected::SOCIAL_FAILED);
        }

        return $claims;
    }

    /**
     * @throws SignupRejected
     */
    private function key(): string
    {
        $cryptKey = (string) $this->config->get('cryptKey');

        if (strlen($cryptKey) < 16) {
            throw new SignupRejected(SignupRejected::SOCIAL_UNAVAILABLE);
        }

        return 'self-service-social:' . $cryptKey;
    }
}
