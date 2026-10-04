# Initiatives

Tenant-defined work units for projects, onboarding, document collection and ongoing engagements. An initiative type defines the reusable stages; an initiative is one concrete undertaking, such as **Acme onboarding**. The feature is labeled **Initiatives** in English and **Iniciativas** in Brazilian Portuguese.

Independent of the automated `FeatureJourney` engine and sales opportunities: no timers, revenue forecasting or automatic transitions. Initiatives explicitly select a pending Task, Call or Meeting as their next action, using the same API contract and implementation as opportunities. The Related Records panel can also link existing Tasks without making them the initiative's next action.

## Model

```text
Tenant → InitiativeType → InitiativeStage (ordered, tenant-created records)
                        → Initiative → stage
                                     → status (derived from stage.category)
                                     → optional assigned user
                                     → InitiativeRelation[] → polymorphic related record
```

- **InitiativeType**: name, description, active flag, explicit `Tenant` link and ACL teams. The form uses the existing logged-user tenant preparator; server-side creation also defaults the tenant and its base team before required validation. For users belonging to multiple tenants, server defaults use the default team's tenant or require an explicit selection rather than guessing.
- **InitiativeStage**: name, initiative type, order, category, description and active flag. A stage is an entity, not an enum option or JSON entry. Tenants create stages directly in an initiative type's **Stages** panel; labels/order can change without breaking initiative references or editing global metadata.
- **Initiative**: a named work unit of one initiative type and in one of its stages. Both stages and initiatives inherit a read-only `Tenant` link from the initiative type.
- **InitiativeRelation**: a small association entity, one row per related-record reference (`initiativeId`, `parentType`, `parentId`). An initiative can have **many related records of mixed types**: Account, Contact, ChatwootConversation, Task and another Initiative. Several related records of the same type are also supported. This is not a single-parent tree. Duplicate links and self-links are rejected. Deleting a link never deletes the referenced record.
- **Stage category determines initiative status**: `Open`, `In Progress`, `Paused`, `Completed`, `Canceled`. Backlog and Planned can be separate stages under `Open`. Each stage defaults to `Open`; configure its category once. Initiative status is read-only and derived on every save, including ORM/import paths. Changing a stage's category synchronizes all its existing initiatives. Users only change the stage. `Completed` and `Canceled` describe the whole initiative, not progress within a stage.
- **Next action** is an explicit `nextActionId` / `nextActionType` reference to one pending Task, Call or Meeting whose `parentType` / `parentId` identifies the initiative. Stage/status changes preserve the selection. Like Opportunity, references are nullable during creation and after clearing/completion, and read-only through ordinary record writes.
- Initiative stage changes and their derived status changes use Espo's audit stream. Stage category changes refresh the stored status in bulk without generating individual initiative audit entries or changing their timestamps. Revisiting a stage derives its category again; there is no per-stage completion matrix.

## Usage

1. Tenant admin: open **Configurations → Initiatives → Initiative Types**, create an initiative type and confirm the prefilled tenant and teams. Instance administrators can also use **Administration → Initiatives**.
2. Create and order stages in its **Stages** panel and assign categories (for example, Planned → Open, Documents/Setup/Training → In Progress, Waiting → Paused, Delivered → Completed, Canceled → Canceled).
3. Operators: create initiatives in the type's **Initiatives** panel, select a stage, and change the stage as work progresses. Status follows automatically.
   In the initiative's **Related Records** panel, use Create / Add Related Record for each relation. Choose the entity type and select a record; repeat to add any number of related records. Use **Remove Link / Remover Vínculo** in a row's menu to remove only the association, never the referenced record.
4. The initiatives list supports initiative type/stage/status filters. The old status-column Kanban is disabled because status is derived; change the Stage field to advance the process.

English and Brazilian Portuguese copy is included in both admin panels, forms, tooltips and feature validation messages. The English category/status labels are **Planned**, **In Progress**, **Paused**, **Completed** and **Canceled**. The pt-BR labels are **Planejada**, **Em andamento**, **Pausada**, **Concluída** and **Cancelada**. The Planned/Planejada category retains the internal API value `Open` for compatibility with existing records and filters; the other API values match their English labels.

Example REST payloads (standard Espo CRUD endpoints):

```json
POST /api/v1/InitiativeType
{"name":"Customer onboarding","teamsIds":["tenant-team-id"]}

POST /api/v1/InitiativeStage
{"name":"Documents","initiativeTypeId":"type-id","order":10,"category":"In Progress"}

POST /api/v1/Initiative
{"name":"Acme onboarding","initiativeTypeId":"type-id","stageId":"stage-id"}

PUT /api/v1/Initiative/initiative-id
{"stageId":"next-stage-id"}

POST /api/v1/InitiativeRelation
{"initiativeId":"initiative-id","parentType":"Contact","parentId":"contact-id"}

POST /api/v1/InitiativeRelation
{"initiativeId":"initiative-id","parentType":"ChatwootConversation","parentId":"conversation-id"}
```

