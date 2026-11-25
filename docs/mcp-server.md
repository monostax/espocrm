# MCP server

The CRM acts as an MCP server through the official EspoCRM extension
[espocrm/ext-mcp](https://github.com/espocrm/ext-mcp). AI clients (Claude, ChatGPT, Cursor…)
connect to an endpoint and get tools such as `Find_Contact`, `Read_Opportunity` or
`Create_Task`. Every tool call runs as the signed-in user, through the record services,
so team/tenant ACL applies exactly as in the UI and the REST API.

Requires EspoCRM ≥ 10.1 for OAuth (merged in this fork from upstream 10.1.1).
Only MCP protocol version `2026-07-28` is supported, so the client has to support it.

## Where it lives

| Part | Path |
|---|---|
| Backend module (vendored release, includes the php-scoped `opis/json-schema` in `vendor/`) | `custom/Espo/Modules/Mcp` |
| Admin UI bundle (prebuilt) | `client/custom/modules/mcp` |
| Route | `POST /api/v1/Mcp/{slug}` |
| `Mcp` scope for tenant roles | `custom/Espo/Modules/Global/Rebuild/SeedRole.php` (tenant base data) |

The files are the 0.3.0 release package as published (sha256
`140e34e24d541a648d531dd5456072abca77a890fc04c39381d704f883dd64dc`), plus one Monostax change:
the endpoint **All Users** switch (`allUsers` in `entityDefs/McpEndpoint.json`, the detail layout and
en_US labels, `Endpoint::isForAllUsers()`, the early return in `Tools/Mcp/EndpointProvider.php`). To
update: download the new `mcp-x.y.z.zip` from the releases page, check its `manifest.json`
(`acceptableVersions`, `php`), replace both folders with `files/custom/Espo/Modules/Mcp` and
`files/client/custom/modules/mcp` from the package, then re-apply that change. The install scripts
are no-ops.

## Who can use it

Three gates, all required:

1. **`Mcp` role scope.** The seeded `tenant`, `tenant-admin` and `tenant-user-api` roles grant it
   (SeedRole rewrites these roles on every rebuild, so the grant lives in code).
2. **Endpoint access.** Either the endpoint has **All Users** on (any user with the `Mcp` scope),
   or an instance admin adds the user to it (MCP Endpoint → Users panel). Tenant admins can't
   manage endpoints.
3. **Record ACL.** Each tool call goes through `ServiceFactory::createForUser`, so the user only
   finds, reads and writes what their teams allow. Cross-tenant reads return
   "No read access to the record."

Endpoints are global (not per tenant): one endpoint can serve every tenant, because data access is
per user. The intended setup is one All Users endpoint and one OAuth client per AI app for
everyone; the tenant comes from whoever signs in. Keep `tenant` and `teams` out of the tools'
fields: creates fill them from the user (below). The tool list is cached per endpoint and user.

## Setting up an endpoint (instance admin)

Administration → MCP Endpoints → Create. Pick a `slug` (the URL becomes
`https://<crm-host>/api/v1/Mcp/<slug>`), then add features: each feature is one tool
(Find / Read / Create / Update / Delete / Record Stream / Create Post) for one entity type,
with the fields it exposes and per-field instructions for the model. Keep the field lists
short: the whole schema goes into the client's context window. Turn on **All Users**, or add the
users last.

## Connecting a client

**OAuth 2.0 (recommended).** Register a client under Administration → OAuth Clients:
type *Public* (PKCE) for desktop/CLI clients, redirect URI from the client, scopes `Mcp`
plus every entity scope the endpoint uses (e.g. `Contact`, `Opportunity`), or `Global`.
In the MCP client, set the server URL and paste the OAuth client ID. The user signs in on
the CRM consent page and approves. Access tokens last 1 hour and come with a refresh token.

- Discovery: `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server`
  at the CRM root. The CRM sends no `resource_metadata` hint on 401, so clients use the root fallback.
- No dynamic client registration: the client ID has to be registered by an admin and pasted in.
- One *Public* client per AI app, each listing that app's redirect URIs exactly (a redirect URI that
  isn't on the client fails as `invalid_client`, "Client authentication failed"):

  | App | Redirect URIs |
  |---|---|
  | Claude (web, Desktop, mobile) | `https://claude.ai/api/mcp/auth_callback` |
  | ChatGPT | `https://chatgpt.com/connector_platform_oauth_redirect` (only used when the server returns RFC 9207 `iss`; otherwise ChatGPT uses a per-connection `https://chatgpt.com/connector/oauth/{id}`) |
  | Cursor | `http://localhost:8787/callback` (fixed port), `https://www.cursor.com/agents/mcp/oauth/callback`, `cursor://anysphere.cursor-mcp/oauth/callback` |
  | Claude Code | `http://localhost:<port>/callback`, with the same port in `claude mcp add --callback-port` (not 8787) |

  The validator ignores the port only for `127.0.0.1` / `[::1]`, so `localhost` URIs match exactly.
- Users get the server URL, client IDs and steps on Chatwoot **Configurações › Conectar IA**
  (`settings_ai_connections`), which reads `GET /Mcp/action/setup`
  (`Global\Tools\Mcp\ConnectionSetupService`: the endpoints the user may use and the active public
  clients with the `Mcp` scope) and lists/disconnects their apps through
  `GET|DELETE /User/:id/connectedApps`. An app shows as not enabled until a client lists its redirect.
- A client that sends no `scope` (Claude does, because the metadata lists none) is asked for the
  client's configured scopes (`DefaultScopeApplier`, a Monostax change). Without that the token had
  no scopes and every call failed on the `Mcp` scope.
- A token only reaches the scopes it was granted. With only `Mcp`, the tools still appear in
  the (cached) list, but every call returns 403.

**Personal API key.** A user can create a `UserApiKey` and set the header `X-Api-Key: <key>`
in the client. It authenticates as that user (same three gates). Legacy API users work too.

## Creates through MCP and the API

Tenant entities require `teams` and `tenant`. The web UI pre-fills both; API and MCP clients
usually don't. `Global\Classes\RecordHooks\FillTeamAndTenantOnCreate` (an
`earlyBeforeCreate` record hook for every entity) adds the user's default team when `teams`
is required and empty, and derives `tenantId` from the teams (`TenantFromTeamsSync`, teams
spanning tenants are refused). Validation and the entity create ACL still run after it.

