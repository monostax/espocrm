# AI sessions

- `Resources/`: entity, owner-filter metadata and session API routes.
- `Services/`: owner/account validation and explicit human-submission execution policy.
- `Controllers/`: account-scoped Chatwoot API and standard CRM record controller.
- `Classes/`: mandatory collection and record ACL boundaries.
- `Hooks/`: immutable ownership and workspace validation.

Sessions are private to their human owner in Chatwoot. CRM instance administrators retain stock administrative access; these records are not inaccessible to administrators. AI users receive no general record or collection grant: only a validated submitted execution can obtain context or publish through StreamAgent. Code Mode retains tenant-plus-human workspace isolation.

## Runtime contract

- Explicit `AiSession/:id/posts` submissions capture one exact membership; ordinary Note imports and edits never enqueue turns.
- Last eligible AI link outside quotes/code wins. `AiSession/:id/recipient` powers the composer indication using the same resolver.
- CRM dispatch waits for earlier pending replies; Hatchet queues by tenant/session across agent handoffs. Queued placeholders expire after 24 hours, running ones after 15 minutes.
- Model context includes Markdown, author identities and bounded UTF-8 text/Markdown/CSV/JSON attachment content. Other uploads retain rendering/download support and are explicitly marked as unavailable to the model.
- Private invalidations use the owner's Chatwoot pubsub token. Account-wide activity and mention broadcasts exclude these sessions.
- Agent eligibility also requires read access to its prompt. Private-session knowledge reads use human-authorized CRM APIs; provider-side file-search indexes are disabled because they cannot enforce the human's row/field ACL.

## Deployment

- Rebuild CRM metadata/schema and seeded roles for the new entity.
- Deploy Chatwoot with short Rails routes and private invalidation handling, plus backend API/worker registration for `chatwoot-agent-on-ai-session`.
- Reuse the existing signed stream-agent webhook configuration and AI credential provisioning.
- Personal execution requires `AI_CODE_MODE_ENABLED=1` and the existing Code Mode deployment configuration. Missing or revoked human delegation blocks the turn; private chats never fall back to AI-credential tools. The Code Mode write-enable flag remains authoritative.
