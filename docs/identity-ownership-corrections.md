# Wrong-recipient corrections

The Chatwoot conversation menu and the opportunity **Conversas** cards expose
**Pessoa errada / número errado**. The confirmation shows the CRM contact and
the exact destination. An incoming message can optionally be attached as evidence.

## Meaning and persistence

`ContactChannelIdentity.ownershipStatus = rejected` rejects the association
between the person and the destination. It does not opt out the entire contact,
invalidate their other addresses, or change the opportunity's stage.

The identity retains the reviewer, timestamp, reason, CRM conversation ID and
optional Chatwoot message ID. Reconciliation cannot restore, reassign or overwrite
a rejected association. Ordinary deletion is prevented; contact-field list saves
retain rejected identities even when omitted from the submitted active list.

Chatwoot stores contact/account-scoped `ContactIdentityRejection` records, separate
from editable profile attributes. WhatsApp, SMS and calls share a normalized
phone destination; emails use normalized email addresses; other channels are
scoped to the inbox. Incoming messages and private notes remain available.
Contact merges retain suppression records in the same account.

New public messages and queued deliveries are checked. This includes the normal
channel sender, WAHA delivery jobs and API-inbox webhooks, SMS campaigns, calls
and CSAT. CRM initiation, WhatsApp campaigns and email campaign delivery also
check the canonical identity correction. Other identities remain eligible.

## Authorization

The CRM preview and report routes require scope/field access, conversation read
access, contact and identity read/edit access, and an authorized workspace tenant.
The contact, identity and Chatwoot bridge must agree on tenant, owner, account and
destination. Chatwoot additionally authorizes the exact conversation using the
current agent's personal token. Evidence is limited to its incoming messages.

Cross-workspace mirrors use the configured account integration token only after
those checks and only for bridges of the same CRM contact in the same tenant.
The Chatwoot mirror endpoint requires an account administrator and resolves its
target contact through `Current.account.contacts`.

The dialog binds to the workspace/conversation it opened for. Late responses
cannot update another workspace after navigation.

## Deployment and retries

1. Deploy the Chatwoot backend and run its `db:migrate` step. The new migration is
   `20261005180000_create_contact_identity_rejections.rb`.
2. Deploy CRM and run its usual `php rebuild.php` step to add ownership fields and
   refresh metadata/routes.
3. Deploy the matching Chatwoot frontend.

The CRM rejection is saved before remote synchronization. If a mirror fails,
the dialog reports failure and remains retryable; the canonical rejection is
retained. Success is returned only after the selected conversation and applicable
same-tenant workspace mirrors have been updated. Retrying preserves the original
CRM review evidence. `ownershipSyncedAt` records a completed synchronization.

This initial action records a rejection, not a new owner. Ownership reassignment
or reversal requires a separate reviewed workflow; imports cannot silently undo
the correction.
