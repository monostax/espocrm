# AI sessions

- `Resources/`: entity, owner-filter metadata and session API routes.
- `Services/`: owner/account validation and explicit human-submission execution policy.
- `Controllers/`: account-scoped Chatwoot API and standard CRM record controller.
- `Classes/`: mandatory collection and record ACL boundaries.
- `Hooks/`: immutable ownership and workspace validation.

Sessions are private to their human owner in Chatwoot. CRM instance administrators retain stock administrative access; these records are not inaccessible to administrators. AI users receive no general record or collection grant: only a validated submitted execution can obtain context or publish through StreamAgent. Code Mode retains tenant-plus-human workspace isolation.

## Main chat

Each person has one main chat per workspace (`primarySlot = "main"`, unique with owner and Chatwoot account; `Services/Primary`). It is made on first use: when the session list loads, or when the person writes to Monostax on WhatsApp. Its agent is the one of the person's latest chat, else the first AI of the workspace they may use; without one there is no main chat. It is an ordinary private session (same owner, ACL, agent, model and credit rules), except that it can't be deleted, renamed or pinned, and Chatwoot lists it first as "Principal".

Turns submitted from WhatsApp (FeatureWhatsAppNotifications `Assistant`, as the person through `ActorScope`) carry `data.aiSessionChannel = "whatsapp"`, the subscription and the Chatwoot message id. `Execution::post` passes the channel to the agent, which keeps such answers short; the finished reply is mirrored back to the person's verified number (the Note hook `MirrorAssistantReply`). Composer and WhatsApp submissions share `Services/Submissions`.

In the main chat only, the agent has a `switch_agent` tool (backend `code-mode/session-agent.ts`, offered when the run context's `sessionRecord.mainChat` is true), for when the person asks for another agent. It lists the app picker's agents (`AiSession/action/agents`), then calls `POST AiSession/:id/handoff` as the person. The handoff moves the session to that agent (record update: owner, ACL and agent access are checked again) and posts the person's message once more for the new agent (`Submissions::post` with an explicit agent, so mentions in the text can't redirect it). The new post keeps the WhatsApp origin and `aiSessionHandoffFrom`. It happens once per message (`aiSessionHandedOffTo` on the original) and never for a post that was itself handed over, so agents can't ping-pong. Its run queues behind the old agent's short confirmation. Changing the agent in the app's picker never re-sends anything.

## Runtime contract

- Explicit `AiSession/:id/posts` submissions capture one exact membership; ordinary Note imports and edits never enqueue turns.
- Last eligible AI link outside quotes/code wins. `AiSession/:id/recipient` powers the composer indication using the same resolver.
- CRM dispatch waits for earlier pending replies; Hatchet queues by tenant/session across agent handoffs. Queued placeholders expire after 24 hours, running ones after 15 minutes.
- Model context includes Markdown, author identities and bounded UTF-8 text/Markdown/CSV/JSON attachment content. Other uploads retain rendering/download support and are explicitly marked as unavailable to the model.
- Private invalidations use the owner's Chatwoot pubsub token. Account-wide activity and mention broadcasts exclude these sessions.
- Agent eligibility also requires read access to its prompt. Private-session knowledge reads use human-authorized CRM APIs; provider-side file-search indexes are disabled because they cannot enforce the human's row/field ACL.

## Deployment

- Rebuild CRM metadata/schema and seeded roles for the new entity (and the `primarySlot` column + unique `ownerPrimary` index).
- Deploy Chatwoot with short Rails routes and private invalidation handling, plus backend API/worker registration for `chatwoot-agent-on-ai-session`.
- Reuse the existing signed stream-agent webhook configuration and AI credential provisioning.
- Personal execution uses Code Mode and its configured service origins and workspace infrastructure. Missing or revoked human delegation blocks the turn; private chats never fall back to AI-credential tools. Agent membership grants control writes, execution and sending.
