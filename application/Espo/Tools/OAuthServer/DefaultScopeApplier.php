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

namespace Espo\Tools\OAuthServer;

use Espo\Tools\OAuthServer\Repository\ClientRepository;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Monostax: an authorization request without a `scope` asks for the client's configured scopes.
 *
 * MCP clients such as Claude send no `scope` when the server metadata advertises none. League then
 * validates an empty scope list and the token ends up with no scopes, so every ACL scope (`Mcp`
 * included) is stripped from the user. Defaulting to the scopes the admin set on the client is the
 * server default RFC 6749 §3.3 allows. The user still sees and approves them on the consent page,
 * and token issuance still keeps only the scopes the user's own ACL grants.
 */
class DefaultScopeApplier
{
    private const string PARAM_SCOPE = 'scope';
    private const string PARAM_CLIENT_ID = 'client_id';

    public function __construct(
        private ClientRepository $clientRepository,
    ) {}

    public function apply(ServerRequestInterface $request): ServerRequestInterface
    {
        $params = $request->getQueryParams();

        if (array_key_exists(self::PARAM_SCOPE, $params) && !self::isBlank($params[self::PARAM_SCOPE])) {
            return $request;
        }

        $clientId = $params[self::PARAM_CLIENT_ID] ?? null;

        if (!is_string($clientId) || $clientId === '') {
            return $request;
        }

        $client = $this->clientRepository->getActiveByIdentifier($clientId);

        if (!$client) {
            return $request;
        }

        $scopes = $client->getScopes();

        if ($scopes === []) {
            return $request;
        }

        $params[self::PARAM_SCOPE] = implode(' ', $scopes);

        return $request->withQueryParams($params);
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