## Known limits

- The OAuth authorize request is held in the PHP session between "authorize" and "approve".
  The web deployment runs two replicas, so PHP sessions live in Redis (`CRM_PHP_SESSION_SAVE_*`,
  db 2, prefix `crm_session:`).
- An instance admin who approves the consent page gets a token that sees every tenant.
  Connect as a tenant user.
- Protocol (2026-11-25): the module speaks only `2026-07-28` (no `initialize`). Cursor speaks
  `2025-11-25` and earlier and can't connect; whether Claude's connectors negotiate `2026-07-28` is
  undocumented ("rolling out"). Serving `2025-11-25` beside it is the fix both point to.
- OAuth metadata lists no `code_challenge_methods_supported` (S256) or
  `token_endpoint_auth_methods_supported` (`none`); ChatGPT treats a server without S256 as
  unsupported and Claude's docs require it. The 401 sends `Basic realm`, not
  `Bearer resource_metadata=…`, and the protected-resource `resource` is the site URL, not the
  endpoint URL; Claude got through on the root fallback on 2026-11-25.
- Our custom ACL checkers and access-control filters use `isEffectiveAdmin()`, so an instance admin
  using a limited-scope OAuth token loses the admin bypass there. About 89 other
  `->isAdmin()` checks (mostly admin-only controllers such as credit billing) still ignore token
  scopes. Fix those before instance admins use OAuth clients.
- The consent page is a server-rendered single page (`useRouter: false`). The Chatwoot
  workspace redirect skips it on purpose (`App::redirectNonAdminToChatwoot`).

## Testing it locally

An isolated recipe (throwaway MariaDB + production image + this tree) used for the acceptance run:
clone a CRM database, sync the source into a webroot, write `data/config*.php`, `php rebuild.php`,
serve with `apache2-foreground`, then drive `server/discover`, `tools/list` and `tools/call` with
`MCP-Protocol-Version: 2026-07-28` and either `X-Api-Key` or `Authorization: Bearer`.
