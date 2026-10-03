# Initiatives

Tenant-defined work units for projects, onboarding, document collection and ongoing engagements. An initiative type defines the reusable stages; an initiative is one concrete undertaking, such as **Acme onboarding**. The feature is labeled **Initiatives** in English and **Iniciativas** in Brazilian Portuguese.

Independent of the automated `FeatureJourney` engine and sales opportunities: no timers, actions, revenue forecasting or automatic transitions. Activities and playbooks remain separate capabilities; this module currently relates existing Tasks through its Related Records panel.

## Model

```text
Tenant → InitiativeType → InitiativeStage (ordered, tenant-created records)
                        → Initiative → stage
                                     → status
                                     → optional assigned user
                                     → InitiativeRelation[] → polymorphic related record
```

- **InitiativeType**: name, description, active flag, explicit `Tenant` link and ACL teams. The form uses the existing logged-user tenant preparator; server-side creation also defaults the tenant and its base team before required validation. For users belonging to multiple tenants, server defaults use the default team's tenant or require an explicit selection rather than guessing.
- **InitiativeStage**: name, initiative type, order, description and active flag. A stage is an entity, not an enum option or JSON entry. Tenants create stages directly in an initiative type's **Stages** panel; labels/order can change without breaking initiative references or editing global metadata.
- **Initiative**: a named work unit of one initiative type and in one of its stages. Both stages and initiatives inherit a read-only `Tenant` link from the initiative type.
- **InitiativeRelation**: a small association entity, one row per related-record reference (`initiativeId`, `parentType`, `parentId`). An initiative can have **many related records of mixed types**: Account, Contact, ChatwootConversation, Task and another Initiative. Several related records of the same type are also supported. This is not a single-parent tree. Duplicate links and self-links are rejected. Deleting a link never deletes the referenced record.
- **Status belongs to the initiative in its current stage**, not to the shared stage definition: `On Hold`, `To Do`, `Doing`, `Done`. New initiatives default to `To Do`. Changing stage resets status to `To Do`, including when a status is sent in the same update. Updating status never changes stage. `Done` completes work in that stage, not the entire initiative.
- Stage/status changes use Espo's audit stream. There is no per-stage completion matrix or resumable status history; revisiting a stage starts at `To Do`. Those would require a separate record-stage progress/history entity.

## Usage

1. Tenant admin: open **Configurations → Initiatives → Initiative Types**, create an initiative type and confirm the prefilled tenant and teams. Instance administrators can also use **Administration → Initiatives**.
2. Create and order stages in its **Stages** panel (for example, Documents → Setup → Training).
3. Operators: create initiatives in the type's **Initiatives** panel, select a stage, and update their status as work progresses.
   In the initiative's **Related Records** panel, use Create / Add Related Record for each relation. Choose the entity type and select a record; repeat to add any number of related records. Use **Remove Link / Remover Vínculo** in a row's menu to remove only the association, never the referenced record.
4. The initiatives list supports initiative type/stage/status filters and a **status-column Kanban**. Moving cards changes status; change the Stage field to advance the process. This is not a custom-stage-column board.

English and Brazilian Portuguese copy is included in both admin panels, forms, tooltips and feature validation messages. The pt-BR status labels are **Pausado**, **Pendente**, **Em Andamento** and **Concluído**; API values remain unchanged.

Example REST payloads (standard Espo CRUD endpoints):

```json
POST /api/v1/InitiativeType
{"name":"Customer onboarding","teamsIds":["tenant-team-id"]}

POST /api/v1/InitiativeStage
{"name":"Documents","initiativeTypeId":"type-id","order":10}

POST /api/v1/Initiative
{"name":"Acme onboarding","initiativeTypeId":"type-id","stageId":"stage-id","status":"To Do"}

PUT /api/v1/Initiative/initiative-id
{"status":"Doing"}

POST /api/v1/InitiativeRelation
{"initiativeId":"initiative-id","parentType":"Contact","parentId":"contact-id"}

POST /api/v1/InitiativeRelation
{"initiativeId":"initiative-id","parentType":"ChatwootConversation","parentId":"conversation-id"}
```

## Integrity and access

- An initiative type's teams must all belong to exactly one tenant. Its tenant cannot change after creation.
- Stages and initiatives cannot be reassigned to another initiative type. The server validates stage membership on every initiative save, including ORM/import paths.
- Child access is resolved from the **live parent initiative type**, not copied team fields. Team changes apply immediately. Scope permissions still apply; the mandatory list/search filter also enforces the boundary for roles granting `all`. Portal access is disabled.
- The frontend receives fresh, read-only `initiativeAccess` flags in detail/list/create/update responses. These reflect the server's per-record decisions, including inherited teams and source-record permissions; they are not persisted or trusted for API authorization.
- Tenant operators can read configuration and create/update initiatives. Tenant admins also manage types/stages and delete initiatives. Instance admins retain normal administrative access.
- Related records and assignees must belong to the initiative's tenant. Adding a relation requires edit access to the initiative and read access to the referenced record. Task tenancy is resolved from its teams; conversation tenancy comes from its Chatwoot account. Relations inherit the source initiative's ACL, including list/search access. Soft-deleted links can be added again; deleting an initiative cascades only its relation rows, not the referenced records.
- Inactive types/stages reject new entries and stage moves but allow updates to existing work. A type with stages/initiatives or a stage with initiatives cannot be deleted; deactivate it instead.
- Direct relationship link/unlink endpoints and create-time relationship ID/column stubs (`stagesIds`, `initiativesIds`, `relationsIds`, and their `Columns` variants) are blocked for ownership/progress links. Use the child's `initiativeTypeId`, `stageId` or `initiativeId` fields so validation and auditing run.

## Deploy and validate

Run the usual CRM source/frontend build, then in the deployed CRM:

```sh
php command.php clear-cache
php command.php rebuild
```

Rebuild creates the initiative tables, adds default navigation and updates seeded tenant roles. Existing customized `SidenavConfig` menus are intentionally not overwritten; add `Initiative` / `InitiativeType` there if desired. The Configurations links remain available.

This is a clean feature rename. Existing data is not migrated or backfilled; deployment starts with the new initiative scopes and tables.

With source Composer dependencies installed:

```sh
php phpunit.phar --do-not-cache-result tests/unit/Espo/Modules/FeatureInitiative
node --test tests/unit-js/initiative-*.test.cjs
```

Smoke-test in a running CRM: create an initiative type and stages; create an initiative from each relationship panel; change stage from Done and verify To Do; filter/list/drag status cards; verify another tenant cannot list/read/link its stages or initiatives; deactivate instead of deleting referenced configuration.

Also test with two different users on the same initiative type team: both should be able to edit permitted initiatives created/assigned to their colleague. Add and remove relations from the relationship panel and verify the referenced records remain intact. Automated framework-integration tests use in-memory dependencies, not a live database or browser; a full build/rebuild and these smoke tests are still required before production rollout.
