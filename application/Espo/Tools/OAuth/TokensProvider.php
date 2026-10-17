<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Tools\OAuth;

use Espo\Core\Field\DateTime;
use Espo\Core\Utils\Crypt;
use Espo\Entities\OAuthAccount;
use Espo\ORM\EntityManager;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\SelectBuilder;
use Espo\Tools\OAuth\Exceptions\NoToken;
use Espo\Tools\OAuth\Exceptions\ProviderNotAvailable;
use Espo\Tools\OAuth\Exceptions\AccountNotFound;
use Espo\Tools\OAuth\Exceptions\TokenObtainingFailure;
use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use LogicException;

class TokensProvider
{
    private const EXPIRATION_LEAD_TIME = 60;

    public function __construct(
        private EntityManager $entityManager,
        private GenericProviderFactory $genericProviderFactory,
        private TokenSetter $tokenSetter,
        private Crypt $crypt,
    ) {}

    /**
     * @throws AccountNotFound
     * @throws ProviderNotAvailable
     * @throws NoToken
     * @throws TokenObtainingFailure
     */
    public function get(string $id, ?float $requestTimeout = null): Tokens
    {
        $account = $this->fetch($id);

        if (
            $account->getRefreshToken() &&
            $account->getExpiresAt() &&
            $account->getExpiresAt()->isLessThan(
                DateTime::createNow()->addSeconds(self::EXPIRATION_LEAD_TIME)
            )
        ) {
            $this->refresh($account, $requestTimeout);
        }

        if (!$account->getAccessToken()) {
            throw new NoToken();
        }

        $accessToken = $this->crypt->decrypt($account->getAccessToken());

        $refreshToken = $account->getRefreshToken() ?
            $this->crypt->decrypt($account->getRefreshToken()) :
            null;

        return new Tokens(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $account->getExpiresAt(),
        );
    }

    /**
     * @throws ProviderNotAvailable
     * @throws AccountNotFound
     * @throws NoToken
     */
    private function fetch(string $id): OAuthAccount
    {
        // Ensuring the token is not being refreshed.
        $account = $this->entityManager->getTransactionManager()->run(function () use ($id) {
            // Lock only stored identity. A raw cloned query has no joins for
            // OAuthAccount's calculated provider fields (e.g. providerType).
            $locked = $this->entityManager
                ->getRDBRepositoryByClass(OAuthAccount::class)
                ->clone(
                    SelectBuilder::create()
                        ->from(OAuthAccount::ENTITY_TYPE)
                        ->select(Attribute::ID)
                        ->forShare()
                        ->build()
                )
                ->where([Attribute::ID => $id])
                ->findOne();

            return $locked ? $this->entityManager->getEntityById(OAuthAccount::ENTITY_TYPE, $id) : null;
        });

        if (!$account) {
            throw new AccountNotFound();
        }

        if (!$account->getProvider()->isActive()) {
            throw new ProviderNotAvailable();
        }

        if (!$account->getAccessToken()) {
            throw new NoToken();
        }

        return $account;
    }

    /**
     * @throws TokenObtainingFailure
     * @noinspection PhpDocRedundantThrowsInspection
     */
    private function refresh(OAuthAccount $account, ?float $requestTimeout = null): void
    {
        $this->entityManager
            ->getTransactionManager()
            ->run(function () use ($account, $requestTimeout) {
                $this->refreshInTransaction($account, $requestTimeout);
            });
    }

    /**
     * @throws TokenObtainingFailure
     */
    private function refreshInTransaction(OAuthAccount $account, ?float $requestTimeout = null): void
    {
        $refreshToken = $account->getRefreshToken();

        if (!$refreshToken) {
            throw new LogicException();
        }

        $refreshToken = $this->crypt->decrypt($refreshToken);

        $this->entityManager
            ->getRDBRepositoryByClass(OAuthAccount::class)
            ->forUpdate()
            ->sth()
            ->select(Attribute::ID)
            ->where([Attribute::ID => $account->getId()])
            ->find();

        $genericProvider = $this->genericProviderFactory->create($account->getProvider());
        // Optional per-operation budget; ordinary OAuth callers retain their existing client defaults.
        if ($requestTimeout !== null) {
            $genericProvider->setHttpClient(new \GuzzleHttp\Client([
                'timeout' => max(0.001, $requestTimeout),
                'connect_timeout' => max(0.001, min(10, $requestTimeout)),
            ]));
        }

        try {
            $tokens = $genericProvider->getAccessToken('refresh_token', ['refresh_token' => $refreshToken]);
        } catch (GuzzleException|IdentityProviderException $e) {
            throw new TokenObtainingFailure($e->getMessage(), $e->getCode(), $e);
        }

        $this->tokenSetter->set($account, $tokens);

        // Google normally omits refresh_token on refresh. Retain the existing
        // encrypted token unless the provider actually rotates it.
        if (!$tokens->getRefreshToken()) {
            $account->setRefreshToken($this->crypt->encrypt($refreshToken));
        }

        $this->entityManager->saveEntity($account);
    }
}
