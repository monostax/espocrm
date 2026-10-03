# CRM tags

- Catalog: `CrmTag` in the Global module, scoped by tenant and teams; names are
  unique within a tenant. Colors use the shared six-color UI palette.
- Management: Configurations → Administration → Data and Customization → CRM
  Tags (`Global/Resources/metadata/app/adminForUserPanel.json`). Native CRM
  list/detail screens support creation, rename, color changes, and deletion.
- Assignment: `tagsIds` on Opportunity, Task, Call, and Meeting. CRM tags follow
  the host record's edit permission; assigning one requires tag read access and
  the same workspace. Record save/link hooks enforce the workspace boundary.
- Display: Chatwoot cards, details, activity forms, Kanban, spreadsheet, and the
  native opportunity table. List badges are batch-hydrated after record ACL.
- Filtering: `?tag=<id>` in both workspaces; opportunities also expose CRM tags
  in advanced filters. Sidebar counts aggregate the full authorized scope,
  retain assignee selection, and count each record once per tag.
- Refresh: Catalog saves/removals queue post-commit invalidations for both
  workspaces; assignment changes use the existing record update hooks.
- Rollout: Deploy both components and run the standard CRM rebuild to create
  the catalog/join tables, merge metadata, and update seeded role permissions.
