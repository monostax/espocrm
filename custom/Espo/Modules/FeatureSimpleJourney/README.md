# Simple journeys

Manual, tenant-defined processes such as onboarding, document collection and payment follow-up. Independent of the automated `FeatureJourney` engine and sales opportunities: no timers, actions, revenue forecasting or automatic transitions.

## Model

```text
Tenant → SimpleJourney → SimpleJourneyStage (ordered, tenant-created records)
                      → SimpleJourneyRecord → stage
                                            → status
                                            → optional assigned user
                                            → SimpleJourneyRecordParent[] → polymorphic parent
```

- **SimpleJourney**: name, description, active flag, explicit `Tenant` link and ACL teams. The form uses the existing logged-user tenant preparator; server-side creation also defaults the tenant and its base team before required validation. For users belonging to multiple tenants, server defaults use the default team's tenant or require an explicit selection rather than guessing.
- **SimpleJourneyStage**: name, journey, order, description and active flag. A stage is an entity, not an enum option or JSON entry. Tenants create stages directly in a journey's **Stages** panel; labels/order can change without breaking record references or editing global metadata.
- **SimpleJourneyRecord**: a named work item in one journey and one of its stages. Both stages and records inherit a read-only `Tenant` link from the journey.
- **SimpleJourneyRecordParent**: a small association entity, one row per parent reference (`recordId`, `parentType`, `parentId`). A journey record can have **many parents of mixed types**: Account, Contact, ChatwootConversation, Task and another SimpleJourneyRecord. Several parents of the same type are also supported. This replaces a singular polymorphic target field; it is not a single-parent tree. Duplicate links and self-links are rejected. Deleting a link never deletes the referenced record.
- **Status belongs to the record in its current stage**, not to the shared stage definition: `On Hold`, `To Do`, `Doing`, `Done`. New records default to `To Do`. Changing stage resets status to `To Do`, including when a status is sent in the same update. Updating status never changes stage. `Done` completes work in that stage, not the entire journey.
- Stage/status changes use Espo's audit stream. There is no per-stage completion matrix or resumable status history; revisiting a stage starts at `To Do`. Those would require a separate record-stage progress/history entity.

## Usage

1. Tenant admin: open **Configurations → Simple Journeys**, create a journey and confirm the prefilled tenant and teams. Instance administrators can also use **Administration → Simple Journeys**.
2. Create and order stages in its **Stages** panel (for example, Documents → Setup → Training).
3. Operators: create records in the journey's **Records** panel, select a stage, and update their status as work progresses.
   In the record's **Related Records** panel, use Create / Add Related Record for each parent. Choose the entity type and select a record; repeat to add any number of parents. Use **Remove Link / Remover Vínculo** in a row's menu to remove only the association, never the referenced parent record.
4. The records list supports journey/stage/status filters and a **status-column Kanban**. Moving cards changes status; change the Stage field to advance the process. This is not a custom-stage-column board.

English and Brazilian Portuguese copy is included in both admin panels, forms, tooltips and feature validation messages. The pt-BR status labels are **Pausado**, **Pendente**, **Em Andamento** and **Concluído**; API values remain unchanged.

Example REST payloads (standard Espo CRUD endpoints):

```json
POST /api/v1/SimpleJourney
{"name":"Customer onboarding","teamsIds":["tenant-team-id"]}

POST /api/v1/SimpleJourneyStage
{"name":"Documents","journeyId":"journey-id","order":10}

POST /api/v1/SimpleJourneyRecord
{"name":"Acme onboarding","journeyId":"journey-id","stageId":"stage-id","status":"To Do"}

PUT /api/v1/SimpleJourneyRecord/record-id
{"status":"Doing"}

POST /api/v1/SimpleJourneyRecordParent
{"recordId":"record-id","parentType":"Contact","parentId":"contact-id"}

POST /api/v1/SimpleJourneyRecordParent
{"recordId":"record-id","parentType":"ChatwootConversation","parentId":"conversation-id"}
```

## Integrity and access

- A journey's teams must all belong to exactly one tenant. Its tenant cannot change after creation.
- Stages and records cannot be reassigned to another journey. The server validates stage membership on every record save, including ORM/import paths.
- Child access is resolved from the **live parent journey**, not copied team fields. Team changes apply immediately. Scope permissions still apply; the mandatory list/search filter also enforces the boundary for roles granting `all`. Portal access is disabled.
- The frontend receives fresh, read-only `simpleJourneyAccess` flags in detail/list/create/update responses. These reflect the server's per-record decisions, including inherited teams and source-record permissions; they are not persisted or trusted for API authorization.
- Tenant operators can read configuration and create/update records. Tenant admins also manage journeys/stages and delete records. Instance admins retain normal administrative access.
- Parents and assignees must belong to the journey's tenant. Adding a parent requires edit access to the journey record and read access to the referenced parent. Task tenancy is resolved from its teams; conversation tenancy comes from its Chatwoot account. Parent links inherit the source record's ACL, including list/search access. Soft-deleted links can be added again; deleting a journey record cascades only its link rows, not the referenced parent records.
- Inactive journeys/stages reject new entries and stage moves but allow updates to existing work. A journey with stages/records or a stage with records cannot be deleted; deactivate it instead.
- Direct relationship link/unlink endpoints and create-time relationship ID/column stubs (`stagesIds`, `recordsIds`, `parentsIds`, and their `Columns` variants) are blocked for ownership/progress links. Use the child's `journeyId`, `stageId` or `recordId` fields so validation and auditing run.

## Deploy and validate

Run the usual CRM source/frontend build, then in the deployed CRM:

```sh
php command.php clear-cache
php command.php rebuild
```

Rebuild creates the tables, adds default navigation and updates seeded tenant roles. Existing customized `SidenavConfig` menus are intentionally not overwritten; add `SimpleJourney` / `SimpleJourneyRecord` there if desired. The Configurations links remain available.

With source Composer dependencies installed:

```sh
php vendor/bin/phpunit tests/unit/Espo/Modules/FeatureSimpleJourney
node --test tests/unit-js/simple-journey-*.test.cjs
```

Smoke-test in a running CRM: create a journey and stages; create a work item from each relationship panel; change stage from Done and verify To Do; filter/list/drag status cards; verify another tenant cannot list/read/link its stages or records; deactivate instead of deleting referenced configuration.

Also test with two different users on the same journey team: both should be able to edit permitted records created/assigned to their colleague. Add and remove parent links from the relationship panel and verify the referenced records remain intact. Automated framework-integration tests use in-memory dependencies, not a live database or browser; a full build/rebuild and these smoke tests are still required before production rollout.
