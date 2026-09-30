# Opportunity playbooks

## Installation and access

- Run the CRM's normal rebuild after deployment (`php command.php rebuild`) to create the four entity tables and indexes; rebuild client assets for the native panel.
- Runs inherit the live Opportunity read/edit ACL. All operations use `/Opportunity/:id/playbooks`; generic entity CRUD, mass services, and exports are unavailable.
- Published templates are bounded by tenant, shared teams, and Playbook read permission. Grant Playbook create/edit permission to template authors. New templates inherit the opportunity tenant and teams.
- Chatwoot's Opportunity Details/sidebar includes application, template authoring/publication/archival, execution, and Task activation. The native CRM Opportunity side panel supports application and execution and links to the template manager.

## Feature entrypoints

| Surface | Entry |
| --- | --- |
| User configurations | `#Configurations` → Automation → Playbooks → Playbook templates / Playbook runs. Registered in `Resources/metadata/app/adminForUserPanel.json`. |
| Administrator panel | `#Admin` → Playbooks → Playbook templates / Playbook runs. Registered in `Resources/metadata/app/adminPanel.json`. |
| Native template manager | `#PlaybookManager/index/view=templates`. Select an opportunity for its tenant/team library; create, edit, reorder steps, publish, or archive templates. |
| Native run manager | `#PlaybookManager/index/view=runs`. Select an opportunity to apply playbooks and inspect or change its runs. |
| Native Opportunity | `#Opportunity/view/<id>` → Playbooks side panel. “Playbook templates” opens the manager with `opportunityId=<id>`. |
| Chatwoot Opportunity | Details/sidebar → Apply playbook → Manage templates. Both standalone and embedded Details layouts share `OpportunitySidebar.vue`. |

The management page requires Opportunity read access; template authoring and run mutations retain all server-side checks. An opportunity is required as context: these screens do not expose cross-opportunity/global CRUD on the internal entities. Client entry: `client/custom/modules/feature-playbook/src/controllers/manager.js`; server entry: `Controllers/OpportunityPlaybook.php` → `Services/Playbooks.php`.

## API

| Request | Contract |
| --- | --- |
| `GET /Opportunity/:id/playbooks` | Returns permitted templates (including editable drafts/archives), runs, and capabilities. |
| `POST /Opportunity/:id/playbooks` | `{requestKey, templateId}` or `{requestKey, name, steps}`. Same key and payload return the existing run; a fresh key deliberately reapplies. |
| `POST /Opportunity/:id/playbooks/templates` | `{id?, expectedRevision?, name, status, steps}`. Saves a new revision; Published requires at least one step. |
| `POST /Opportunity/:id/playbooks/:runId` | `{action: "step", stepId, status}`, `{action: "activate", stepId, assignedUserId?, dateEndDate?}`, `{action: "addStep", step}`, `{action: "stop" or "cancel", reason}`, or `{action: "resume"}`. |

Step definitions use `{name, kind: "Check" or "Task", instructions?, references?: string[]}`. Array order becomes execution order. Titles are plain text; references allow HTTP/HTTPS only. Runs support up to 100 steps. Ad hoc runs can append steps; template-backed runs retain their snapshot.

## Lifecycle decisions

- Progress is completed / original total; skipped steps are displayed separately and remain in the denominator. All steps Completed or Skipped closes a nonempty run as Completed. Cancelled steps do not count as resolved.
- Stopped means the process legitimately ended early (for example, a prospect replied); Cancelled means the run was abandoned or applied in error. Both require a reason and retain completed/skipped history.
- Closing cancels unfinished linked Tasks atomically, requiring Task edit permission. Completed Tasks are preserved. Resuming retains the last stop reason and does not reopen Tasks or schedule work.
- Task status owns task-backed completion: Completed → Completed, Canceled → Cancelled, other statuses → Pending. Changes from either surface update the same run. Skipping an activated step requires a cancelled or deleted Task.
- Deleting an unfinished Task leaves a cancelled step and its historical Task ID. Activation never recreates it; it can be explicitly skipped. Deleting a completed Task preserves completion attribution.
- Reopening a step reopens a Completed run. Stopped/Cancelled runs must be explicitly resumed before changing Task status. Linked Tasks cannot be reparented. Task saves/removals are transactional; opportunity row locks serialize them with run mutations.
- Automatic cadence timing, recommendations, arbitrary branching, and outbound messaging are follow-up work.
