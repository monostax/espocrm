# Custom Fields (tenant-scoped, multi-entity)

## Model

```
CustomFieldGroup  (tenant + entityType + name/label)   → UI panels
CustomFieldDef    (tenant + entityType + group + type) → schema
Entity.customFields jsonObject                         → values (ACL = host record)
```

- **No per-tenant DDL.** Values are a JSON bag on the host record.
- **Groups are presentation.** Moving a field between groups does not change `valueKey`.
- **`valueKey` is immutable** after create. Default: `group.name + '.' + field.name`, or bare `field.name` if ungrouped.
- **Names ban dots** (`^[a-z][a-zA-Z0-9_]*$`) so dotted keys stay unambiguous.
- **Uniqueness:** `(tenantId, entityType, name)` and `(tenantId, entityType, valueKey)`.

## ACL

| Surface | Gate |
| --- | --- |
| Schema admin (Group / Def CRUD) | Team ACL on those entities |
| Meta API `GET CustomField/action/meta` | **Host entity read** (so agents can render panels) |
| Template vars `GET CustomField/action/templateVariables` | Same as meta (host read) |
| Import vars `GET CustomField/action/importVariables` | Same as meta (host read) |
| Values on Contact/Lead/… | **Host entity read/edit** — no separate CustomFieldDef permission |

## Templates (P1)

Handlebars (Htmlizer / WhatsApp parameterMapping):

```
{{customFields.plan}}
{{customFields.address.city}}
```

Classic Email Template placeholders:

```
{Contact.customFields.plan}
{Contact.customFields.address.city}
{Contact.account.customFields.plan}   ← belongsTo / hasOne / belongsToParent one-hop
```

At render time `TemplateBridge` nests the flat bag for Handlebars and expands classic leaf placeholders (including one-hop related). Only defs with `isTemplateEnabled=true` appear in pickers (`getTemplateVariables`).

### Server paths

| Surface | Wiring |
| --- | --- |
| Email (Htmlizer + classic) | `Global\Tools\EmailTemplate\Processor` (bound over core) |
| WhatsApp parameterMapping | `ProcessWhatsAppCampaignChunk::resolveParameterMapping` + `TemplateBridge` |
| Nest helpers | `MetaProvider::expandForTemplate` / `TemplateBridge` |

## Import (CSV)

Map columns to leaf tokens (flat bag keys):

```
customFields.plan
customFields.address.city
```

Or whole bag as one JSON cell:

```
customFields  →  {"plan":"pro","address.city":"SP"}
```

| Behaviour | Detail |
| --- | --- |
| Engine | `Global\Tools\Import\Import` (bound over core) + `ImportValueWriter` |
| Leaf semantics | Merge into existing bag (update-safe); empty cell clears key on update |
| Whole bag | JSON object **merged** into bag (not wipe siblings if keys overlap only those keys) |
| Types | Coerced from def when tenant resolvable (`int`/`float`/`bool`/`multiEnum`/…) |
| Unknown keys | Accepted (orphan keys preserved, same as UI) |
| ACL | `customFields.*` stripped if `customFields` edit-forbidden |
| Picker | Import step2 lists `Custom Fields · {group} · {label}` via `GET CustomField/action/importVariables` |

Tenant for def lookup: entity `tenantId` → `teamsIds` → user teams (same idea as meta API). Set **Teams** (or tenant) as a default value when defs are team-scoped.

### Author UX

- **Email Template → Insert Field:** host CF → `{Entity.customFields.valueKey}`; related belongsTo CF → `{Entity.link.customFields.valueKey}`.
- **CSV Import → column map:** `customFields.<valueKey>` leaves + optional whole JSON bag.
- **WhatsApp Campaign → Parameter mapping:** Suggestion chips for native Contact fields + CF expressions `{{customFields.*}}`.


## Enable entities

`Resources/metadata/app/customFields.json` → `entityTypeList`.

P0 bags wired on: Contact, Lead, Account, Opportunity.
Layouts with panel: Contact, Account, Opportunity.

### Tenant resolution (meta / templateVariables API)

`GET CustomField/action/meta|templateVariables|importVariables?entityType=...` resolve tenant as:

1. `tenantId` query (Contact / Account have it natively)
2. else `teamIds` (host `teamsIds`) via `TenantResolver` → `baseUserTeam` / `otherUserTeams`
3. else `recordId` → load host record teams (ACL-checked)
4. else current user's defaultTeam / teams

Non-admins may only resolve via teams they belong to.

## Admin UI

Admin panel → Data → Custom Field Groups / Custom Fields.

## Rebuild

After deploy: `php rebuild.php` (or container rebuild) so CustomFieldGroup / CustomFieldDef tables are created and Contact.custom_fields column exists.
