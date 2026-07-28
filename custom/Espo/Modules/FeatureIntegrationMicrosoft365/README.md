# FeatureIntegrationMicrosoft365

Microsoft 365 / Outlook personal and group mailboxes via **OAuthAccount + IMAP/SMTP XOAUTH2**.

Microsoft 365 no longer supports basic passwords for IMAP/SMTP in many tenants.
This module wires Espo's built-in XOAUTH handlers to Monostax `OAuthAccount`
tokens (same stack as Gmail / Google Meet / Calendar).

## What it adds

| Piece | Role |
| -------- | ------ |
| `OAuthProvider` seed `msx_m365_01` (discriminator `microsoft-365`) | Azure app OAuth client config + IMAP/SMTP XOAUTH scopes |
| Reuses `EmailAccount.oAuthAccount` / `InboundEmail.oAuthAccount` | Shared link field with Gmail (one OAuth account per mailbox) |
| `Microsoft365ImapHandler` / `Microsoft365SmtpHandler` | Inject live access token as XOAUTH2 password on fetch/send |
| BeforeSave hooks | Set `imapHandler` / `smtpHandler`, Office 365 host defaults, clear passwords |
| Client setup handlers | Prefill Office 365 hosts when a Microsoft 365 OAuth account is selected |
| Side panel **Connect Microsoft 365** | One-click: create `OAuthAccount`, open Microsoft consent, link EmailAccount/InboundEmail |

## Setup (per environment)

### 1. Microsoft Entra ID (Azure AD)

1. Register an app (single-tenant or multi-tenant) in [Microsoft Entra admin center](https://entra.microsoft.com/).
2. **Authentication** → add a Web redirect URI matching the CRM OAuth Provider
   `authorizationRedirectUri` (typically `https://{host}/oauth/callback/`).
3. **Certificates & secrets** → create a client secret.
4. **API permissions** → add **delegated** permissions:
   - `offline_access`
   - `openid`
   - `email`
   - `https://outlook.office365.com/IMAP.AccessAsUser.All`
   - `https://outlook.office365.com/SMTP.Send`
5. Grant admin consent if your tenant requires it.
6. For organizational mailboxes, ensure IMAP/SMTP and authenticated SMTP are
   enabled for the mailbox/tenant.

### 2. CRM rebuild

```bash
# from CRM app root / container
php rebuild.php
```

This seeds **Microsoft 365** provider (`msx_m365_01`) without overwriting an
existing `clientId` / `clientSecret`.

### 3. Provider credentials

**Client ID** is seeded automatically when empty:

| Source | When |
| -------- | ------ |
| Env `CRM_MICROSOFT_365_OAUTH_CLIENT_ID` | Preferred (gitops: `crmMicrosoft365OauthClientId`) |
| Built-in `DEFAULT_CLIENT_ID` | Fallback when env is empty |

**Client Secret** is seeded automatically when empty:

| Source | When |
| -------- | ------ |
| Env `CRM_MICROSOFT_365_OAUTH_CLIENT_SECRET` | gitops `crmMicrosoft365OauthClientSecret` (same Azure secret as `chatwootAzureAppSecret`) |

Neither field is overwritten once the provider row already has a value.

Optional: change tenant in the authorize/token endpoints from `/common/` to your
tenant GUID or `/organizations/` if you do not want consumer Microsoft accounts.

### 4. Connect from Email Account (recommended)

1. **Email Accounts** (personal) or **Group Email Accounts** → open/create & **save**.
2. Set **Email Address** to the Microsoft 365 mailbox address.
3. Side panel **Microsoft 365** → **Connect Microsoft 365**.
4. Microsoft consent popup opens. The CRM:
   - Creates an `OAuthAccount` (provider Microsoft 365) if none of that provider is linked
   - Stores tokens via `POST OAuth/{id}/connection`
   - Links it on the EmailAccount and wires IMAP/SMTP XOAUTH2 handlers
5. Hosts become:
   - IMAP `outlook.office365.com:993` SSL
   - SMTP `smtp.office365.com:587` TLS
6. **Test Connection** / **Send Test Email**.

Password fields hide automatically while any OAuth account is linked.

### 5. Coexistence with Gmail

Both integrations share `EmailAccount.oAuthAccount`. Connecting one provider
replaces the other's link on that mailbox. Side panels only show Connected /
Disconnect for accounts of their own provider.

### 6. Chatwoot-managed Microsoft 365 inboxes

Chatwoot owns IMAP fetch for email channels. CRM-side Microsoft 365 OAuth alone
is **not** enough: the Chatwoot channel needs `provider=microsoft` and tokens in
`provider_config`. Bridging an Office 365 mailbox without an IMAP app password
is rejected — authorize Microsoft OAuth in Chatwoot first (or set an app password).

When Chatwoot owns a Microsoft inbox, inbox sync imports its OAuth grant through
the administrator-only `email_oauth_credentials` endpoint and links the mirrored
CRM mailbox to an encrypted `OAuthAccount`. Configure the CRM **Microsoft 365**
provider with the same Azure app client ID/secret as Chatwoot (`AZURE_APP_ID` /
`AZURE_APP_SECRET`). A refresh token belongs to the client that issued it; the
sync verifies the client ID and refuses a mismatch.

The imported OAuthAccount is source-managed by its Chatwoot inbox. If Chatwoot
removes the grant, changes the channel provider, or deletes the inbox, the sync
clears the CRM mailbox link and removes the copied tokens. A manually linked CRM
OAuthAccount is never overwritten.

## Flow

```
EmailAccount detail → [Connect Microsoft 365]
        │
        ├─ window.open(about:blank)
        ├─ POST OAuthAccount (provider=msx_m365_01)
        ├─ GET  OAuthAccount/{id}   // DataLoader fills data.*
        ├─ Build Microsoft auth URL (login.microsoftonline.com allowlist)
        ├─ Popup consent → {siteUrl}/oauth/callback/?code=...
        ├─ POST OAuth/{id}/connection {code}
        └─ PATCH EmailAccount {oAuthAccountId}
              → BeforeSave ApplyMicrosoft365OAuth
                    imapHandler  = Microsoft365ImapHandler
                    smtpHandler  = Microsoft365SmtpHandler
                    hosts → outlook.office365.com / smtp.office365.com
                    clear passwords
```

## Runtime

- **Fetch:** `CheckEmailAccounts` → IMAP storage factory → `Microsoft365ImapHandler`
  → `TokensProvider` (refresh if needed) → XOAUTH2.
- **Send:** SMTP handler path → same token → Symfony `XOAuth2Authenticator`.

## Layout

```
FeatureIntegrationMicrosoft365/
├── Rebuild/SeedOAuthProviderMicrosoft365.php
├── Mail/Microsoft365{Imap,Smtp}Handler.php
├── Hooks/{EmailAccount,InboundEmail}/ApplyMicrosoft365OAuth.php
├── Classes/Select/OAuthAccount/PrimaryFilters/Microsoft365.php
└── Resources/...

client/custom/modules/feature-integration-microsoft-365/
├── src/helpers/microsoft-365-oauth-connect.js
├── src/views/.../panels/microsoft-365-connection.js
└── src/handlers/.../detail-setup.js
```
