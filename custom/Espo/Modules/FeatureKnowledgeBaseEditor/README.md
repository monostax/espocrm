# Shared Notion-style editor

## Fields and dependencies

- `KnowledgeBaseArticle.body` and wiki `Document.body`: `bodyEditorState` is canonical JSON; `bodyFormat` selects the HTML/Markdown `body` projection.
- `ChatwootAccountUserMembership.aiPrompt`: `aiPromptEditorState` is canonical JSON; `aiPrompt` is always HTML. The adapter does not submit article attributes. Existing `isAI` layout visibility is retained.
- `taskManagementPrompt` is unchanged.
- Lexical packages are pinned together at **0.48.0**, React/React DOM at **19.2.4**, Floating UI DOM at **1.7.6**. The existing `EspoLexical.createKbEditor` API remains available to agent modes, skills and conversion helpers. React UI plugins are opt-in through `notion: true`.

The implementation uses the public Lexical composer context, typeahead and experimental draggable-block plugin shipped in the pinned package. The document/selection interactions were informed by the Lexical website-notion and Liveblocks examples linked in the workspace plan. No Liveblocks service is involved. Plugin glue and toolbar code are original; dependency copyright/license notices remain in the generated bundle and dependency distributions (Lexical, React and Floating UI: MIT).

## Editing

- `/`: searchable paragraphs, headings 1–3, lists, quotes, code blocks and 3×3 tables. The fixed toolbar also supports custom table dimensions, undo/redo and source editing.
- Hover a block for insertion and drag controls. **Alt+Shift+Up/Down** reorders the current block from the keyboard.
- Select text for bold, italic, underline, strike, inline code and links. **Alt+F10** focuses the selection toolbar; arrow keys move between its controls; Escape returns to the editor.
- `@`: records grouped by entity and the three symbolic run-context references. Record searches are debounced, stale responses discarded, and outstanding requests aborted on rerender/removal.
- Ctrl/Cmd-click a resolved chip to open its CRM record. References neither assign work nor send notifications.

## Portable references

The version-1 `crm-mention` text-entity node stores `reference`:

```json
{"kind":"record","entityType":"Account","recordId":"abc123","label":"Display hint"}
```

or `{"kind":"context","key":"currentOpportunity"}`. Supported types are `User`, `Account`, `Opportunity`, `Contact`, `KnowledgeBaseArticle`, and `Document` pages (`contentType=Page`). Context keys are `currentOpportunity`, `opportunityOwner`, and `primaryContact`.

HTML uses ordinary safe links:

```html
<a href="#crm-reference/v1/record/Account/abc123">Display hint</a>
<a href="#crm-reference/v1/context/opportunityOwner">Opportunity owner</a>
```

Markdown uses the same URLs in ordinary Markdown links. Escaped labels are supported. Tables export as raw HTML blocks in Markdown so cell formatting and references survive source round trips; this does not add GitHub pipe-table import support.

The editor and detail view resolve labels through `EditorReference/resolve` rather than displaying a cached record label as a trusted result. Unavailable targets show **Unavailable reference**. HTML sanitization preserves the link representation. Raw source/JSON/projections still contain author-supplied display hints; they are not an authorization result.

All identity parsing uses an entity allow-list and restricted IDs; stored URLs cannot supply arbitrary navigation destinations. Batch resolution uses strict list ACL plus record and name-field ACL. Users also require the existing active-user/mention permissions, including team restrictions. There is a limit of 200 distinct references per document and ten search results per entity group.

## Reference search

`EditorReference/search` matches all whitespace-separated word prefixes anywhere in the record name, in any order. For example, `Kibu` matches `AI Kibu` and `IA (Kibu) — Overview`, while `ibu` does not match `Kibu`. Entity type names and translated singular/plural labels can qualify a query in either position (`oportunidade drogasil`, `manuella contato`). Portuguese opportunity/contact aliases also work with an English UI. A label by itself remains a name search. Search terms are literal, not user-supplied SQL/full-text operators. Existing recent-view ordering, access checks and ten-results-per-type limits apply.

Large record tables have a dedicated `editorReferenceName` full-text index on their name columns (first/last name for Contact). This keeps matching independent of document bodies and uses indexed boolean word-prefix queries instead of `%term%` scans. Short terms, default InnoDB stopwords, punctuation, and unindexed types use an escaped word-boundary regular expression. Those fallback-only searches can be slower. The MariaDB indexes assume the standard InnoDB token size range 3–84 and default stopword list; rebuild the indexes and update the eligibility check if those settings change. Roll out the index metadata and run the CRM schema rebuild before serving the updated endpoint. Document parent ACL subqueries use correlated primary-key lookups instead of materializing all readable parents.

Recent-view ranking uses the covering `editorReferenceHistory` index and only reads history for the requested entity types.

### Production benchmark (2026-10-09)

The regression was introduced when whole-name prefix filtering became `%term%` filtering. The original `Kibu` search took 1.6–2.0 seconds inside the CRM pod, with approximately 1.67 seconds in its SQL union. After the indexed word-prefix rollout, authenticated HTTP checks (one initial request excluded, 20 sequential samples, a temporary admin session, requests originating inside the cluster) measured:

