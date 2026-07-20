# FeatureIntegrationGmail

Gmail personal/group mailboxes via **OAuthAccount + IMAP/SMTP XOAUTH2**.

Google no longer supports normal passwords for IMAP/SMTP. This module wires Espo's
built-in XOAUTH handlers to Monostax `OAuthAccount` tokens (same stack as Google
Meet / Calendar).

## What it adds

| Piece | Role |
| -------- | ------ |
| `OAuthProvider` seed `msx_gmail_01` (discriminator `google-gmail`) | Google OAuth client config + `https://mail.google.com/` scope |
| `EmailAccount.oAuthAccount` / `InboundEmail.oAuthAccount` | Link field (picker filtered to Gmail accounts) |
| `GmailImapHandler` / `GmailSmtpHandler` | Inject live access token as XOAUTH2 password on fetch/send |
| BeforeSave hooks | Set `imapHandler` / `smtpHandler`, Gmail host defaults, clear passwords |
| Detail layouts + logicDefs | OAuth field on Main tab; hide password fields when OAuth is linked |
| Client setup handlers | Prefill Gmail hosts in the form when OAuth is selected |
| Side panel **Connect Gmail** | One-click: create `OAuthAccount`, open Google consent, link empty EmailAccount/InboundEmail |

## Setup (per environment)

### 1. Google Cloud

1. Create (or reuse) an OAuth 2.0 **Web application** client.
2. Enable **Gmail API**.
3. OAuth consent screen: add scope `https://mail.google.com/` (and ideally make the
   app production/verified for non-test users).
   4. Authorized redirect URI = the value shown on the CRM **OAuth Provider** record
    (`authorizationRedirectUri`, typically `https://{host}/oauth/callback/`).

### 2. CRM rebuild

```bash
# from CRM app root / container
php rebuild.php
```

This seeds **Google Gmail** provider (`msx_gmail_01`) without overwriting an
existing `clientId` / `clientSecret`.

### 3. Provider secrets

Admin → **OAuth Providers** → **Google Gmail** → set:

- Client ID
- Client Secret

### 4. Connect from Email Account (recommended)

1. **Email Accounts** (personal) or **Group Email Accounts** → open/create & **save**.
2. Set **Email Address** to the Gmail address.
3. Side panel **Gmail** → **Connect Gmail**.
4. Google consent popup opens. The CRM:
   - Creates an `OAuthAccount` (provider Google Gmail) if none is linked
   - Stores tokens via `POST OAuth/{id}/connection`
   - Links it on the EmailAccount and wires IMAP/SMTP XOAUTH2 handlers
5. Hosts become:
   - IMAP `imap.gmail.com:993` SSL
   - SMTP `smtp.gmail.com:587` TLS
6. **Test Connection** / **Send Test Email**.

Password fields hide automatically while OAuth is linked.

### 5. Manual path (optional)

Still available: create/connect under **OAuth Accounts**, then pick it in the
**Gmail OAuth Account** link field on the Email Account Main tab.

### 6. Chatwoot-managed Gmail inboxes

When Chatwoot owns a Gmail inbox, the inbox sync imports its Google OAuth grant
through Chatwoot's administrator-only
`email_oauth_credentials` endpoint and links the mirrored CRM mailbox to an
encrypted `OAuthAccount`. The regular Chatwoot inbox API never returns this
grant.

Configure the CRM **Google Gmail** provider with the same Google OAuth client
ID and secret used by Chatwoot. A refresh token belongs to the client that
issued it; the sync verifies the client ID and refuses a mismatch. The
Chatwoot platform URL should use HTTPS when reachable over the public
network. In-cluster Monostax deployments use plain HTTP
(`chatwoot-service.*.svc.cluster.local`) and therefore set
`chatwootAllowInsecureCredentialSync=true` via
`ESPOCRM_CONFIG_CHATWOOT_ALLOW_INSECURE_CREDENTIAL_SYNC` /
`CRM_CHATWOOT_ALLOW_INSECURE_CREDENTIAL_SYNC` (applied each pod start).

The imported OAuthAccount is marked as source-managed by its Chatwoot inbox.
If Chatwoot removes the Google grant, changes the channel provider, or deletes
the inbox, the sync clears the CRM mailbox link and removes the copied tokens.
An existing manually linked CRM OAuthAccount is never overwritten.

Personal `EmailAccount` mailboxes also ensure `assignedUser` is a
`ChatwootAccountUserMembership` agent on the account and is linked on the
mirrored `ChatwootInbox` (plus AI agents), so CRM ACL and Chatwoot
`inbox_members` grant the owner access.

## Scope requirements

| Scope | Required |
| -------- | ---------- |
| `https://mail.google.com/` | **Yes** — only this (full mail) works for IMAP/SMTP XOAUTH2 |
| `openid` / `email` / `userinfo.email` | Optional identity |

Do **not** rely on `gmail.readonly` / `gmail.send` alone for this path.

## Architecture

```
OAuthAccount (google-gmail) ──TokensProvider──► access_token (refreshed)
        │
EmailAccount.oAuthAccountId
        │
BeforeSave → imapHandler = GmailImapHandler
             smtpHandler = GmailSmtpHandler
        │
IMAP\tableofcontents Auth: XOAUTH2  user=email  pass=access_token
SMTP ─┘
```

Handlers live under `Mail/` and implement:

- `Espo\Core\Mail\Account\Storage\Handler` (IMAP)
- `Espo\Core\Mail\Smtp\Handler` (SMTP)

## Notes / limits

- Linking a **non-Gmail** OAuthAccount does not apply handlers (logged warning).
- Unlinking OAuth clears Gmail handlers only (does not wipe custom hosts).
- Workspace admins can still block IMAP/SMTP OAuth for the domain.
- App Password remains a fallback without this module; prefer OAuth.
- Chatwoot's account API key must belong to an administrator with access to the
  inbox for Chatwoot-managed Gmail OAuth credentials to sync.

## Files

```
FeatureIntegrationGmail/
├── Classes/Select/OAuthAccount/PrimaryFilters/Gmail.php
├── Hooks/EmailAccount/ApplyGmailOAuth.php
├── Hooks/InboundEmail/ApplyGmailOAuth.php
├── Mail/GmailImapHandler.php
├── Mail/GmailSmtpHandler.php
├── Rebuild/SeedOAuthProviderGmail.php
└── Resources/...
client/custom/modules/feature-integration-gmail/
├── src/helpers/gmail-oauth-connect.js
├── src/handlers/email-account/detail-setup.js
├── src/handlers/inbound-email/detail-setup.js
└── src/views/{email-account,inbound-email}/
    ├── fields/o-auth-account.js
    └── panels/gmail-connection.js
```
