# FeatureMcp

Monostax additions to the CRM's MCP server (the vendored `Mcp` module, espocrm/ext-mcp). Every tool here is a
feature type with nothing to configure: add it once to an endpoint. ext-mcp finds a tool's feature type by the part
of its name before the first `_`, so each tool is named after its type. Loads after `Mcp`
(order 55) and depends on `Mcp`, `Chatwoot` and `Global`, never the other way round.

## Connection setup

`GET /Mcp/action/setup` (`Tools/Setup`) gives the Chatwoot page Configurações › Conectar IA the endpoints the user
may use and the public OAuth clients with the `Mcp` scope. MCP endpoints and OAuth clients are admin-only records.

## Advanced filter tools

Three MCP feature types with nothing to configure (add each once to an endpoint):

| Feature type | Tool | What it does |
|---|---|---|
| Advanced Filter (`QueryRecords`) | `QueryRecords` | Finds records of any type the advanced filter covers with a record query, a saved filter, text search, `select`, sorting and paging. |
| Advanced Filter Docs (`QueryLanguage`) | `QueryLanguage` | On demand: the syntax; a record type's fields, related records and lists (`path` walks into them); checks a query without running it. |
| Saved Filters (`SavedFilters`) | `SavedFilters` | The user's saved filters (own, and shared in their workspaces); `QueryRecords` takes the id. |

Parity with Monostax is the rule: whatever the "Consulta avançada" box accepts works here, with the same results.

- The query goes to the CRM as the `recordQuery` where item the Chatwoot lists send (Chatwoot
  `Tools/RecordQuery`), through the record service built for the user: ACL, tenant isolation, the enrichment gate and
  the 64-SELECT budget apply unchanged. Record types are those whose `selectDefs` map `id_recordQuery`.
- `QueryLanguage` reads the editor's own Schema (autocomplete) and Compiler (check); error texts are the box's
  (`QueryErrors`, from Chatwoot `advancedFilters.json`). Docs stay out of tool definitions so the tool list stays small.
- Saved filters apply through `ConditionWhere`, a port of the Chatwoot `createCrmWhereBuilder` and its field maps
  (`api/crm/{opportunityFilters,contact,account}.js`). Keep them in step.
- A saved filter shows when Monostax would show it (owner, or shared) in a workspace the user may act in
  (`OpportunityBulkPostAccess::workspace`). MCP calls carry no workspace, so a person in several sees them together.
- Every statement of a query call is capped at 10 s (`StatementTimeout`, MariaDB `max_statement_time`).
- Fields to return default to the record type's list layout; any field the user may read can be selected.

## Knowledge base and AI agents

Same rules as the AI Session's tools (backend `code-mode/knowledge-base.ts`, `agent-instructions.ts`; decided
2026-11-17): changes apply at once as the user and every one is a revision that can be restored. Reading and changing
are separate tools so an MCP client can let reads run and ask before changes.

| Feature type | Tool | What it does |
|---|---|---|
| Knowledge Base (`KnowledgeBase`) | `KnowledgeBase` | Map (categories with AI search, agents using each, titles), search with passage, an article's exact stored text in parts with its version and the agents that find it, its recent changes. |
| Knowledge Base Edit (`KnowledgeBaseEdit`) | `KnowledgeBaseEdit` | Exact-passage replacements (`Replacements`), title, status, categories, with the version check; restore a revision; new article (Markdown) in categories of one workspace, owned by the user's team there. Anyone who can edit by hand. |
| AI Agents (`AiAgents`) | `AiAgents` | The AI agents the user can see (workspace, knowledge categories); an agent's instructions (system prompt) with its hash and recent changes. |
| AI Agents Edit (`AiAgentsEdit`) | `AiAgentsEdit` | Replacements or whole text with the hash check; restore. Tenant admins only (`FeatureAiSession\Services\AgentInstructions`); the tool is not even listed for others. |

Articles go through the user's record services (ACL, validation, indexing and revision hooks) and
`FeatureKnowledgeBaseEditor\Services\ArticleRevisions`. An MCP call has no workspace: an article reaches the agents
of its own tenant.

## Chat (Chatwoot conversations)

Decided 2026-11-25: the tools act in Chat **as the person**, with their own Chat token (`ChatwootUser.userAccessToken`,
fetched with the platform token when missing), never the workspace `apiKey`. Chat then applies its own rules (inbox
membership, teams, custom roles) and everything shows in Chat as done by them.

| Feature type | Tool | What it does |
|---|---|---|
| Conversations | `Conversations` | Open conversations with filters (status, mine / unassigned, inbox, label), message search, a contact's conversations, a conversation with a paged transcript (`Transcript`, the AI Session's read_chat shaping), one message whole, lookups (agents, teams, labels, inboxes, an inbox's WhatsApp templates). |
| Conversation Notes | `ConversationNotes` | Private note, optional @mentions. |
| Conversation Replies | `ConversationReplies` | Message to the customer: free text while `can_reply`, otherwise an approved WhatsApp template with its parameters (`Templates` builds Chatwoot's `template_params`). Marked destructive and open-world so clients ask before each send. |
| Conversation Actions | `ConversationActions` | Status (snooze with a time), assignee or team, labels (replace, add, remove), priority; the result says what each was before. |

- **Identity** (`ChatIdentity`, rules of the backend's `chat-human.ts` / `chat-delegation.ts`): the person's ChatwootUser on
  the workspace's platform with a human membership in it, exactly one; before acting, Chat's `/profile` must answer with
  that user and the workspace active. Workspaces are a tool parameter; people without a Chat identity get no Chat tools.
- **Search and a contact's conversations** open each conversation as the person and drop the ones Chat refuses: Chat's
  search checks inbox membership only (custom roles apply when a conversation is opened).
- **"via Claude"**: notes and replies carry `content_attributes.sent_via_app` with the OAuth client's name
  (`Espo\Tools\OAuthServer\CurrentClient`, set by OAuthLogin); Chatwoot's MessageMeta shows it next to the time.
- The token never leaves the server: not in results, logs or errors (`ChatAccess` hides it from dumps).