| Query | Loopback p50 / p95 | Public HTTPS p50 / p95 | Results |
| --- | --- | --- | --- |
| `Kibu` | 220 / 298 ms | 263 / 315 ms | 4 |
| `oportunidade drogasil` | 66 / 133 ms | 95 / 102 ms | 0 |
| `Kibu IA` | 216 / 237 ms | 260 / 289 ms | 4 |
| `Contact manuella` | 80 / 103 ms | 105 / 121 ms | 10 |

`Kibu` retained both agent/user names and their Overview documents. The qualified Drogasil query correctly restricted its scope to Opportunity, but there were no matching records for the benchmark identity. These are warm sequential measurements, not a concurrent load test or browser-to-server timings. The 50 ms end-to-end goal is not met: even an empty authenticated `EditorReference/resolve` request measured 52 ms median before doing search work. Remaining work includes cross-type query construction, record hydration, request initialization, and network overhead.

## Persistence and API writes

The editor saves the projection and canonical JSON together. Source-mode saves import the source before collecting canonical JSON. Invalid/unknown canonical state falls back to the projection when opened.

The `EditorState` ORM hook invalidates canonical state when a projection (or article format) changes without a changed canonical state. This prevents a projection-only API update from being overwritten on reopen. Empty projections clear state. API integrations should either submit a synchronized pair or update the projection alone; do not update canonical JSON in isolation. Direct SQL writes bypass ORM hooks and must clear stale state themselves.

Canonical attributes are included in their projection field's `additionalAttributeList`, so the projection field's ACL also covers the stored JSON.

## Prompt execution

`EditorReference/prompt` reads the saved membership prompt through the **runtime actor's CRM credentials**, rechecks referenced targets and returns readable identities such as `Acme [Account:abc123]`. It never expands page bodies. Failed resolution never falls back to cached target names. Legacy prompts without portable references retain the existing projection path.

| Run | Current Opportunity | Opportunity owner | Primary contact |
| --- | --- | --- | --- |
| Regular/customer-message, scheduled or conversation-mention | Unresolved (a conversation can have several deals) | Unresolved | Unresolved |
| Follow-up | Explicit trigger `opportunityId` | That readable opportunity's `assignedUserId`, with User ACL | Unresolved |
| Opportunity stream | Explicit validated stream `opportunityId` | That readable opportunity's `assignedUserId`, with User ACL | Unresolved |

The endpoint supports an explicit `primaryContactId` binding and checks Contact ACL, but current workflows do not supply one. No relationship ordering is used to infer a primary contact. Missing bindings are rendered as `[Unresolved context: …]`; inaccessible concrete targets as `[Unavailable reference]`.

Reference-bearing prompts require the AI actor to have CRM credentials and read access to the membership prompt. They fail closed if that is unavailable. The two conversation workflow config tasks preserve portable HTML until the shared runner can bind the execution context. The opportunity-stream workflow uses its already authenticated actor client. Historical `.hz.snapshots` files are not live consumers.

## Backlinks

`EditorReferenceIndex` is internal storage with no public CRUD actions. Source saves/deletions and index maintenance run within the source entity's `transactionalSave` transaction. Repeated references deduplicate; context references do not create permanent record backlinks.

The **Mentioned in** panels expose only readable source records/fields. The endpoint validates index entries against saved source state and paginates visible results without exposing unfiltered totals or cursors for hidden-only pages.

Rebuild/backfill (pause writes first; rerunning is safe):

```sh
php run-module-script.php \
  custom/Espo/Modules/FeatureKnowledgeBaseEditor/Scripts/RebuildReferences.php \
  'Espo\Modules\FeatureKnowledgeBaseEditor\Scripts\RebuildReferences'
```

This clears orphaned rows and reconstructs the index from saved canonical state. Projection-only legacy documents acquire canonical state when saved through the editor.

## Build and rollout

From `components/crm/source`:

```sh
npm ci
npm run build:editor
node js/transpile-custom-modules.js
```

Both generated editor bundles are tracked. Custom AMD assets are regenerated by the existing module transpiler. Deploy the CRM code/assets, then run the usual CRM rebuild (`php rebuild.php`) and cache clear (`php clear_cache.php`) to add `ai_prompt_editor_state` and `editor_reference_index`, register routes/hooks, and refresh metadata. Deploy the backend changes after the CRM endpoint/schema is available. Existing records do not require conversion.

### Focused checks

```sh
npx playwright install chromium
npm run test:editor
php phpunit.phar --do-not-cache-result tests/unit/Espo/Modules/FeatureKnowledgeBaseEditor
```

`CHROMIUM_PATH` can select an installed browser. Browser tests use the actual generated bundle; field-storage tests load the adapter classes with minimal field infrastructure. Backend checks (from `components/backend/source`):

```sh
node --import tsx --experimental-test-module-mocks --test \
  'source/modules/hatchet/helpers/$editor-prompt.test.ts' \
  'source/modules/hatchet/agents/$chatwoot-agent-runner.test.ts' \
  'source/modules/hatchet/workflows/$chatwootAgentOnOpportunityStream.test.ts'
```

Before rollout, verify save/reopen/cancel in a deployed CRM's inline editor and full-record modal, real-role search/backlink ACL, schema creation, source-save transaction rollback, and index rebuild. The local checks do not substitute for those database/deployment smoke checks. Hover previews, user notifications, AI writing actions, and comments remain future features.