## Next-action API

The endpoints mirror [Opportunity next action](../../../../docs/opportunity-next-action.md):

- `POST /api/v1/Initiative/{id}/nextAction`: select with `activityId` and `activityType`, or clear with `activityId: null`.
- The same endpoint accepts `createTask: true`, `name` and optional `dateEndDate` (`YYYY-MM-DD`). The Task belongs to the initiative, inherits its assigned user (or the current user) and the live initiative type's teams.
- `POST /api/v1/Initiative/{id}/nextAction/complete`: complete the selected activity using its metadata-defined completed status and clear the reference atomically.

Both endpoints require nullable `expectedId` and `expectedType` matching the displayed reference; stale references return 409. They require initiative read/edit access and a readable, pending activity belonging directly to that initiative. Completion also requires activity edit and status-field edit access. Create/select and complete/clear run in transactions and use the standard activity record services for validation and hooks. A Task linked only through `InitiativeRelation` is not eligible unless it also has the initiative as its parent.

Every initiative category supports next-action operations, including follow-up work on completed or canceled initiatives.

```json
POST /api/v1/Initiative/initiative-id/nextAction
{"expectedId":null,"expectedType":null,"createTask":true,"name":"Collect onboarding documents","dateEndDate":"2026-10-05"}

POST /api/v1/Initiative/initiative-id/nextAction
{"expectedId":"task-id","expectedType":"Task","activityId":"call-id","activityType":"Call"}

POST /api/v1/Initiative/initiative-id/nextAction/complete
{"expectedId":"call-id","expectedType":"Call"}
```

## Chatwoot initiative inbox

The Chatwoot workspace is available at `/app/accounts/{accountId}/initiatives`, with record permalinks at `/app/accounts/{accountId}/initiatives/{initiativeId}`. It reuses the Opportunity discussion, thread, attachment, reaction, inline-field and next-action components.

The inbox supports All/Mine/Unassigned scopes, unread views, type/stage/status/owner/activity filters, condition rows, sorting, collapsible groups, and paginated group-wide selection. Groups are read status, initiative type, stage, status, owner, next action, or no grouping. Counts are computed across the authorized result set before pagination. Completed and canceled initiatives remain visible and can have a next action. Status is display-only; stage changes derive it on the server.

All inbox endpoints require the Chatwoot `accountId` query parameter. List, metadata, options, navigation-count and group-summary endpoints live under `/api/v1/Initiative/action/`; record CRUD uses `/api/v1/Initiative/{id}/inbox`. Discussion uses `/{id}/discussion`, `/{id}/posts` and `/{id}/readState`. Posts and read cursors use the existing Note and ActivityReadState infrastructure. Tenant membership, live initiative-type ACL, field ACL and stream permissions apply server-side, including direct Note and Attachment access. Rebuild the CRM to register these routes.

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

Rebuild creates the initiative tables, adds the nullable next-action fields and activity parent relationships, registers the next-action routes, adds default navigation and updates seeded tenant roles. Existing initiatives start without a selected next action. Existing customized `SidenavConfig` menus are intentionally not overwritten; add `Initiative` / `InitiativeType` there if desired. The Configurations links remain available.

Rebuild initializes existing stages without a category to `Open`, then synchronizes initiative statuses from their stages. The operation is idempotent and preserves configured categories. Old per-initiative statuses cannot reliably identify a shared stage's lifecycle category: review the categories of existing stages after deployment. Stage configuration and initiative references are preserved.

With source Composer dependencies installed:

```sh
php phpunit.phar --do-not-cache-result tests/unit/Espo/Modules/FeatureInitiative tests/unit/Espo/Modules/Global/Controllers/RecordNextActionTest.php
node --test tests/unit-js/initiative-*.test.cjs
```

Smoke-test in a running CRM: create an initiative type and stages with each category; create an initiative from each relationship panel; change stage and verify status follows the category; change a stage category and verify all its initiatives and status filters/counts update; verify status cannot be manually edited in CRM or Chatwoot; verify another tenant cannot list/read/link its stages or initiatives; deactivate instead of deleting referenced configuration.

For next actions, create/select a Task through the API and verify its parent, owner, teams and optional date. Select and complete a pending Call and Meeting, verify stale expected references return 409, and confirm stage/status changes preserve the selected reference. Clearing a selection must leave the activity intact.

Also test with two different users on the same initiative type team: both should be able to edit permitted initiatives created/assigned to their colleague. Add and remove relations from the relationship panel and verify the referenced records remain intact. Automated framework-integration tests use in-memory dependencies, not a live database or browser; a full build/rebuild and these smoke tests are still required before production rollout.
