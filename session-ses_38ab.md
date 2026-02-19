# Virtual Folder Tabs in Sidenav with Saved Filters and Global Module Integration

**Session ID:** ses_38ab0b24affeHASVSib3jPPEz1
**Created:** 2/19/2026, 6:50:57 AM
**Updated:** 2/19/2026, 6:57:29 AM

---

## User

given sidenav sidebar feature (you can fund more info on .scopes/ folder) we need to scope an .scopes/virtual-folder-tablist-type.v1.md

so in this feature we want to use entities saved filters to group record items in the sidenav. so the entity name/label is like the `Divider` css and link items are records. we can have options on each to limit the number of visible li items per virtual folder. also we should show the icon of the entity on the left side (so entity icon + name on sidenav record item)

the virtual folder use an saved filter but it can have an "label" which will be used as the "Divider" label name.

we can collapse / expand these virtual folders in the sidenav.

they should be the same concept (sibling) of addGroup addDivider and addUrl

in the right side of the divider label (on hover) we should show an more options menu and an button icon with plus that should open the quick create for the entity

should use Global module on custom/Espo/Modules/Global and client/custom/modules/global 

---

## Assistant (Scope · glm-5-free · 14.3s)

_Thinking:_

The user wants me to scope a new feature called "virtual-folder-tablist-type" for a sidenav sidebar. Let me understand what they're asking for:

1. This is a sidenav feature that uses entities' saved filters to group record items
2. The entity name/label acts like a "Divider" with link items being records
3. Each virtual folder can have options to limit visible items
4. Should show entity icon + name on sidenav record items
5. Virtual folders use saved filters but can have a custom "label" for the divider
6. Can collapse/expand these virtual folders
7. Should be siblings of addGroup, addDivider, and addUrl (same concept level)
8. Right side of divider label on hover: more options menu + plus button for quick create
9. Should use Global module at custom/Espo/Modules/Global and client/custom/modules/global

Let me start by exploring:
1. The .scopes/ folder to understand existing scope documents
2. The Global module structure
3. Existing sidenav implementation patterns
4. How addGroup, addDivider, addUrl work
5. Saved filters implementation
6. Quick create implementation

Let me explore the codebase systematically.

I'll explore the codebase to understand the existing sidenav implementation, the Global module patterns, and how to properly scope this virtual-folder-tablist-type feature.

**Tool: glob**

**Input:**
```json
{
  "pattern": ".scopes/**/*"
}
```

**Output:**
```
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.walkthrough.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-ordering-and-disable.walkthrough.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-ordering-and-disable.v3.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-ordering-and-disable.v2.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-ordering-and-disable.v2.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-ordering-and-disable.v1.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-ordering-and-disable.v1.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v4.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v4.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v3.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v3.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v2.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v2.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v1.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v1.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v10.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v9.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v9.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v8.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v8.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v7.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v7.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v6.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v6.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v5.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v5.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v4.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v4.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v3.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v3.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v2.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v2.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v1.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v1.audit.md
```

**Tool: glob**

**Input:**
```json
{
  "pattern": "custom/Espo/Modules/Global/**/*.php"
}
```

**Output:**
```
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/EntryPoints/PublicDownload.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Credential/CredentialResolver.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Credential/HealthCheckers/GenericHttpHealthChecker.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/FieldProcessing/User/RoleNameLoader.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Credential/HealthCheckers/HealthCheckerInterface.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/AppParams/TeamSidenavConfigs.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Credential/HealthCheckers/HealthCheckResult.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Credential/HealthCheckManager.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Controllers/CredentialHistory.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Credential/HealthCheckers/WhatsappCloudApiHealthChecker.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Controllers/CredentialType.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Rebuild/SeedRole.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Hooks/Common/CascadeDelete.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Controllers/SidenavConfig.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Hooks/SidenavConfig/EnsureSingleDefault.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/FieldProcessing/Role/NameLoader.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Controllers/Credential.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Activities/ListService.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Activities/Api/GetList.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Activities/List/Params.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Kanban/KanbanService.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Kanban/CustomOrderer.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Kanban/OpportunityOrdererProcessor.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/Kanban/OpportunityKanban.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Tools/AdminNotifications/LatestReleaseDataRequester.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Rebuild/SetDefaultSidePanelView.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Rebuild/SeedCalendarViews.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Rebuild/ModifyConfig.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Rebuild/AddAppointmentToCalendar.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Rebuild/SeedTeams.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Core/Utils/Metadata/AdditionalBuilder/AdminForUserPanel.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Core/Utils/Database/Orm/FieldConverters/Weight.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Core/Field/Weight/WeightFactory.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Core/Field/Weight/WeightAttributeExtractor.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Core/Field/Weight.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Core/Console/Commands/Upgrade.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Select/Task/BoolFilters/AssignedToMe.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Select/OpportunityStage/AccessControlFilters/OnlyTeam.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Hooks/Opportunity/ValidateStageFunnel.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Hooks/Funnel/EnsureSingleDefault.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Hooks/User/AddDefaultCalendarViews.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Hooks/Appointment/SyncAssignedUsers.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Controllers/OpportunityStage.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Controllers/Activities.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Controllers/Opportunity.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Controllers/Funnel.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Controllers/CAIThread.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Controllers/CAIPlaybook.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Controllers/Appointment.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Select/OpportunityStage/PrimaryFilters/Active.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Select/OpportunityStage/BoolFilters/OnlyActive.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Entities/OpportunityStage.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Entities/Funnel.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Binding.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Acl/Funnel/OwnershipChecker.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Acl/Funnel/AccessChecker.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Acl/User/AccessChecker.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Acl/OpportunityStage/OwnershipChecker.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Acl/OpportunityStage/AccessChecker.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Select/Call/BoolFilters/AssignedToMe.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Select/Funnel/AccessControlFilters/OnlyTeam.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Select/Funnel/PrimaryFilters/Active.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Select/Funnel/BoolFilters/OnlyActive.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/Select/Meeting/BoolFilters/AssignedToMe.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/RecordHooks/User/BeforeUpdate.php
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/RecordHooks/User/BeforeCreate.php
```

**Tool: glob**

**Input:**
```json
{
  "pattern": "client/custom/modules/global/**/*"
}
```

**Output:**
```
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/handlers/attachment/toggle-public.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/controllers/calendar.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/controllers/admin-for-user.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/controllers/activities.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/views/site/navbar-config-selector.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/views/site/navbar.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/controllers/credential-type.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/controllers/credential-history.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/controllers/activities.js.map
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/controllers/credential.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/controllers/admin-for-user.js.map
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/controllers/calendar.js.map
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/handlers/attachment/toggle-public.js.map
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/views/modals/mobile-filter.js.map
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/views/modals/navbar-config-field-add.js.map
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/views/site/navbar-config-selector.js.map
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/views/site/navbar.js.map
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential/record/edit.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/resource-calendar.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/controllers/credential.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/calendar/resource-calendar.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential/fields/config.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential-type/record/detail.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/controllers/credential-history.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/modals/navbar-config-field-add.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/preferences/fields/active-navbar-config.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/site/navbar.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential-history/record/detail.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/handlers/attachment/toggle-public.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/site/navbar-config-selector.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential/record/list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential/record/detail.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/css/navbar-config-selector.css
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/site/navbar-config-selector.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential/fields/metadata.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/controllers/credential-type.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/record/detail.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/record/detail.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/css/mobile-drawer.css
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/record/list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/record/search-mobile.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/modals/mobile-filter.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/modals/mobile-filter.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/record/search-mobile.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/controllers/ccredential-history.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/controllers/ccredential-type.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/controllers/ccredential.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/record/panels/default-side.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/record/panels/activities.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/opportunity/record/kanban-item.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/opportunity/record/kanban.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/admin-for-user/index.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/calendar/mode-buttons.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/calendar/color-picker-popover.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/opportunity/record/kanban-item.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/opportunity/record/kanban.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/opportunity/fields/opportunity-stage.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/admin-for-user/index.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/record/list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/fields/name-with-icon.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/fields/entity-type.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/search.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/mode-buttons.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/modals/manage-users.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/modals/resource-options.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/color-picker-popover.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/calendar.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/calendar-page.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/fields/link-multiple-with-icons.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/fields/relationship-list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/fields/complex-created.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/fields/followers.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/controllers/calendar.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/controllers/admin-for-user.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/controllers/activities.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/activities/fields/name-with-icon/list-link.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/activities/fields/name-with-icon/list.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/activities/fields/entity-type/list.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/activities/fields/entity-type/detail.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/build-scheduler.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/original/fullcalendar-premium-common.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/original/fullcalendar-resource-timegrid.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/original/fullcalendar-resource-daygrid.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/original/fullcalendar-resource.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/original/fullcalendar-scheduler-bundle.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/fullcalendar-premium-common.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/iframe-parent-bridge.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/README.md
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/iframe-detection.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/fullcalendar-resource-timegrid.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/fullcalendar-resource-daygrid.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/fullcalendar-resource.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/fullcalendar-scheduler-bundle.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/views/dashlets/external-number.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/views/dashlets/external-number.js.map
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/views/account/record/detail.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/views/account/record/detail.js.map
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/helpers/site/tabs.js.map

(Results are truncated: showing first 100 results. Consider using a more specific path or pattern.)
```

**Tool: grep**

**Input:**
```json
{
  "pattern": "addGroup|addDivider|addUrl",
  "include": "*.php"
}
```

**Output:**
```
Found 3 matches
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Advanced/scripts/AfterInstall.php:
  Line 630:         $url = $this->addUrlParam($url, 'instanceId', $config->get('instanceId'));
  Line 631:         $url = $this->addUrlParam($url, 'advanced-pack', 'c4060ef13557322b374635a5ad844ab2');
  Line 641:     private function addUrlParam(string $url, string $paramName, $paramValue): string
```

---

## Assistant (Scope · glm-5-free · 27.9s)

_Thinking:_

Let me explore the latest sidenav scope files to understand the existing implementation patterns, and look for addGroup, addDivider, addUrl patterns in the sidenav implementation.

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v10.md"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v10.md</path>
<type>file</type>
<content>1: # Multi-Sidenav Sidebar Mode - Implementation Plan v10
2: 
3: > **Version**: 10.0  
4: > **Based on**: `.scopes/multi-sidenav-sidebar.v9.md` and `.scopes/multi-sidenav-sidebar.v9.audit.md`  
5: > **Codebase Root**: `components/crm/source/`  
6: > **Status**: File Manifest - SCOPE MAPPED
7: 
8: ## Overview
9: 
10: Feature request to implement a multi-sidenav sidebar mode allowing users to toggle between different navbar configurations using a dropdown selector in the sidebar.
11: 
12: ### Requirements
13: 1. **UI Pattern**: Dropdown/selector in the sidebar for switching views
14: 2. **Configuration**: Each navbar config has its own complete `tabList`
15: 3. **Levels**: Both system-level defaults and user-level overrides
16: 4. **Quantity**: Unlimited configurable navbar views
17: 
18: ---
19: 
20: ## Audit Corrections Applied (v9 → v10)
21: 
22: ### Warning Items Addressed
23: 
24: | Warning | v10 Correction |
25: |---------|----------------|
26: | Config Change Event Pattern | **CLARIFIED**: The existing `this.listenTo(this.getHelper().settings, 'sync', () => update())` at navbar.js:463 already handles ALL config changes including `navbarConfigList`. No additional listener needed. Only need to add new preference fields to the existing preferences listener at lines 471-474. |
27: | Missing Test Files | **NOTED**: Testing will be manual/ad-hoc. No test infrastructure changes required. |
28: 
29: ### Suggestions (Not Blocking - Listed for Consideration)
30: 
31: | Suggestion | Status |
32: |------------|--------|
33: | Tooltip for resolution priority order | Optional - add to `navbarConfigList` tooltip in Settings.json |
34: | Server-side validation for ID uniqueness | Optional - can be added later if needed |
35: | Loading state during initial config load | Optional - UX enhancement |
36: 
37: ---
38: 
39: ## Decisions Made
40: 
41: | Question | Decision | Rationale |
42: |----------|----------|-----------|
43: | Default Behavior | Keep existing `tabList` as fallback; first navbar config must be explicitly created | Backward compatible, no migration needed |
44: | Selector Visibility | Hidden when ≤1 navbar config exists | Cleaner UI when feature not actively used |
45: | Admin UI | Field on User Interface page (not separate page) | Simpler implementation, follows existing pattern |
46: | Portal Support | **Out of scope** for v10 | Portal has separate `tabList` system; can be added later |
47: | Storage Strategy | Server-side Preferences only | Syncs across devices, simpler implementation |
48: | Active Config Save | Use `Espo.Ajax.putRequest()` to update Preferences | No new backend action needed, uses existing REST API |
49: | Config Change Event | Use existing `settings.sync` listener | Already handles all config changes at navbar.js:463 |
50: | Testing | Manual/ad-hoc | No test file infrastructure changes |
51: 
52: ---
53: 
54: ## Current System Architecture
55: 
56: ### Existing Navbar Modes
57: - **Location**: `application/Espo/Resources/metadata/themes/Espo.json`
58: - Two modes supported: `side` (sidebar) and `top` (horizontal navbar)
59: - Configured via theme `params.navbar` enum field
60: 
61: ### Current Tab List Structure
62: - **Settings Field**: `tabList` in `application/Espo/Resources/metadata/entityDefs/Settings.json:245-255`
63: - **Preferences Field**: `tabList` in `application/Espo/Resources/metadata/entityDefs/Preferences.json:171-181`
64: - **Field View**: `client/src/views/settings/fields/tab-list.js`
65: - **Helper Class**: `client/src/helpers/site/tabs.js`
66: 
67: ### Existing Preference Fields (MUST ACCOUNT FOR)
68: | Field | Type | Location | Purpose |
69: |-------|------|----------|---------|
70: | `useCustomTabList` | bool | `Preferences.json:162` | User has custom tab list enabled |
71: | `addCustomTabs` | bool | `Preferences.json:166` | User's tabs are additive to system tabs |
72: | `tabList` | array | `Preferences.json:171` | User's custom tab list |
73: 
74: **Existing Resolution Logic** (`client/src/helpers/site/tabs.js:67-79`):
75: ```javascript
76: getTabList() {
77:     let tabList = this.preferences.get('useCustomTabList') && !this.preferences.get('addCustomTabs') ?
78:         this.preferences.get('tabList') :
79:         this.config.get('tabList');
80: 
81:     if (this.preferences.get('useCustomTabList') && this.preferences.get('addCustomTabs')) {
82:         tabList = [
83:             ...tabList,
84:             ...(this.preferences.get('tabList') || []),
85:         ];
86:     }
87: 
88:     return Espo.Utils.cloneDeep(tabList) || [];
89: }
90: ```
91: 
92: ### Tab List Item Types
93: 1. **Scope**: String entity name (e.g., `"Accounts"`, `"Contacts"`)
94: 2. **Group**: Object with `type: "group"`, `text`, `iconClass`, `color`, `itemList`
95: 3. **URL**: Object with `type: "url"`, `text`, `url`, `iconClass`, `color`, `aclScope`, `onlyAdmin`
96: 4. **Divider**: Object with `type: "divider"`, `text`
97: 5. **Delimiter**: String `"_delimiter_"` or `"_delimiter-ext_"` for more menu split
98: 
99: ### Existing Translation Section (MUST USE)
100: **Location**: `application/Espo/Resources/i18n/en_US/Global.json:988-994`
101: ```json
102: "navbarTabs": {
103:     "Business": "Business",
104:     "Marketing": "Marketing",
105:     "Support": "Support",
106:     "CRM": "CRM",
107:     "Activities": "Activities"
108: }
109: ```
110: 
111: ### Verified CSS Variables
112: **Location**: `frontend/less/espo/root-variables.less`
113: - Spacing: `--8px`, `--12px`, `--4px`, etc. (lines 10, 14, 6)
114: - Layout: `--navbar-width` (232px, line 108), `--border-radius` (line 440)
115: - Colors: `--navbar-inverse-link-hover-bg` (line 392), `--navbar-inverse-border` (line 388), `--dropdown-link-hover-bg` (line 490), `--dropdown-link-hover-color` (line 489)
116: 
117: ### Verified Bootstrap Variables
118: **Location**: `frontend/less/espo/bootstrap/variables.less:37`
119: - `@screen-xs-max: (@screen-sm-min - 1px);`
120: 
121: ---
122: 
123: ## Data Model Design
124: 
125: ### New Navbar Configuration Object
126: ```json
127: {
128:   "id": "navbar-config-123",
129:   "name": "Business",
130:   "iconClass": "fas fa-briefcase",
131:   "color": "#4A90D9",
132:   "tabList": [
133:     "Home",
134:     "Accounts",
135:     "Contacts",
136:     {"type": "group", "text": "Sales", "itemList": ["Opportunities", "Leads"]}
137:   ],
138:   "isDefault": false
139: }
140: ```
141: 
142: ### Settings Fields (System Level)
143: | Field | Type | Description |
144: |-------|------|-------------|
145: | `navbarConfigList` | `jsonArray` | Array of navbar configuration objects |
146: | `navbarConfigDisabled` | `bool` | Disable user customization (default: false) |
147: | `navbarConfigSelectorDisabled` | `bool` | Hide selector dropdown (default: false) |
148: 
149: **Rationale for `jsonArray` type**: Unlike `tabList` which uses `array` type, `navbarConfigList` uses `jsonArray` because each config contains nested `tabList` with complex objects (groups, URLs, dividers), not just string scope names. This follows the pattern of `dashboardLayout` field.
150: 
151: ### Preferences Fields (User Level)
152: | Field | Type | Description |
153: |-------|------|-------------|
154: | `navbarConfigList` | `jsonArray` | User's custom navbar configurations |
155: | `useCustomNavbarConfig` | `bool` | Use user configs instead of system (default: false) |
156: | `activeNavbarConfigId` | `varchar` | ID of currently active configuration |
157: 
158: ---
159: 
160: ## Resolution Logic (Complete)
161: 
162: ```javascript
163: /**
164:  * Resolution Priority Order:
165:  * 1. Navbar config system (new feature) - if any navbarConfigList exists
166:  * 2. Legacy tab customization (existing feature) - useCustomTabList/addCustomTabs
167:  * 3. System default tabList
168:  */
169: 
170: getTabList() {
171:     // NEW: Check navbar config system first
172:     if (this.hasNavbarConfigSystem()) {
173:         const activeConfig = this.getActiveNavbarConfig();
174:         
175:         if (activeConfig && activeConfig.tabList) {
176:             return Espo.Utils.cloneDeep(activeConfig.tabList);
177:         }
178:     }
179:     
180:     // Existing logic remains as fallback
181:     let tabList = this.preferences.get('useCustomTabList') && 
182:                   !this.preferences.get('addCustomTabs') ?
183:         this.preferences.get('tabList') :
184:         this.config.get('tabList');
185: 
186:     if (this.preferences.get('useCustomTabList') && this.preferences.get('addCustomTabs')) {
187:         tabList = [
188:             ...tabList,
189:             ...(this.preferences.get('tabList') || []),
190:         ];
191:     }
192: 
193:     return Espo.Utils.cloneDeep(tabList) || [];
194: }
195: 
196: hasNavbarConfigSystem() {
197:     const configList = this.getNavbarConfigList();
198:     return configList && configList.length > 0;
199: }
200: 
201: getNavbarConfigList() {
202:     if (this.config.get('navbarConfigDisabled')) {
203:         return this.config.get('navbarConfigList') || [];
204:     }
205:     
206:     if (this.preferences.get('useCustomNavbarConfig')) {
207:         return this.preferences.get('navbarConfigList') || [];
208:     }
209:     
210:     return this.config.get('navbarConfigList') || [];
211: }
212: 
213: getActiveNavbarConfig() {
214:     const configList = this.getNavbarConfigList();
215:     
216:     if (!configList || configList.length === 0) {
217:         return null;
218:     }
219:     
220:     const activeId = this.preferences.get('activeNavbarConfigId');
221:     
222:     if (activeId) {
223:         const found = configList.find(c => c.id === activeId);
224:         if (found) return found;
225:         
226:         // ID not found - clear invalid preference
227:         console.warn('Active navbar config ID not found, falling back to default');
228:     }
229:     
230:     return configList.find(c => c.isDefault) || configList[0];
231: }
232: 
233: validateNavbarConfigList(configList) {
234:     if (!configList || configList.length === 0) return true;
235:     
236:     const ids = configList.map(c => c.id).filter(Boolean);
237:     
238:     if (new Set(ids).size !== ids.length) {
239:         throw new Error('Duplicate navbar config IDs detected');
240:     }
241:     
242:     return true;
243: }
244: ```
245: 
246: ---
247: 
248: ## File Manifest
249: 
250: ### Files to CREATE
251: 
252: #### JavaScript Field Views
253: 
254: | File Path | Reason |
255: |-----------|--------|
256: | `client/src/views/settings/fields/navbar-config-list.js` | New field view for managing navbar configs in Settings. **CRITICAL**: Must use modern DOM API (`document.createElement()`) pattern matching `tab-list.js:138-235` instead of legacy string concatenation. |
257: | `client/src/views/preferences/fields/navbar-config-list.js` | User-level navbar configs field view extending Settings version |
258: | `client/src/views/preferences/fields/active-navbar-config.js` | Dropdown field to select active config. |
259: 
260: #### JavaScript Modal Views
261: 
262: | File Path | Reason |
263: |-----------|--------|
264: | `client/src/views/settings/modals/edit-navbar-config.js` | Modal for editing a single navbar config. Uses `new Model()` pattern with `model.name = 'NavbarConfig'` and `model.setDefs()` following `edit-tab-group.js:94-117`. Uses inline `templateContent`. |
265: | `client/src/views/modals/navbar-config-field-add.js` | Add item modal for navbar-config-list field. Uses `new Model()` pattern. Uses inline `templateContent`. |
266: 
267: #### JavaScript Sidebar Selector Component
268: 
269: | File Path | Reason |
270: |-----------|--------|
271: | `client/src/views/site/navbar-config-selector.js` | Dropdown selector component rendered in sidebar |
272: 
273: #### Templates
274: 
275: | File Path | Reason |
276: |-----------|--------|
277: | `client/res/templates/site/navbar-config-selector.tpl` | Template for navbar config selector dropdown. Uses `#ifEqual` (verified at view-helper.js:386) |
278: 
279: #### Styles
280: 
281: | File Path | Reason |
282: |-----------|--------|
283: | `frontend/less/espo/elements/navbar-config-selector.less` | Selector component styles using verified CSS variables from `root-variables.less` |
284: 
285: ---
286: 
287: ### Files to EDIT
288: 
289: #### Entity Definitions (Metadata)
290: 
291: | File Path | Reason | Critical Notes |
292: |-----------|--------|----------------|
293: | `application/Espo/Resources/metadata/entityDefs/Settings.json` | Add `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled` fields | Insert AFTER line 255 (`tabList` closing brace). **CRITICAL**: Ensure proper comma after `tabList` closing brace and after the new `navbarConfigSelectorDisabled` field |
294: | `application/Espo/Resources/metadata/entityDefs/Preferences.json` | Add `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId` fields | Insert AFTER line 181 (`tabList` closing brace). **CRITICAL**: Ensure proper comma handling |
295: 
296: #### Layout Files
297: 
298: | File Path | Reason | Critical Notes |
299: |-----------|--------|----------------|
300: | `application/Espo/Resources/layouts/Settings/userInterface.json` | Add navbar config fields to Navbar tab section | Modify existing rows array (lines 22-30) to include new fields |
301: | `application/Espo/Resources/layouts/Preferences/detail.json` | Add navbar config fields to User Interface section | **CRITICAL**: Append rows to the existing section at lines 111-122, NOT create a new wrapped object with `{ "rows": [...] }`. The structure is an array of objects, not nested. |
302: 
303: #### Helper Logic
304: 
305: | File Path | Reason |
306: |-----------|--------|
307: | `client/src/helpers/site/tabs.js` | Modify `getTabList()` to check navbar config system FIRST, add new methods: `hasNavbarConfigSystem()`, `getNavbarConfigList()`, `getActiveNavbarConfig()`, `validateNavbarConfigList()` |
308: 
309: #### Navbar View
310: 
311: | File Path | Reason | Location Details |
312: |-----------|--------|------------------|
313: | `client/src/views/site/navbar.js` | Multiple edits: (A) Call `setupNavbarConfigSelector()` after line 461, (B) Update preferences listener at lines 471-474 to include `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId`, (C) Add new methods `setupNavbarConfigSelector()` and `switchNavbarConfig()` AFTER `adjustAfterRender()` method | **CRITICAL v10 FINDING**: The existing `this.listenTo(this.getHelper().settings, 'sync', () => update())` at line 463 already handles ALL config changes. NO additional listener needed for `navbarConfigList`. Only add new preference fields to the existing listener at lines 471-474. |
314: 
315: #### Navbar Template
316: 
317: | File Path | Reason | Location Details |
318: |-----------|--------|------------------|
319: | `client/res/templates/site/navbar.tpl` | Add `<div class="navbar-config-selector-container"></div>` inside `.navbar-left-container` | Insert AFTER line 15, BEFORE line 16 (before `<ul class="nav navbar-nav tabs">`) |
320: 
321: #### Styles
322: 
323: | File Path | Reason | Location Details |
324: |-----------|--------|------------------|
325: | `frontend/less/espo/elements/navbar.less` | Add import for navbar-config-selector.less | **CRITICAL**: Add at TOP of file (line 1), before any existing content |
326: 
327: #### Internationalization Files
328: 
329: | File Path | Reason | Critical Notes |
330: |-----------|--------|----------------|
331: | `application/Espo/Resources/i18n/en_US/Settings.json` | Add field labels and tooltips for `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. Add modal header labels: `"Edit Navbar Configuration"`, `"Add Navbar Configuration"` | Add to "fields", "labels", and "tooltips" sections |
332: | `application/Espo/Resources/i18n/en_US/Preferences.json` | Add field labels and tooltips for `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId` | Add to "fields" and "tooltips" sections |
333: | `application/Espo/Resources/i18n/en_US/Global.json` | Add `navbarConfig` translation section with keys: `switchView`, `defaultConfig`, `customConfig`, `noConfigs`, `selectConfig`. Add `errorSavingPreference` to "messages" section | **CRITICAL**: Add `navbarConfig` section AFTER line 994 (after `navbarTabs` closing brace) with proper comma after `navbarTabs`. Verify messages section for `errorSavingPreference` insertion point |
334: 
335: #### Preferences Edit View
336: 
337: | File Path | Reason | Location Details |
338: |-----------|--------|------------------|
339: | `client/src/views/preferences/record/edit.js` | Hide navbar config fields if `navbarConfigDisabled` is true | Add after line 146 (after `userThemesDisabled` check) |
340: 
341: ---
342: 
343: ### Files to DELETE
344: 
345: None.
346: 
347: ---
348: 
349: ### Files to CONSIDER
350: 
351: | File Path | Reason |
352: |-----------|--------|
353: | `application/Espo/Resources/metadata/entityDefs/Portal.json` | Portal has separate tabList system - explicitly **OUT OF SCOPE** for v10 |
354: | `client/src/views/portal/navbar.js` | Portal navbar - explicitly **OUT OF SCOPE** for v10 |
355: | `client/src/views/fields/array.js` | Reference for `addItemModalView` pattern (line 112) - may need review for event handling patterns |
356: 
357: ---
358: 
359: ### Related Files (for reference only, no changes needed)
360: 
361: | File Path | Pattern Reference |
362: |-----------|-------------------|
363: | `client/src/views/settings/modals/edit-tab-group.js` | **CRITICAL REFERENCE** for modal model creation pattern (lines 94-117) - `new Model()` with `model.name` and `model.setDefs()` |
364: | `client/src/views/settings/modals/tab-list-field-add.js` | Add item modal pattern - extends `ArrayFieldAddModalView` |
365: | `client/src/views/settings/fields/tab-list.js` | **PRIMARY PATTERN** for `navbar-config-list.js` - `generateItemId()` at lines 54-55, `getGroupItemHtml()` uses modern DOM API at lines 138-235 |
366: | `client/src/views/preferences/fields/tab-list.js` | Preferences field extending Settings field pattern |
367: | `client/src/helpers/site/tabs.js` | Existing `getTabList()` method to modify (lines 67-80) |
368: | `client/src/views/site/navbar.js` | Existing navbar view with `tabsHelper` instantiation at line 429, **existing `settings.sync` listener at line 463** |
369: | `client/res/templates/site/navbar.tpl` | Navbar template with `navbar-left-container` at line 15 |
370: | `frontend/less/espo/root-variables.less` | CSS variable definitions: `--8px` (line 10), `--12px` (line 14), `--navbar-width` (line 108), `--border-radius` (line 440) |
371: | `frontend/less/espo/bootstrap/variables.less` | Bootstrap variable `@screen-xs-max` at line 37 |
372: | `client/src/view-helper.js` | `#ifEqual` Handlebars helper at line 386 |
373: | `application/Espo/Resources/i18n/en_US/Global.json` (lines 988-994) | Existing `navbarTabs` translation section pattern |
374: | `client/src/model.js` | Model class for `import Model from 'model'` |
375: | `client/src/view.js` | `escapeString` method at lines 126-128 |
376: 
377: ---
378: 
379: ## Error Handling
380: 
381: ### Missing Config Fallback
382: The `getActiveNavbarConfig()` method handles:
383: - Empty config list → returns `null` → triggers legacy fallback
384: - Invalid `activeNavbarConfigId` → logs warning → falls back to default/first
385: - No default set → uses first config
386: 
387: ### AJAX Error Handling
388: The `switchNavbarConfig()` method includes:
389: - Race condition prevention with `this._switchingConfig` flag
390: - Loading indicator with `Espo.Ui.notifyWait()`
391: - try/catch block for network failures
392: - User-facing error message with `Espo.Ui.error()`
393: - Console error logging for debugging
394: - Specific handling for server-side validation errors
395: 
396: ---
397: 
398: ## No Migration Strategy Required
399: 
400: - Existing `tabList` preferences continue to work unchanged
401: - Navbar config system activates only when `navbarConfigList` is populated
402: - No automatic migration of existing `tabList` to navbar config format
403: - Users who want navbar configs must explicitly create them
404: 
405: ---
406: 
407: ## Implementation Order
408: 
409: 1. **Phase 1: Data Model**
410:    - Add entity definitions (Settings.json, Preferences.json)
411:    - Add translations
412: 
413: 2. **Phase 2: Helper Logic**
414:    - Modify TabsHelper with new methods
415:    - Add validation logic
416: 
417: 3. **Phase 3: Admin UI**
418:    - Create field views for navbar config list
419:    - Create modal views for adding/editing configs
420:    - Update layout files
421: 
422: 4. **Phase 4: Navbar UI**
423:    - Create navbar config selector component
424:    - Modify navbar view
425:    - Modify navbar template
426: 
427: 5. **Phase 5: Styling**
428:    - Add CSS for selector component
429:    - Update navbar.less
430:    - Add responsive rules
431: 
432: 6. **Phase 6: Testing (Manual)**
433:    - Test backward compatibility with existing `useCustomTabList`
434:    - Test ID validation
435:    - Test missing config fallback
436:    - Test selector visibility logic
437:    - Test error handling for AJAX failures
438:    - Test keyboard navigation
439:    - Test responsive behavior on mobile
440: 
441: ---
442: 
443: ## Summary of File Count
444: 
445: - **CREATE**: 8 files
446: - **EDIT**: 10 files
447: - **DELETE**: 0 files
448: - **CONSIDER**: 3 files
449: - **Reference**: 13 files
450: 
451: ---
452: 
453: *Scope document v10 generated with all v9 audit corrections applied - SCOPE MAPPED*
454: 

(End of file - total 454 lines)
</content>
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v4.md"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v4.md</path>
<type>file</type>
<content>1: # Multi-Sidenav Sidebar Scoped by Team - v4 File Manifest
2: 
3: > **Version**: 4.0  
4: > **Based on**: v3 scope + v3.audit findings  
5: > **Codebase Root**: `components/crm/source/`  
6: > **Status**: SCOPE MAPPED - READY FOR IMPLEMENTATION
7: 
8: ## Overview
9: 
10: Move navbar configuration from User Interface (Settings/Preferences) to a team-scoped adminForUser panel. Each team can configure its own navbar tabLists, and users see configs from all teams they belong to.
11: 
12: ### Key Changes from v3
13: 
14: | Finding                                                     | Resolution                                                            |
15: | ----------------------------------------------------------- | --------------------------------------------------------------------- |
16: | **Critical**: Missing Team.json edit for bidirectional link | Added Team.json to Files to EDIT with `sidenavConfigs` hasMany link   |
17: | **Warning**: Configurations.json clarification              | Added explicit note that this is a NEW file creation in Global module |
18: | **Warning**: Hook auto-discovery not documented             | Added implementation note about EspoCRM hook discovery convention     |
19: | **Suggestion**: ACL guidance                                | Added ACL expectations documentation in the scope definition          |
20: 
21: ---
22: 
23: ## Decisions
24: 
25: | #   | Decision                                                          | Alternatives Considered              | Rationale                                                                                                                         |
26: | --- | ----------------------------------------------------------------- | ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------- |
27: | 1   | Create new `SidenavConfig` entity linked to Team                  | Add fields directly to Team entity   | Allows multiple configs per team, follows existing adminForUser entity patterns (ChatwootInboxIntegration, MsxGoogleCalendarUser) |
28: | 2   | User's `teams` determines which configs are available             | Use only `defaultTeam`               | Users get access to configs from ALL teams they belong to, providing flexibility for multi-team users                             |
29: | 3   | Keep `activeNavbarConfigId` in Preferences                        | Store in SidenavConfig or new entity | User's active selection is personal preference, follows existing pattern                                                          |
30: | 4   | Keep `navbarConfigShowDefaultTabList` in Settings                 | Add to SidenavConfig or Preferences  | System-level setting that applies globally, controlled by admin                                                                   |
31: | 5   | Remove Settings-level navbar config fields entirely               | Keep as fallback/global override     | Eliminates complexity of 3-level resolution (team → system → default)                                                             |
32: | 6   | Remove `useCustomNavbarConfig` and Preferences `navbarConfigList` | Keep for user-level override         | Team-based configs replace user-level customization                                                                               |
33: | 7   | Fallback to legacy `tabList` if no team configs exist             | Require configs for all users        | Backward compatible with existing installations                                                                                   |
34: | 8   | Admins without team see system `tabList`                          | Create implicit "Global" team        | Edge case - most users belong to at least one team                                                                                |
35: | 9   | Use special ID `__default_tablist__` for default tabList option   | Create pseudo-config record          | Simple, no database changes needed, easy to detect in resolution logic                                                            |
36: | 10  | Load team configs via AppParam `teamSidenavConfigs`               | AJAX call to custom endpoint         | AppParam loads with user session data, more efficient, follows Chatwoot pattern                                                   |
37: | 11  | Add index on `teamId` field                                       | No index                             | AppParam queries by teamId, improves performance for systems with many configs                                                    |
38: | 12  | Add `foreign` link to Team relationship                           | Omit foreign link                    | Enables bidirectional navigation (`Team.sidenavConfigs`), matches Funnel pattern with `foreign: "funnels"`                        |
39: | 13  | Add BeforeSave hook for isDefault validation                      | Manual admin enforcement             | Ensures only one default config per team automatically, matches Funnel/EnsureSingleDefault pattern                                |
40: | 14  | **ACL: table-level with admin-only create**                       | Team-level ACL, open create          | Only admins can create SidenavConfig records via adminForUser panel; read access follows team membership                          |
41: 
42: ---
43: 
44: ## File Manifest
45: 
46: ### Files to CREATE (ordered by complexity/risk, highest first)
47: 
48: #### 1. Backend AppParam Class (CRITICAL)
49: 
50: | File Path                                                             | Purpose                                                                                                                                                                                        |
51: | --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
52: | `custom/Espo/Modules/Global/Classes/AppParams/TeamSidenavConfigs.php` | Implements `Espo\Tools\App\AppParam` interface. Loads all SidenavConfig records for user's teams. Returns array of config objects with id, name, teamId, iconClass, color, tabList, isDefault. |
53: 
54: **Key Implementation Details:**
55: 
56: - Constructor: inject `User` and `EntityManager`
57: - `get()` method: query `SidenavConfig` entity where `teamId` IN user's teamIds
58: - Return structure: flat array of config objects
59: - Handle empty teams case: return `[]`
60: 
61: **Reference Pattern:** `custom/Espo/Modules/Chatwoot/Classes/AppParams/ChatwootSsoUrl.php`
62: 
63: ---
64: 
65: #### 2. Entity Definition (CRITICAL)
66: 
67: | File Path                                                                     | Purpose                                                                                    |
68: | ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
69: | `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json` | New entity for team-scoped navbar configurations. Pattern follows `Funnel.json` structure. |
70: 
71: **Complete Structure:**
72: 
73: ```json
74: {
75:     "fields": {
76:         "name": {
77:             "type": "varchar",
78:             "required": true,
79:             "maxLength": 255,
80:             "trim": true
81:         },
82:         "team": {
83:             "type": "link",
84:             "required": true,
85:             "view": "views/fields/link"
86:         },
87:         "iconClass": {
88:             "type": "varchar",
89:             "maxLength": 100
90:         },
91:         "color": {
92:             "type": "varchar",
93:             "maxLength": 7
94:         },
95:         "tabList": {
96:             "type": "jsonArray",
97:             "view": "views/settings/fields/tab-list"
98:         },
99:         "isDefault": {
100:             "type": "bool",
101:             "default": false,
102:             "tooltip": true
103:         },
104:         "createdAt": {
105:             "type": "datetime",
106:             "readOnly": true
107:         },
108:         "modifiedAt": {
109:             "type": "datetime",
110:             "readOnly": true
111:         },
112:         "createdBy": {
113:             "type": "link",
114:             "readOnly": true,
115:             "view": "views/fields/user"
116:         },
117:         "modifiedBy": {
118:             "type": "link",
119:             "readOnly": true,
120:             "view": "views/fields/user"
121:         },
122:         "teams": {
123:             "type": "linkMultiple",
124:             "view": "views/fields/teams"
125:         }
126:     },
127:     "links": {
128:         "team": {
129:             "type": "belongsTo",
130:             "entity": "Team",
131:             "foreign": "sidenavConfigs"
132:         },
133:         "teams": {
134:             "type": "hasMany",
135:             "entity": "Team",
136:             "relationName": "entityTeam",
137:             "layoutRelationshipsDisabled": true
138:         },
139:         "createdBy": {
140:             "type": "belongsTo",
141:             "entity": "User"
142:         },
143:         "modifiedBy": {
144:             "type": "belongsTo",
145:             "entity": "User"
146:         }
147:     },
148:     "collection": {
149:         "orderBy": "name",
150:         "order": "asc"
151:     },
152:     "indexes": {
153:         "teamId": {
154:             "columns": ["teamId", "deleted"]
155:         }
156:     }
157: }
158: ```
159: 
160: **Reference Pattern:** `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Funnel.json`
161: 
162: ---
163: 
164: #### 3. Navbar View Rewrite (CRITICAL)
165: 
166: | File Path                                               | Purpose                                                                                                                                       |
167: | ------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
168: | `client/custom/modules/global/src/views/site/navbar.js` | Rewrite config resolution logic. Replace `getNavbarConfigList()` to fetch from `teamSidenavConfigs` appParam instead of Settings/Preferences. |
169: 
170: **Key Changes:**
171: 
172: - Add constant: `const DEFAULT_TABLIST_ID = '__default_tablist__';`
173: - Rewrite `getNavbarConfigList()`:
174:     - Get `userTeamIds` from `this.getUser().get('teamsIds')`
175:     - Get `teamConfigs` from `this.getHelper().getAppParam('teamSidenavConfigs')`
176:     - Filter configs by user's team IDs
177:     - Add default tabList option if `navbarConfigShowDefaultTabList` setting is enabled
178: - Rewrite `getActiveNavbarConfig()`: handle `DEFAULT_TABLIST_ID` selection
179: - Rewrite `getTabList()`: handle `isDefaultTabList` flag
180: - Add `getLegacyTabList()`: call `super.getTabList()` and filter
181: - Update `shouldShowConfigSelector()`: remove `navbarConfigSelectorDisabled` check, use team configs
182: - Update `setup()` preference listener: remove `navbarConfigList`, `useCustomNavbarConfig` listeners
183: 
184: ---
185: 
186: #### 4. Active Navbar Config Field Rewrite (HIGH)
187: 
188: | File Path                                                                           | Purpose                                                                                             |
189: | ----------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
190: | `client/custom/modules/global/src/views/preferences/fields/active-navbar-config.js` | Rewrite to fetch options from team configs via appParam instead of Settings/Preferences configList. |
191: 
192: **Key Changes:**
193: 
194: - Rewrite `setupOptions()`:
195:     - Get `userTeamIds` from `this.getUser().get('teamsIds')`
196:     - Get `teamConfigs` from `this.getHelper().getAppParam('teamSidenavConfigs')`
197:     - Filter by user's teams
198:     - Add default tabList option if enabled
199: - Remove listeners for `navbarConfigList`, `useCustomNavbarConfig`
200: - Remove `getResolvedConfigList()` method (no longer needed)
201: - Remove `navbarConfigDisabled` check (no longer applicable)
202: 
203: ---
204: 
205: #### 5. BeforeSave Hook for isDefault Validation (HIGH)
206: 
207: | File Path                                                                | Purpose                                                                                                                                                    |
208: | ------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
209: | `custom/Espo/Modules/Global/Hooks/SidenavConfig/EnsureSingleDefault.php` | Ensures only one SidenavConfig per Team is marked as default. When a config is set as default, all other configs for the same team are set to not default. |
210: 
211: **Structure:** Follows exact pattern from `Hooks/Funnel/EnsureSingleDefault.php`
212: 
213: **Implementation Note:** EspoCRM hooks are auto-discovered by directory convention (`Hooks/EntityName/HookClass.php`). No explicit registration in metadata is required.
214: 
215: ```php
216: <?php
217: namespace Espo\Modules\Global\Hooks\SidenavConfig;
218: 
219: use Espo\Core\Hook\Hook\BeforeSave;
220: use Espo\ORM\Entity;
221: use Espo\ORM\EntityManager;
222: use Espo\ORM\Repository\Option\SaveOptions;
223: 
224: /**
225:  * Ensures only one SidenavConfig per Team is marked as default.
226:  * @implements BeforeSave<Entity>
227:  */
228: class EnsureSingleDefault implements BeforeSave
229: {
230:     public static int $order = 5;
231: 
232:     public function __construct(
233:         private EntityManager $entityManager,
234:     ) {}
235: 
236:     public function beforeSave(Entity $entity, SaveOptions $options): void
237:     {
238:         // Only process if isDefault is being set to true
239:         if (!$entity->get('isDefault')) {
240:             return;
241:         }
242: 
243:         // Only process if this is a new entity or isDefault has changed
244:         if (!$entity->isNew() && !$entity->isAttributeChanged('isDefault')) {
245:             return;
246:         }
247: 
248:         $teamId = $entity->get('teamId');
249: 
250:         if (!$teamId) {
251:             return;
252:         }
253: 
254:         // Find all other configs for the same team that are marked as default
255:         $otherDefaults = $this->entityManager
256:             ->getRDBRepository('SidenavConfig')
257:             ->where([
258:                 'teamId' => $teamId,
259:                 'isDefault' => true,
260:                 'id!=' => $entity->getId(),
261:             ])
262:             ->find();
263: 
264:         // Set them to not default
265:         foreach ($otherDefaults as $otherConfig) {
266:             $otherConfig->set('isDefault', false);
267:             $this->entityManager->saveEntity($otherConfig, ['skipHooks' => true]);
268:         }
269:     }
270: }
271: ```
272: 
273: **Reference Pattern:** `custom/Espo/Modules/Global/Hooks/Funnel/EnsureSingleDefault.php`
274: 
275: ---
276: 
277: #### 6. Entity Scope Definition (MEDIUM)
278: 
279: | File Path                                                                 | Purpose                                                                                              |
280: | ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
281: | `custom/Espo/Modules/Global/Resources/metadata/scopes/SidenavConfig.json` | Entity scope definition. Pattern follows `Funnel.json` but with `"tab": false` and `"acl": "table"`. |
282: 
283: **Structure:**
284: 
285: ```json
286: {
287:     "entity": true,
288:     "layouts": true,
289:     "tab": false,
290:     "acl": "table",
291:     "module": "Global",
292:     "customizable": false,
293:     "importable": false,
294:     "object": true,
295:     "type": "Base",
296:     "hasTeams": true
297: }
298: ```
299: 
300: **ACL Expectations:** With `"acl": "table"`, ACL entries should be created to grant:
301: 
302: - Admin: full CRUD access
303: - Regular users: read access only (configs are managed by admins via adminForUser)
304: 
305: ---
306: 
307: #### 7. ClientDefs Metadata (MEDIUM)
308: 
309: | File Path                                                                     | Purpose                                                                           |
310: | ----------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
311: | `custom/Espo/Modules/Global/Resources/metadata/clientDefs/SidenavConfig.json` | Client-side entity metadata. Required for proper rendering in adminForUser panel. |
312: 
313: **Structure:**
314: 
315: ```json
316: {
317:     "controller": "controllers/record",
318:     "iconClass": "fas fa-bars",
319:     "createDisabled": false,
320:     "defaultSidePanelFieldLists": {
321:         "detail": ["teams"],
322:         "edit": ["teams"],
323:         "detailSmall": ["teams"]
324:     }
325: }
326: ```
327: 
328: **Reference Pattern:** `custom/Espo/Modules/PackEnterprise/Resources/metadata/clientDefs/MsxGoogleCalendarUser.json`
329: 
330: ---
331: 
332: #### 8. AppParams Metadata (MEDIUM)
333: 
334: | File Path                                                          | Purpose                                                           |
335: | ------------------------------------------------------------------ | ----------------------------------------------------------------- |
336: | `custom/Espo/Modules/Global/Resources/metadata/app/appParams.json` | Register the `teamSidenavConfigs` appParam. Currently empty `{}`. |
337: 
338: **Edit: Replace entire content with:**
339: 
340: ```json
341: {
342:     "teamSidenavConfigs": {
343:         "className": "Espo\\Modules\\Global\\Classes\\AppParams\\TeamSidenavConfigs"
344:     }
345: }
346: ```
347: 
348: **Reference Pattern:** `custom/Espo/Modules/Chatwoot/Resources/metadata/app/appParams.json`
349: 
350: ---
351: 
352: #### 9. adminForUserPanel Entry (MEDIUM)
353: 
354: | File Path                                                                  | Purpose                                       |
355: | -------------------------------------------------------------------------- | --------------------------------------------- |
356: | `custom/Espo/Modules/Global/Resources/metadata/app/adminForUserPanel.json` | Add "sidenav" panel entry to existing object. |
357: 
358: **Edit: Add to existing object (preserve existing `users`, `data`, `sales` keys):**
359: 
360: ```json
361: "sidenav": {
362:     "label": "Sidenav",
363:     "itemList": [
364:         {
365:             "url": "#Configurations/SidenavConfig",
366:             "label": "Sidenav Configs",
367:             "iconClass": "fas fa-bars",
368:             "description": "sidenavConfigs"
369:         }
370:     ],
371:     "order": 10
372: }
373: ```
374: 
375: ---
376: 
377: #### 10. Entity Layouts (LOW)
378: 
379: | File Path                                                                     | Purpose                             |
380: | ----------------------------------------------------------------------------- | ----------------------------------- |
381: | `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detail.json`      | Detail view layout                  |
382: | `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/list.json`        | List view layout                    |
383: | `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detailSmall.json` | Small detail view for quick editing |
384: 
385: **detail.json structure:**
386: 
387: ```json
388: [
389:     {
390:         "label": "Overview",
391:         "rows": [
392:             [{ "name": "name" }, { "name": "team" }],
393:             [{ "name": "iconClass" }, { "name": "color" }],
394:             [{ "name": "isDefault" }, false],
395:             [{ "name": "tabList", "fullWidth": true }]
396:         ]
397:     }
398: ]
399: ```
400: 
401: **list.json structure:**
402: 
403: ```json
404: [
405:     { "name": "name", "width": 30 },
406:     { "name": "team", "width": 40 },
407:     { "name": "isDefault", "width": 15 }
408: ]
409: ```
410: 
411: **detailSmall.json structure:**
412: 
413: ```json
414: [
415:     {
416:         "label": "Overview",
417:         "rows": [
418:             [{ "name": "name" }],
419:             [{ "name": "team" }],
420:             [{ "name": "isDefault" }]
421:         ]
422:     }
423: ]
424: ```
425: 
426: **Reference Pattern:** `custom/Espo/Modules/Global/Resources/layouts/Funnel/detailSmall.json`
427: 
428: ---
429: 
430: #### 11. Entity Translations (LOW)
431: 
432: | File Path                                                            | Purpose                                                  |
433: | -------------------------------------------------------------------- | -------------------------------------------------------- |
434: | `custom/Espo/Modules/Global/Resources/i18n/en_US/SidenavConfig.json` | Entity translations: scopeNames, field labels, tooltips. |
435: 
436: **Structure:**
437: 
438: ```json
439: {
440:     "scopeNames": {
441:         "SidenavConfig": "Sidenav Configuration"
442:     },
443:     "scopeNamesPlural": {
444:         "SidenavConfig": "Sidenav Configurations"
445:     },
446:     "fields": {
447:         "name": "Name",
448:         "team": "Team",
449:         "iconClass": "Icon",
450:         "color": "Color",
451:         "tabList": "Tab List",
452:         "isDefault": "Default"
453:     },
454:     "tooltips": {
455:         "isDefault": "If checked, this configuration will be the default for users in this team who haven't selected a specific config."
456:     }
457: }
458: ```
459: 
460: ---
461: 
462: #### 12. Configurations Translations (LOW) - **NEW FILE**
463: 
464: | File Path                                                             | Purpose                                                                                                                                                                       |
465: | --------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
466: | `custom/Espo/Modules/Global/Resources/i18n/en_US/Configurations.json` | **NEW FILE** - Panel labels for adminForUser. Used by `admin-for-user/index.js`. This is a new file in the Global module, NOT an extension of Chatwoot's Configurations.json. |
467: 
468: **Structure:**
469: 
470: ```json
471: {
472:     "labels": {
473:         "Sidenav": "Navigation",
474:         "Sidenav Configs": "Sidenav Configurations"
475:     },
476:     "descriptions": {
477:         "sidenavConfigs": "Configure team-specific navigation sidebars"
478:     },
479:     "keywords": {
480:         "sidenavConfigs": "navigation,sidebar,menu,tabs"
481:     }
482: }
483: ```
484: 
485: **Reference Pattern:** `custom/Espo/Modules/Chatwoot/Resources/i18n/en_US/Configurations.json`
486: 
487: ---
488: 
489: ### Files to EDIT
490: 
491: #### 1. Team Entity Definition (CRITICAL - v4 Addition)
492: 
493: | File Path                                                            | Changes                                                                                     |
494: | -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
495: | `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Team.json` | ADD `sidenavConfigs` link to complete bidirectional relationship with SidenavConfig entity. |
496: 
497: **Current content:**
498: 
499: ```json
500: {
501:     "links": {
502:         "funnels": {
503:             "type": "hasMany",
504:             "entity": "Funnel",
505:             "foreign": "team"
506:         }
507:     }
508: }
509: ```
510: 
511: **Add after `funnels` link:**
512: 
513: ```json
514: {
515:     "links": {
516:         "funnels": {
517:             "type": "hasMany",
518:             "entity": "Funnel",
519:             "foreign": "team"
520:         },
521:         "sidenavConfigs": {
522:             "type": "hasMany",
523:             "entity": "SidenavConfig",
524:             "foreign": "team"
525:         }
526:     }
527: }
528: ```
529: 
530: **Rationale:** This completes the bidirectional link relationship referenced in Decision #12. Without this, `Team.sidenavConfigs` navigation would not work.
531: 
532: ---
533: 
534: #### 2. Settings Entity Definition (HIGH)
535: 
536: | File Path                                                                | Changes                                                                                                                                 |
537: | ------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------- |
538: | `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Settings.json` | DELETE fields: `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. ADD field: `navbarConfigShowDefaultTabList`. |
539: 
540: **Replace entire content with:**
541: 
542: ```json
543: {
544:     "fields": {
545:         "navbarConfigShowDefaultTabList": {
546:             "type": "bool",
547:             "default": false,
548:             "tooltip": true
549:         }
550:     }
551: }
552: ```
553: 
554: ---
555: 
556: #### 3. Preferences Entity Definition (HIGH)
557: 
558: | File Path                                                                   | Changes                                                                                  |
559: | --------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
560: | `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Preferences.json` | DELETE fields: `navbarConfigList`, `useCustomNavbarConfig`. KEEP `activeNavbarConfigId`. |
561: 
562: **Replace entire content with:**
563: 
564: ```json
565: {
566:     "fields": {
567:         "activeNavbarConfigId": {
568:             "type": "varchar",
569:             "maxLength": 36,
570:             "view": "global:views/preferences/fields/active-navbar-config"
571:         }
572:     }
573: }
574: ```
575: 
576: ---
577: 
578: #### 4. Settings Layout (MEDIUM)
579: 
580: | File Path                                                                  | Changes                                                                                                                                                                            |
581: | -------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
582: | `custom/Espo/Modules/Global/Resources/layouts/Settings/userInterface.json` | In the Navbar tab section, DELETE the rows containing `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. ADD a row with `navbarConfigShowDefaultTabList`. |
583: 
584: **Current Navbar section (rows 31-36):**
585: 
586: ```json
587: {
588:     "rows": [
589:         [{ "name": "navbarConfigList", "fullWidth": true }],
590:         [
591:             { "name": "navbarConfigDisabled" },
592:             { "name": "navbarConfigSelectorDisabled" }
593:         ]
594:     ]
595: }
596: ```
597: 
598: **Replace with:**
599: 
600: ```json
601: {
602:     "rows": [[{ "name": "navbarConfigShowDefaultTabList" }, false]]
603: }
604: ```
605: 
606: ---
607: 
608: #### 5. Preferences Layout (MEDIUM)
609: 
610: | File Path                                                              | Changes                                                                                                                                        |
611: | ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
612: | `custom/Espo/Modules/Global/Resources/layouts/Preferences/detail.json` | In the User Interface tab section, DELETE the rows containing `useCustomNavbarConfig` and `navbarConfigList`. KEEP `activeNavbarConfigId` row. |
613: 
614: **Current User Interface section (rows 123-137):**
615: 
616: ```json
617: {
618:     "rows": [
619:         [{ "name": "useCustomNavbarConfig" }, false],
620:         [{ "name": "navbarConfigList", "fullWidth": true }],
621:         [{ "name": "activeNavbarConfigId" }, false]
622:     ]
623: }
624: ```
625: 
626: **Replace with:**
627: 
628: ```json
629: {
630:     "rows": [[{ "name": "activeNavbarConfigId" }, false]]
631: }
632: ```
633: 
634: ---
635: 
636: #### 6. Settings Translations (LOW)
637: 
638: | File Path                                                       | Changes                                                                                                                                                                                                                                                    |
639: | --------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
640: | `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json` | DELETE translations for `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. DELETE labels: `Edit Navbar Configuration`, `Add Navbar Configuration`, `Navbar Configuration`. ADD translations for `navbarConfigShowDefaultTabList`. |
641: 
642: **Replace entire content with:**
643: 
644: ```json
645: {
646:     "fields": {
647:         "navbarConfigShowDefaultTabList": "Show Default Tab List Option"
648:     },
649:     "tooltips": {
650:         "navbarConfigShowDefaultTabList": "If checked, users will see a 'Default' option in the navbar config selector that uses the system-level tabList. This allows users to switch back to the default navigation from team-specific configs."
651:     }
652: }
653: ```
654: 
655: ---
656: 
657: #### 7. Preferences Translations (LOW)
658: 
659: | File Path                                                          | Changes                                                                                                            |
660: | ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------ |
661: | `custom/Espo/Modules/Global/Resources/i18n/en_US/Preferences.json` | DELETE translations for `navbarConfigList`, `useCustomNavbarConfig`. KEEP `activeNavbarConfigId` - update tooltip. |
662: 
663: **Replace entire content with:**
664: 
665: ```json
666: {
667:     "fields": {
668:         "activeNavbarConfigId": "Active Navbar Configuration"
669:     },
670:     "tooltips": {
671:         "activeNavbarConfigId": "Select your active navbar configuration from the options provided by your teams. Use 'Default' to use the system-level tab list."
672:     }
673: }
674: ```
675: 
676: ---
677: 
678: #### 8. Global Translations (LOW)
679: 
680: | File Path                                                     | Changes                                                                                                                   |
681: | ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
682: | `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json` | ADD `scopeNames` and `scopeNamesPlural` for SidenavConfig (as fallback). Update `navbarConfig.defaultConfig` translation. |
683: 
684: **Add to `scopeNames` object:**
685: 
686: ```json
687: "SidenavConfig": "Sidenav Configuration"
688: ```
689: 
690: **Add to `scopeNamesPlural` object:**
691: 
692: ```json
693: "SidenavConfig": "Sidenav Configurations"
694: ```
695: 
696: ---
697: 
698: ### Files to DELETE
699: 
700: | File Path                                                                         | Reason                                                                                                                            |
701: | --------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
702: | `client/custom/modules/global/src/views/settings/fields/navbar-config-list.js`    | No longer needed - configs are entity records, not Settings field. **Note: File may not exist yet - verify before deleting.**     |
703: | `client/custom/modules/global/src/views/preferences/fields/navbar-config-list.js` | No longer needed - users don't create custom configs. **Note: File may not exist yet - verify before deleting.**                  |
704: | `client/custom/modules/global/src/views/settings/modals/edit-navbar-config.js`    | No longer needed - editing happens via SidenavConfig record edit view. **Note: File may not exist yet - verify before deleting.** |
705: 
706: ---
707: 
708: ### Files to CONSIDER
709: 
710: | File Path                                                           | Reason                                                                                                                    |
711: | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
712: | `client/custom/modules/global/src/views/preferences/record/edit.js` | May need to hide `activeNavbarConfigId` field if user has no teams or no configs available. Consider dynamic visibility.  |
713: | Migration script or InstallActions                                  | Optional: Copy existing `Settings.navbarConfigList` to a default team's SidenavConfig records for backward compatibility. |
714: 
715: ---
716: 
717: ### Related Files (for reference only, no changes needed)
718: 
719: | File Path                                                               | Pattern Reference                                                                  |
720: | ----------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
721: | `client/custom/modules/global/src/views/site/navbar-config-selector.js` | Selector component - should work with updated resolution logic                     |
722: | `client/custom/modules/global/css/navbar-config-selector.css`           | Selector styles - no changes needed                                                |
723: | `client/custom/modules/global/src/controllers/admin-for-user.js`        | Controller for adminForUser routing                                                |
724: | `client/custom/modules/global/src/views/admin-for-user/index.js`        | Panel index view with ACL filtering - uses `Configurations` scope for translations |
725: | `application/Espo/Tools/App/AppParam.php`                               | Interface definition for AppParam implementations                                  |
726: | `application/Espo/Tools/App/AppService.php`                             | Loads appParams from metadata                                                      |
727: | `application/Espo/Resources/metadata/entityDefs/Team.json`              | Team entity structure - contains `funnels` link pattern                            |
728: | `application/Espo/Resources/metadata/entityDefs/User.json`              | User entity with teams linkMultiple                                                |
729: | `custom/Espo/Modules/Global/Hooks/Funnel/EnsureSingleDefault.php`       | Exact pattern for isDefault validation hook                                        |
730: 
731: ---
732: 
733: ## Error Handling
734: 
735: ### Missing Teams Fallback
736: 
737: - User has no `teamsIds` or empty array → return empty config list → triggers legacy `tabList` fallback
738: 
739: ### Invalid Active Config ID
740: 
741: - `activeNavbarConfigId` references deleted config → log warning → fall back to `isDefault` or first config
742: - `activeNavbarConfigId` is `DEFAULT_TABLIST_ID` but setting is disabled → fall back to first team config
743: 
744: ### Default TabList Option
745: 
746: - `navbarConfigShowDefaultTabList` is disabled → default option not added to selector
747: - User selects default tabList → uses system `tabList` from Settings
748: - Switching from default tabList to team config → normal config resolution applies
749: 
750: ### AJAX Error Handling
751: 
752: - Maintain existing error handling in `switchNavbarConfig()` method
753: - AppParam errors handled by Espo core (returns null/empty)
754: 
755: ---
756: 
757: ## Implementation Order
758: 
759: ### Phase 1: Backend Foundation
760: 
761: 1. Create `TeamSidenavConfigs.php` AppParam class
762: 2. Update `appParams.json` metadata
763: 3. Create `SidenavConfig.json` entity definition
764: 4. **Edit `Team.json` to add `sidenavConfigs` link**
765: 5. Create `SidenavConfig` scope definition
766: 6. Create `SidenavConfig` clientDefs metadata
767: 7. Create `EnsureSingleDefault.php` BeforeSave hook
768: 
769: ### Phase 2: UI Components
770: 
771: 1. Create SidenavConfig layouts (detail, list, detailSmall)
772: 2. Create SidenavConfig translations
773: 3. Create Configurations translations (NEW file)
774: 4. Add adminForUser panel entry
775: 
776: ### Phase 3: Frontend Logic
777: 
778: 1. Update navbar.js resolution logic
779: 2. Update active-navbar-config.js field
780: 3. Update Settings/Preferences layouts
781: 4. Update Settings/Preferences entityDefs
782: 5. Update Settings/Preferences/Global translations
783: 
784: ### Phase 4: Cleanup
785: 
786: 1. Delete deprecated views/modals (verify existence first)
787: 
788: ### Phase 5: Testing
789: 
790: 1. Test team config creation via adminForUser
791: 2. Test resolution with user having single team
792: 3. Test resolution with user having multiple teams
793: 4. Test resolution with user having no teams
794: 5. Test active config selection
795: 6. Test fallback to legacy tabList
796: 7. Test selector visibility (hidden when ≤1 config)
797: 8. Test ACL filtering in adminForUser panel
798: 9. Test `navbarConfigShowDefaultTabList` setting
799: 10. Test selecting default tabList option
800: 11. Test `isDefault` validation (only one default per team)
801: 12. **Test `Team.sidenavConfigs` bidirectional navigation**
802: 
803: ---
804: 
805: ## Summary of File Count
806: 
807: | Category  | Count    |
808: | --------- | -------- |
809: | CREATE    | 12 files |
810: | EDIT      | 8 files  |
811: | DELETE    | 3 files  |
812: | CONSIDER  | 2 items  |
813: | Reference | 10 files |
814: 
815: ---
816: 
817: ## v4 Audit Resolution Summary
818: 
819: | Finding Type   | Description                                   | Resolution                                                    |
820: | -------------- | --------------------------------------------- | ------------------------------------------------------------- |
821: | **Critical**   | Missing Team.json edit for bidirectional link | Added to Files to EDIT section                                |
822: | **Warning**    | Configurations.json file clarification        | Added note that this is a NEW file in Global module           |
823: | **Warning**    | Hook auto-discovery not documented            | Added implementation note in BeforeSave hook section          |
824: | **Suggestion** | ACL guidance                                  | Added ACL expectations to scope definition and scope metadata |
825: 

(End of file - total 825 lines)
</content>
```

**Tool: grep**

**Input:**
```json
{
  "include": "*.php",
  "pattern": "SidenavConfig|sidenavConfig"
}
```

**Output:**
```
Found 8 matches
/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Classes/AppParams/TeamSidenavConfigs.php:
  Line 31:  * AppParam that provides SidenavConfig records for the current user's teams.
  Line 33:  * Returned as part of the /api/v1/App/user response under `teamSidenavConfigs`.
  Line 36: class TeamSidenavConfigs implements AppParam
  Line 55:             ->getRDBRepository('SidenavConfig')

/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Controllers/SidenavConfig.php:
  Line 16: class SidenavConfig extends Base

/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Hooks/SidenavConfig/EnsureSingleDefault.php:
  Line 12: namespace Espo\Modules\Global\Hooks\SidenavConfig;
  Line 21:  * Ensures only one SidenavConfig per overlapping Team set is marked as default.
  Line 56:             ->getRDBRepository('SidenavConfig')
```

---

## Assistant (Scope · glm-5-free · 13.8s)

_Thinking:_

Now I have a good understanding of the existing sidenav implementation. Let me explore more about:
1. How addGroup, addDivider, addUrl work (they seem to be tab list item types)
2. The saved filter implementation
3. Quick create functionality
4. Entity icons

Let me look at the existing tab-list.js implementation and understand how divider/group/url work.

Let me explore the tab list implementation to understand how dividers, groups, and URLs work, and then look at saved filters and quick create patterns.

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/fields/tab-list.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/fields/tab-list.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of EspoCRM.
3:  *
4:  * EspoCRM – Open Source CRM application.
5:  * Copyright (C) 2014-2026 EspoCRM, Inc.
6:  * Website: https://www.espocrm.com
7:  *
8:  * This program is free software: you can redistribute it and/or modify
9:  * it under the terms of the GNU Affero General Public License as published by
10:  * the Free Software Foundation, either version 3 of the License, or
11:  * (at your option) any later version.
12:  *
13:  * This program is distributed in the hope that it will be useful,
14:  * but WITHOUT ANY WARRANTY; without even the implied warranty of
15:  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
16:  * GNU Affero General Public License for more details.
17:  *
18:  * You should have received a copy of the GNU Affero General Public License
19:  * along with this program. If not, see <https://www.gnu.org/licenses/>.
20:  *
21:  * The interactive user interfaces in modified source and object code versions
22:  * of this program must display Appropriate Legal Notices, as required under
23:  * Section 5 of the GNU Affero General Public License version 3.
24:  *
25:  * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
26:  * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
27:  ************************************************************************/
28: 
29: import ArrayFieldView from 'views/fields/array';
30: 
31: class TabListFieldView extends ArrayFieldView {
32: 
33:     addItemModalView = 'views/settings/modals/tab-list-field-add'
34: 
35:     noGroups = false
36:     noDelimiters = false
37: 
38:     setup() {
39:         super.setup();
40: 
41:         this.selected.forEach(item => {
42:             if (item && typeof item === 'object') {
43:                 if (!item.id) {
44:                     item.id = this.generateItemId();
45:                 }
46:             }
47:         });
48: 
49:         this.addActionHandler('editGroup', (e, target) => {
50:             this.editGroup(target.dataset.value);
51:         })
52:     }
53: 
54:     generateItemId() {
55:         return Math.floor(Math.random() * 1000000 + 1).toString();
56:     }
57: 
58:     setupOptions() {
59:         this.params.options = Object.keys(this.getMetadata().get('scopes'))
60:             .filter(scope => {
61:                 if (this.getMetadata().get(`scopes.${scope}.disabled`)) {
62:                     return false;
63:                 }
64: 
65:                 if (!this.getAcl().checkScope(scope)) {
66:                     return false;
67:                 }
68: 
69:                 return this.getMetadata().get(`scopes.${scope}.tab`);
70:             })
71:             .sort((v1, v2) => {
72:                 return this.translate(v1, 'scopeNamesPlural')
73:                     .localeCompare(this.translate(v2, 'scopeNamesPlural'));
74:             });
75: 
76:         if (!this.noDelimiters) {
77:             this.params.options.push('_delimiter_');
78:             this.params.options.push('_delimiter-ext_');
79:         }
80: 
81:         this.translatedOptions = {};
82: 
83:         this.params.options.forEach(item => {
84:             this.translatedOptions[item] = this.translate(item, 'scopeNamesPlural');
85:         });
86: 
87:         this.translatedOptions['_delimiter_'] = '. . .';
88:         this.translatedOptions['_delimiter-ext_'] = '. . .';
89:     }
90: 
91:     addValue(value) {
92:         if (value && typeof value === 'object') {
93:             if (!value.id) {
94:                 value.id = this.generateItemId();
95:             }
96: 
97:             const html = this.getItemHtml(value);
98: 
99:             this.$list.append(html);
100:             this.selected.push(value);
101: 
102:             this.trigger('change');
103: 
104:             return;
105:         }
106: 
107:         super.addValue(value);
108:     }
109: 
110:     removeValue(value) {
111:         const index = this.getGroupIndexById(value);
112: 
113:         if (~index) {
114:             this.$list.children(`[data-value="${value}"]`).remove();
115: 
116:             this.selected.splice(index, 1);
117:             this.trigger('change');
118: 
119:             return;
120:         }
121: 
122:         super.removeValue(value);
123:     }
124: 
125:     getItemHtml(value) {
126:         if (value && typeof value === 'object') {
127:             return this.getGroupItemHtml(value);
128:         }
129: 
130:         return super.getItemHtml(value);
131:     }
132: 
133:     /**
134:      *
135:      * @param {{id: string, text?: string|null, type: string}} item
136:      * @return {string | jQuery}
137:      */
138:     getGroupItemHtml(item) {
139:         const labelElement = document.createElement('span');
140:         labelElement.textContent = item.text ?? '';
141: 
142:         let icon;
143: 
144:         if (item.type === 'group') {
145:             icon = document.createElement('span');
146:             icon.className = 'far fa-list-alt text-muted';
147:         }
148: 
149:         if (item.type === 'url') {
150:             icon = document.createElement('span');
151:             icon.className = 'fas fa-link fa-sm text-muted';
152:         }
153: 
154:         if (item.type === 'divider') {
155:             labelElement.classList.add('text-soft', 'text-italic');
156:         }
157: 
158:         const itemElement = document.createElement('span');
159:         itemElement.append(labelElement);
160:         itemElement.className = 'text';
161: 
162:         if (icon) {
163:             icon.style.marginRight = 'var(--4px)';
164: 
165:             itemElement.prepend(icon);
166:         }
167: 
168:         const div = document.createElement('div');
169:         div.className = 'list-group-item';
170:         div.dataset.value = item.id;
171:         div.style.cursor = 'default';
172: 
173:         div.append(
174:             (() => {
175:                 const span = document.createElement('span');
176:                 span.className = 'drag-handle';
177:                 span.append(
178:                     (() => {
179:                         const span = document.createElement('span');
180:                         span.className = 'fas fa-grip fa-sm';
181: 
182:                         return span;
183:                     })(),
184:                 );
185: 
186:                 return span;
187:             })(),
188:             (() => {
189:                 const span = document.createElement('span');
190:                 span.className = 'item-button'
191:                 span.append(
192:                     (() => {
193:                         const a = document.createElement('a');
194:                         a.role = 'button';
195:                         a.tabIndex = 0;
196:                         a.dataset.value = item.id;
197:                         a.dataset.action = 'editGroup';
198:                         a.append(
199:                             (() => {
200:                                 const span = document.createElement('span');
201:                                 span.className = 'fas fa-pencil-alt fa-sm';
202: 
203:                                 return span;
204:                             })(),
205:                         );
206: 
207:                         return a;
208:                     })(),
209:                 )
210: 
211:                 return span;
212:             })(),
213:             itemElement,
214:             (() => {
215:                 const a = document.createElement('a');
216:                 a.role = 'button';
217:                 a.tabIndex = 0;
218:                 a.classList.add('pull-right');
219:                 a.dataset.value = item.id;
220:                 a.dataset.action = 'removeValue';
221:                 a.append(
222:                     (() => {
223:                         const span = document.createElement('span');
224:                         span.className = 'fas fa-times'
225: 
226:                         return span;
227:                     })(),
228:                 );
229: 
230:                 return a;
231:             })(),
232:         );
233: 
234:         return div.outerHTML;
235: 
236: 
237:         return $('<div>')
238:             .addClass('list-group-item')
239:             .attr('data-value', item.id)
240:             .css('cursor', 'default')
241:             .append(
242:                 $('<a>')
243:                     .attr('role', 'button')
244:                     .attr('tabindex', '0')
245:                     .attr('data-value', item.id)
246:                     .attr('data-action', 'editGroup')
247:                     .css('margin-right', '7px')
248:                     .append(
249:                         $('<span>').addClass('fas fa-pencil-alt fa-sm')
250:                     ),
251:                 $item,
252:                 '&nbsp;',
253:                 $('<a>')
254:                     .addClass('pull-right')
255:                     .attr('role', 'button')
256:                     .attr('tabindex', '0')
257:                     .attr('data-value', item.id)
258:                     .attr('data-action', 'removeValue')
259:                     .append(
260:                         $('<span>').addClass('fas fa-times')
261:                     )
262:             )
263:             .get(0).outerHTML;
264:     }
265: 
266:     fetchFromDom() {
267:         const selected = [];
268: 
269:         this.$el.find('.list-group .list-group-item').each((i, el) => {
270:             const value = $(el).data('value').toString();
271:             const groupItem = this.getGroupValueById(value);
272: 
273:             if (groupItem) {
274:                 selected.push(groupItem);
275: 
276:                 return;
277:             }
278: 
279:             selected.push(value);
280:         });
281: 
282:         this.selected = selected;
283:     }
284: 
285:     getGroupIndexById(id) {
286:         for (let i = 0; i < this.selected.length; i++) {
287:             const item = this.selected[i];
288: 
289:             if (item && typeof item === 'object') {
290:                 if (item.id === id) {
291:                     return i;
292:                 }
293:             }
294:         }
295: 
296:         return -1;
297:     }
298: 
299:     getGroupValueById(id) {
300:         for (const item of this.selected) {
301:             if (item && typeof item === 'object') {
302:                 if (item.id === id) {
303:                     return item;
304:                 }
305:             }
306:         }
307: 
308:         return null;
309:     }
310: 
311:     editGroup(id) {
312:         const item = Espo.Utils.cloneDeep(this.getGroupValueById(id) || {});
313: 
314:         const index = this.getGroupIndexById(id);
315:         const tabList = Espo.Utils.cloneDeep(this.selected);
316: 
317:         const view = {
318:             divider: 'views/settings/modals/edit-tab-divider',
319:             url: 'views/settings/modals/edit-tab-url'
320:         }[item.type] ||  'views/settings/modals/edit-tab-group';
321: 
322:         this.createView('dialog', view, {
323:             itemData: item,
324:             parentType: this.model.entityType,
325:         }, view => {
326:             view.render();
327: 
328:             this.listenToOnce(view, 'apply', itemData => {
329:                 for (const a in itemData) {
330:                     tabList[index][a] = itemData[a];
331:                 }
332: 
333:                 this.model.set(this.name, tabList);
334: 
335:                 view.close();
336:             });
337:         });
338:     }
339: 
340:     getAddItemModalOptions() {
341:         return {
342:             ...super.getAddItemModalOptions(),
343:             noGroups: this.noGroups,
344:         };
345:     }
346: 
347:     getValueForDisplay() {
348:         const labels = this.translatedOptions || {};
349: 
350:         /** @var {string[]} */
351:         const list = this.selected.map(item => {
352:             if (typeof item !== 'string') {
353:                 return ' - ' + (item.text || '?');
354:             }
355: 
356:             return labels[item] || item;
357:         });
358: 
359:         return list.map(text => {
360:             return $('<div>')
361:                 .addClass('multi-enum-item-container')
362:                 .text(text)
363:                 .get(0)
364:                 .outerHTML
365:         }).join('');
366:     }
367: }
368: 
369: export default TabListFieldView;
370: 

(End of file - total 370 lines)
</content>
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/helpers/site/tabs.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/src/helpers/site/tabs.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of EspoCRM.
3:  *
4:  * EspoCRM – Open Source CRM application.
5:  * Copyright (C) 2014-2026 EspoCRM, Inc.
6:  * Website: https://www.espocrm.com
7:  *
8:  * This program is free software: you can redistribute it and/or modify
9:  * it under the terms of the GNU Affero General Public License as published by
10:  * the Free Software Foundation, either version 3 of the License, or
11:  * (at your option) any later version.
12:  *
13:  * This program is distributed in the hope that it will be useful,
14:  * but WITHOUT ANY WARRANTY; without even the implied warranty of
15:  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
16:  * GNU Affero General Public License for more details.
17:  *
18:  * You should have received a copy of the GNU Affero General Public License
19:  * along with this program. If not, see <https://www.gnu.org/licenses/>.
20:  *
21:  * The interactive user interfaces in modified source and object code versions
22:  * of this program must display Appropriate Legal Notices, as required under
23:  * Section 5 of the GNU Affero General Public License version 3.
24:  *
25:  * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
26:  * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
27:  ************************************************************************/
28: 
29: export default class TabsHelper {
30: 
31:     /**
32:      * @param {import('models/settings').default} config
33:      * @param {import('models/preferences').default} preferences
34:      * @param {import('models/user').default} user
35:      * @param {import('acl-manager').default} acl
36:      * @param {import('metadata').default} metadata
37:      * @param {import('language').default} language
38:      */
39:     constructor(config, preferences, user, acl, metadata, language) {
40:         /** @private */
41:         this.config = config;
42:         /** @private */
43:         this.preferences = preferences;
44:         /** @private */
45:         this.user = user;
46:         /** @private */
47:         this.acl = acl;
48:         /** @private */
49:         this.metadata = metadata;
50:         /** @private */
51:         this.language = language;
52:     }
53: 
54:     /**
55:      * @typedef {Object} TabsHelper~item
56:      * @property {string} [url]
57:      * @property {string} [text]
58:      * @property {'url'|'divider'} [type]
59:      * @property {(TabsHelper~item|string)[]} [itemList]
60:      */
61: 
62:     /**
63:      * Get the tab list.
64:      *
65:      * @return {(TabsHelper~item|string)[]}
66:      */
67:     getTabList() {
68:         let tabList = this.preferences.get('useCustomTabList') && !this.preferences.get('addCustomTabs') ?
69:             this.preferences.get('tabList') :
70:             this.config.get('tabList');
71: 
72:         if (this.preferences.get('useCustomTabList') && this.preferences.get('addCustomTabs')) {
73:             tabList = [
74:                 ...tabList,
75:                 ...(this.preferences.get('tabList') || []),
76:             ];
77:         }
78: 
79:         return Espo.Utils.cloneDeep(tabList) || [];
80:     }
81: 
82:     /**
83:      * Is a tab a divider.
84:      *
85:      * @param {string|{type?: string}} item
86:      */
87:     isTabDivider(item) {
88:         return typeof item === 'object' && item.type === 'divider';
89:     }
90: 
91:     /**
92:      * Is a tab more-delimiter.
93:      *
94:      * @param {string|{type?: string}} item
95:      */
96:     isTabMoreDelimiter(item) {
97:         return item === '_delimiter_' || item === '_delimiter-ext_';
98:     }
99: 
100:     /**
101:      * Is a tab a URL.
102:      *
103:      * @param {string|{type?: string}} item
104:      */
105:     isTabUrl(item) {
106:         return typeof item === 'object' && item.type === 'url';
107:     }
108: 
109:     /**
110:      * Is a tab a group.
111:      *
112:      * @param {string|{type?: string}} item
113:      */
114:     isTabGroup(item) {
115:         if (!this.isTabDivider(item) && !this.isTabUrl(item) && typeof item === 'object') {
116:             return true;
117:         }
118: 
119:         return false;
120:     }
121: 
122:     /**
123:      * Is a tab a scope.
124:      *
125:      * @param {string|{type?: string}} item
126:      */
127:     isTabScope(item) {
128:         if (typeof item === 'object' || this.isTabMoreDelimiter(item) || item === 'Home') {
129:             return false;
130:         }
131: 
132:         return true;
133:     }
134: 
135:     /**
136:      * Get a translated tab label.
137:      *
138:      * @param {{text?: string}|string} item
139:      */
140:     getTranslatedTabLabel(item) {
141:         const translateLabel = label => {
142:             if (label.indexOf('$') === 0) {
143:                 return this.language.translate(label.slice(1), 'navbarTabs');
144:             }
145: 
146:             return label;
147:         };
148: 
149:         if (this.isTabDivider(item) || this.isTabUrl(item) || this.isTabUrl(item) || this.isTabGroup(item)) {
150:             if (item.text) {
151:                 return translateLabel(item.text);
152:             }
153: 
154:             return ''
155:         }
156: 
157:         if (item === 'Home') {
158:             return this.language.translate('Home');
159:         }
160: 
161:         if (typeof item === 'object') {
162:             return '';
163:         }
164: 
165:         return this.language.translate(item, 'scopeNamesPlural');
166:     }
167: 
168:     /**
169:      * Check tab access.
170:      *
171:      * @param {Record|string} item
172:      * @return {boolean}
173:      */
174:     checkTabAccess(item) {
175:         if (this.isTabUrl(item)) {
176:             if (item.onlyAdmin && !this.user.isAdmin()) {
177:                 return false;
178:             }
179: 
180:             if (!item.aclScope) {
181:                 return true;
182:             }
183: 
184:             return this.acl.check(item.aclScope);
185:         }
186: 
187:         if (item === 'Home' || this.isTabMoreDelimiter(item)) {
188:             return true;
189:         }
190: 
191:         /** @type {Record<string, {disabled?: boolean, acl?: boolean, tabAclPermission?: string}>} */
192:         const scopes = this.metadata.get('scopes') || {};
193: 
194:         if (!scopes[item]) {
195:             return false;
196:         }
197: 
198:         const defs = scopes[item] || {};
199: 
200:         if (defs.disabled) {
201:             return false;
202:         }
203: 
204:         if (defs.acl) {
205:             return this.acl.check(item);
206:         }
207: 
208:         if (defs.tabAclPermission) {
209:             const level = this.acl.getPermissionLevel(defs.tabAclPermission);
210: 
211:             return level && level !== 'no';
212:         }
213: 
214:         return true;
215:     }
216: }
217: 

(End of file - total 217 lines)
</content>
```

**Tool: grep**

**Input:**
```json
{
  "include": "*.js",
  "pattern": "quick.?create|quickCreate"
}
```

**Output:**
```
Found 61 matches
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/chatwoot/src/views/chatwoot-conversation/record/inbox.js:
  Line 1369:                 "quickCreate",
  Line 1430:                 "quickCreateAppointment",
  Line 1476:                 "quickCreateTask",
  Line 1531:                 "quickCreateCase",

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/site/navbar.js:
  Line 405:         const quickCreate = this.element.querySelector(
  Line 406:             ".quick-create-container",
  Line 421:         if (quickCreate) {
  Line 422:             quickCreate.classList.remove("hidden-xs");
  Line 423:             rightIcons.appendChild(quickCreate);

/home/antl3x/repos/monostax/mono/components/crm/source/client/lib/espo-extra.js:
  Line 36:     `;data(){var e=super.data();e.itemList=this.getDataList();return e}afterRenderDetail(){this.$el.find(".popover-anchor").each((e,t)=>{var i=this.getHelper().transformMarkdownText(t.dataset.text).toString();Espo.Ui.popover($(t),{content:i},this)})}getDataList(){var e=Espo.Utils.cloneDeep(this.model.get(this.name))||[];let s=this.model.get("entityType");Array.isArray(e)&&e.forEach(e=>{var t=this.getFieldManager(),i=this.getLanguage(),t=t.getEntityTypeFieldParam(s,e.field,"type");if(t){t=t+"_"+e.type;i.has(t,"fieldValidationExplanations","Global")?e.popoverText=i.translate(t,"fieldValidationExplanations"):i.has(e.type,"fieldValidationExplanations","Global")&&(e.popoverText=i.translate(e.type,"fieldValidationExplanations"))}});return e}}e.default=s});define("views/import-error/fields/line-number",["exports","views/fields/int"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{disableFormatting=!0;data(){var e=super.data();e.valueIsSet=this.model.has(this.sourceName);e.isNotEmpty=this.model.has(this.sourceName);return e}setup(){super.setup();this.sourceName="exportLineNumber"===this.name?"exportRowIndex":"rowIndex"}getAttributeList(){return[this.sourceName]}getValueForDisplay(){var e=this.model.get(this.sourceName);e++;return this.formatNumber(e)}}e.default=s});define("views/import/step2",["exports","view","ui/select"],function(e,t,i){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=s(t);i=s(i);function s(e){return e&&e.__esModule?e:{default:e}}class a extends t.default{template="import/step-2";allowedFieldList=["createdAt","createdBy"];events={'click button[data-action="back"]':function(){this.back()},'click button[data-action="next"]':function(){this.next()},'click a[data-action="addField"]':function(e){var t=$(e.currentTarget).data("name");this.addField(t)},'click a[data-action="removeField"]':function(e){var t=$(e.currentTarget).data("name"),i=(th...

/home/antl3x/repos/monostax/mono/components/crm/source/client/lib/espo-crm.js:
  Line 2: define("modules/crm/views/meeting/fields/attendees",["exports","views/fields/link-multiple-with-role"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{columnName="status";roleFieldIsForeign=!1;emptyRoleValue="None"}e.default=s});define("modules/crm/views/calendar/fields/teams",["exports","views/fields/link-multiple"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{foreignScope="Team";getSelectBoolFilterList(){if("team"===this.getAcl().getPermissionLevel("userCalendar"))return["onlyMy"]}}e.default=s});define("modules/crm/knowledge-base-helper",["exports","ajax"],function(e,s){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;s=(t=s,t&&t.__esModule?t:{default:t});var t;e.default=class{constructor(e){this.language=e}getAttributesForEmail(e,t,i){t=t||{};t.body=e.get("body");t.name?t.name=t.name+" ":t.name="";t.name+=this.language.translate("KnowledgeBaseArticle","scopeNames")+": "+e.get("name");s.default.postRequest("KnowledgeBaseArticle/action/getCopiedAttachments",{id:e.id,parentType:"Email",field:"attachments"}).then(e=>{t.attachmentsIds=e.ids;t.attachmentsNames=e.names;t.isHtml=!0;i(t)})}}});define("modules/crm/views/task/record/list",["exports","views/record/list"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{rowActionsView="crm:views/task/record/row-actions/default";actionSetCompleted(e){var t=e.id;if(t){t=this.collection.get(t);if(t){Espo.Ui.notify(this.translate("saving","messages"));t.save({status:"Completed"},{patch:!0}).then(()=>{Espo.Ui.success(this.translate("Saved"));this.collection.fetch()})}}}}e.default=s});define("modules/crm/views/record/panels/tasks",["exports","views/record/panels/relationship","helpers/record/create-related"],func...
  Line 32:     `;data(){var e={success:"fas fa-check fa-sm",danger:"fas fa-times fa-sm",warning:"fas fa-question fa-sm"}[this.style]||"";return{...super.data(),statusText:this.statusText,style:this.style,statusIconClass:e,iconHtml:this.getIconHtml()}}init(){this.getUser().isAdmin()&&(this.isRemovable=!0);super.init()}setup(){this.inviteeType=this.model.get("relatedType");this.inviteeId=this.model.get("relatedId");this.inviteeName=this.model.get("relatedName");var e=this.model.get("data")||{},t=e.status||"Tentative";this.style=e.style||"default";this.statusText=this.getLanguage().translateOption(t,"acceptanceStatus","Meeting");this.messageName="eventConfirmation"+t;this.isThis&&(this.messageName+="This");this.messageData.invitee=$("<a>").attr("href","#"+this.inviteeType+"/view/"+this.inviteeId).attr("data-id",this.inviteeId).attr("data-scope",this.inviteeType).text(this.inviteeName);this.createMessage()}}e.default=s});define("crm:views/record/list-activities-dashlet",["views/record/list-expanded","crm:views/meeting/record/list","crm:views/task/record/list"],function(e,t,i){return e.extend({actionSetHeld:function(e){t.prototype.actionSetHeld.call(this,e)},actionSetNotHeld:function(e){t.prototype.actionSetNotHeld.call(this,e)},actionSetCompleted:function(e){i.prototype.actionSetCompleted.call(this,e)}})});define("crm:views/record/row-actions/tasks",["views/record/row-actions/relationship-no-unlink"],function(e){return e.extend({getActionList:function(){var e=[{action:"quickView",label:"View",data:{id:this.model.id},link:"#"+this.model.entityType+"/view/"+this.model.id,groupIndex:0}];if(this.options.acl.edit){e.push({action:"quickEdit",label:"Edit",data:{id:this.model.id},link:"#"+this.model.entityType+"/edit/"+this.model.id,groupIndex:0});~["Completed","Canceled"].indexOf(this.model.get("status"))||e.push({action:"Complete",text:this.translate("Complete","labels","Task"),data:{id:this.model.id},groupIndex:1})}this.options.acl.delete&&e.push({action:"removeRelated",label:"Remove",...

/home/antl3x/repos/monostax/mono/components/crm/source/client/lib/espo-admin.js:
  Line 55:     `;data(){return{dataList:this.model.attributes.attributes||[]}}}e.default=s});define("views/admin/dynamic-logic/conditions-string/item-operator-only-base",["exports","views/admin/dynamic-logic/conditions-string/item-base"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(a=t,a&&a.__esModule?a:{default:a});var a;class s extends t.default{template="admin/dynamic-logic/conditions-string/item-operator-only-base";createValueFieldView(){}}e.default=s});define("views/admin/dynamic-logic/conditions/field-types/base",["exports","view","ui/select","model"],function(e,t,a,s){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=i(t);a=i(a);s=i(s);function i(e){return e&&e.__esModule?e:{default:e}}class l extends t.default{template="admin/dynamic-logic/conditions/field-types/base";itemData;additionalData;type;field;scope;typeList;baseModel;events={'click > div > div > [data-action="remove"]':function(e){e.stopPropagation();this.trigger("remove-item")}};data(){return{type:this.type,field:this.field,scope:this.scope,typeList:this.typeList,leftString:this.translateLeftString()}}translateLeftString(){return this.translate(this.field,"fields",this.scope)}setup(){this.type=this.options.type;this.field=this.options.field;this.scope=this.options.scope;this.fieldType=this.options.fieldType;this.itemData=this.options.itemData;this.additionalData=this.itemData.data||{};this.typeList=this.getMetadata().get(`clientDefs.DynamicLogic.fieldTypes.${this.fieldType}.typeList`);this.baseModel=new s.default;this.wait(!0);this.createModel().then(e=>{this.model=e;this.populateValues();this.manageValue();this.wait(!1)})}async createModel(){return this.getModelFactory().create(this.scope)}afterRender(){this.$type=this.$el.find('select[data-name="type"]');a.default.init(this.$type.get(0));this.$type.on("change",()=>{this.type=this.$type.val();this.manageValue()})}populateValues(){if("varchar-matches"===this.getValueType())this.itemData.attribute&&this....
  Line 123:     `;portalCollection=null;data(){var e="AuthenticationProvider"!==this.model.entityType||this.portalCollection;return{value:this.getValueForDisplay(),isNotEmpty:e}}copyToClipboard(){var e=this.getValueForDisplay();navigator.clipboard.writeText(e).then(()=>{Espo.Ui.success(this.translate("Copied to clipboard"))})}getValueForDisplay(){var e;return"AuthenticationProvider"===this.model.entityType?this.portalCollection?this.portalCollection.models.map(e=>{var t="oauth-callback.php",a=(e.get("url")||"").replace(/\/+$/,"")+"/"+t,s=`/portal/${e.id}/`+t;return a.endsWith(s)?a.slice(0,-s.length)+"/portal/"+t:a}).join("\n"):null:(e=(this.getConfig().get("siteUrl")||"").replace(/\/+$/,""),e+"/oauth-callback.php")}setup(){super.setup();"AuthenticationProvider"===this.model.entityType&&this.getCollectionFactory().create("Portal").then(e=>{e.data.select=["url","isDefault"].join(",");e.fetch().then(()=>{this.portalCollection=e;this.reRender()})})}}e.default=s});define("views/settings/fields/language",["exports","views/fields/enum"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(a=t,a&&a.__esModule?a:{default:a});var a;class s extends t.default{setupOptions(){this.params.options=Espo.Utils.clone(this.getMetadata().get(["app","language","list"])||[]);this.translatedOptions=Espo.Utils.clone(this.getLanguage().translate("language","options")||{})}}e.default=s});define("views/settings/fields/history-entity-list",["exports","views/fields/entity-type-list"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(a=t,a&&a.__esModule?a:{default:a});var a;class s extends t.default{setupOptions(){super.setupOptions();this.params.options=this.params.options.filter(e=>{if(!this.getMetadata().get("scopes."+e+".disabled")&&this.getMetadata().get("scopes."+e+".object")&&this.getMetadata().get("scopes."+e+".activity"))return!0})}}e.default=s});define("views/settings/fields/group-tab-list",["exports","views/settings/fields/tab-list"],f...

/home/antl3x/repos/monostax/mono/components/crm/source/client/lib/espo-main.js:
  Line 60:     `;backdrop=!0;constructor(e){super(e);this.options=e}setup(){this.headerText=this.translate("Preview")}}e.default=s});define("helpers/mass-action",["exports","di","models/settings","models/user"],function(e,t,i,s){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;i=d(i);s=d(s);var a;let r,o,n,l;function d(e){return e&&e.__esModule?e:{default:e}}function h(d,t,h,i,c,e){var A,P,u,m,p,s,a=Symbol.metadata||Symbol.for("Symbol.metadata"),k=Object.defineProperty,r=Object.create,F=[r(null),r(null)],o=t.length;function _(s,a,r){return function(e,t){a&&(t=e,e=d);for(var i=0;i<s.length;i++)t=s[i].apply(e,r?[t]:[]);return r?t:e}}function O(e,t,i,s){if("function"==typeof e||!s&&void 0===e)return e;throw new TypeError(t+" must "+(i||"be")+" a function"+(s?"":" or undefined"))}function f(r,t,e,i,s,a,o,n,l,d,h){function c(e){if(!h(e))throw new TypeError("Attempted to access private element on non-instance")}var u=[].concat(t[0]),m=t[3],p=!o,f=1===s,g=3===s,v=4===s,y=2===s;function b(i,s,a){return function(e,t){return s&&(t=e,e=r),a&&a(e),w[i].call(e,t)}}if(!p){var w={},M=[],T=g?"get":v||f?"set":"value";if(l?(d||f?w={get:I(function(){return m(this)},i,"get"),set:function(e){t[4](this,e)}}:w[T]=m,d||I(w[T],i,y?"":T)):d||(w=Object.getOwnPropertyDescriptor(r,i)),!d&&!l){if((P=F[+n][i])&&7!=(P^s))throw Error("Decorating two elements with the same name ("+w[T].name+") is not supported yet");F[+n][i]=s<3?1:s}}for(var L=r,C=u.length-1;0<=C;C-=e?2:1){var E=O(u[C],"A decorator","be",!0),x=e?u[C-1]:void 0,S={},D={kind:["field","accessor","method","getter","setter","class"][s],name:i,metadata:A,addInitializer:function(e,t){if(e.v)throw new TypeError("attempted to call addInitializer after decoration was finished");O(t,"An initializer","be",!0),a.push(t)}.bind(null,S)};if(p)P=E.call(x,L,D),S.v=1,O(P,"class decorators","return")&&(L=P);else if(D.static=n,D.private=l,P=D.access={has:l?h.bind():function(e){return i in e}},v||(P.get=l?y?function(e){return c(e),w.value}:b("get",0...
  Line 84:     `;disabled=!1;constructor(e){super(e);this.modeList=e.modeList;this.mode=e.mode;this.scope=e.scope;this.hiddenMap={}}data(){return{disabled:this.disabled,modeDataList:this.modeList.filter(e=>!this.hiddenMap[e]||e===this.mode).map(e=>({name:e,active:e===this.mode,label:this.translate(e,"detailViewModes",this.scope)}))}}changeMode(e){this.mode=e;return this.reRender()}async hideMode(e){this.hiddenMap[e]=!0;await this.reRender()}async showMode(e){delete this.hiddenMap[e];await this.reRender()}disable(){this.disabled=!0;return this.reRender()}enable(){this.disabled=!1;return this.reRender()}}e.default=s});define("helpers/file-upload",["exports","di","models/settings"],function(e,t,i){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;i=(s=i,s&&s.__esModule?s:{default:s});var s,a;let r,o;function n(d,t,h,i,c,e){var A,P,u,m,p,s,a=Symbol.metadata||Symbol.for("Symbol.metadata"),k=Object.defineProperty,r=Object.create,F=[r(null),r(null)],o=t.length;function _(s,a,r){return function(e,t){a&&(t=e,e=d);for(var i=0;i<s.length;i++)t=s[i].apply(e,r?[t]:[]);return r?t:e}}function O(e,t,i,s){if("function"==typeof e||!s&&void 0===e)return e;throw new TypeError(t+" must "+(i||"be")+" a function"+(s?"":" or undefined"))}function f(r,t,e,i,s,a,o,n,l,d,h){function c(e){if(!h(e))throw new TypeError("Attempted to access private element on non-instance")}var u=[].concat(t[0]),m=t[3],p=!o,f=1===s,g=3===s,v=4===s,y=2===s;function b(i,s,a){return function(e,t){return s&&(t=e,e=r),a&&a(e),w[i].call(e,t)}}if(!p){var w={},M=[],T=g?"get":v||f?"set":"value";if(l?(d||f?w={get:I(function(){return m(this)},i,"get"),set:function(e){t[4](this,e)}}:w[T]=m,d||I(w[T],i,y?"":T)):d||(w=Object.getOwnPropertyDescriptor(r,i)),!d&&!l){if((P=F[+n][i])&&7!=(P^s))throw Error("Decorating two elements with the same name ("+w[T].name+") is not supported yet");F[+n][i]=s<3?1:s}}for(var L=r,C=u.length-1;0<=C;C-=e?2:1){var E=O(u[C],"A decorator","be",!0),x=e?u[C-1]:void 0,S={},D={kind:["field","accesso...
  Line 126:         `;constructor(e){super(e);this.options=e}setup(){this.headerText=this.translate("Select");this.options.fieldLabel&&(this.headerText+=" · "+this.options.fieldLabel);this.addActionHandler("select",(e,t)=>{this.options.onSelect(t.dataset.id);this.close()})}}e.default=s});define("ui/app-init",["exports","jquery"],function(e,d){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;d=(t=d,t&&t.__esModule?t:{default:t});var t;e.default=function(){var e=(0,d.default)(document);e.on("keydown.espo.button",e=>{if(!("Enter"!==e.code||"A"!==e.target.tagName||"button"!==e.target.getAttribute("role")||e.target.getAttribute("href")||e.ctrlKey||e.altKey||e.metaKey)){(0,d.default)(e.target).click();e.preventDefault()}});e.on("hidden.bs.dropdown",e=>{(0,d.default)(e.target).removeClass("dropup")});e.on("show.bs.dropdown",e=>{var s=e.target,a=(0,d.default)(e.target).find(".dropdown-menu");let t=a.get(0);if(t){var r=a.outerHeight(),a=a.outerWidth(),o=(0,d.default)(s),n=(0,d.default)(window).height(),l=e.target.getBoundingClientRect().bottom,n=n-(l+r),n=n<0&&r<l-100;if(!o.hasClass("more")&&!o.hasClass("tab")){n?o.addClass("dropup"):o.removeClass("dropup");if(t.classList.contains("pull-right")&&s.getBoundingClientRect().left-a<0){l=s.getBoundingClientRect().right-s.getBoundingClientRect().width/2,o=(t.style.maxWidth=l+"px",(0,d.default)(s));o.one("hidden.bs.dropdown",()=>{t.style.maxWidth=""})}else{a=(0,d.default)(s).closest(".dashlet-body"),l=e.target.parentElement.classList.contains("fix-position");if(a.length||l){let e=a;l&&(e=(0,d.default)(window));(0,d.default)(s).removeClass("dropup");let t=(0,d.default)(s);o=s.getBoundingClientRect(),a=t.find(".dropdown-menu"),l=s.classList.contains("pull-right");let i=t.find(".dropdown-toggle");e.on("scroll.dd",()=>{if(t.hasClass("open")){i.dropdown("toggle");e.off("scroll.dd")}});t.one("hidden.bs.dropdown",()=>{e.off("scroll.dd")});l=l?o.left-a.outerWidth()+o.width:o.left,n=n?o.top-r:o.top+s.getBoundingClientRect().height;a.cs...
  Line 180:     `;isPosted=!1;isPosting=!1;submitButtonElement;constructor(e){super();this.formData=e.formData}data(){return{isPosted:this.isPosted,successText:this.formData.successText}}setup(){this.getMetadata().setData(this.formData.metadata);this.getConfig().setMultiple(this.formData.config);this.getHelper().appParams.setAll(this.formData.appParams);this.getHelper().fieldManager.defs=this.getMetadata().get("fields");this.getDateTime().setSettingsAndPreferences(this.getConfig(),this.getPreferences());this.formData.captchaKey&&grecaptcha.ready(()=>{var e=document.querySelector(".grecaptcha-badge");e&&(e.style.zIndex="4")});for(var e in this.formData.language)this.getLanguage().setScopeData(e,this.formData.language[e]);this.model=new s.default({},{defs:{fields:this.formData.fieldDefs},entityType:"Lead"});this.model.url=this.formData.requestUrl;var t=[...this.formData.text?[{noteText:this.formData.text,noteStyle:"default",rows:[]}]:[],...this.formData.detailLayout];this.recordView=new o({model:this.model,detailLayout:t,sideView:null,bottomView:null,isWide:!0,shortcutKeysEnabled:!0,buttonsDisabled:!0});this.assignView("record",this.recordView,".record");this.whenReady().then(()=>this.initAutocomplete());this.listenTo(this.recordView,"save",()=>this.actionCreate());this.addActionHandler("submit",()=>this.actionCreate())}afterRender(){var e=this.element.querySelector(".detail-button-container .sub-container");e&&e.classList.add("sub-container-center-5");this.submitButtonElement=this.element.querySelector('button[data-action="submit"]')}async actionCreate(){if(!this.isPosting)if(this.recordView.validate())Espo.Ui.error(this.translate("Not valid"));else{this.isPosting=!0;this.recordView.disableActionItems();this.submitButtonElement.classList.add("disabled");this.submitButtonElement.setAttribute("disabled","disabled");Espo.Ui.notifyWait();var t=await this.processCaptcha(),t=t?{"X-Captcha-Token":t}:void 0;let e;try{e=await Espo.Ajax.postRequest(this.model.url,this.model.attributes,{he...
  Line 181: `,"#Task/create?emailId="+e.originalEmailId),i=new o.default;i.showCreate(this,{entityType:"Task",attributes:e,fullFormUrl:t,afterSave:()=>{this.model.fetch()},beforeRender:e=>{var t=e.getRecordView().getFieldView("name"),i=[];t&&t.params.options&&i.push(...t.params.options);i.push(this.translate("replyToEmail","nameOptions","Task"));e.getRecordView().setFieldOptionList("name",i)}})}async actionCreateContact(){var e={},t=new r.default,i=this.model.get("fromString")||this.model.get("fromName");if(i){i=t.parseNameFromStringAddress(i);if(i){var s=i.split(" ").slice(0,-1).join(" "),i=i.split(" ").slice(-1).join(" ");e.firstName=s;e.lastName=i}}if(this.model.get("replyToString")){s=this.model.get("replyToString"),i=s.split(";")[0],s=(e.emailAddress=t.parseAddressFromStringAddress(i),t.parseNameFromStringAddress(i));if(s){t=s.split(" ").slice(0,-1).join(" "),i=s.split(" ").slice(-1).join(" ");e.firstName=t;e.lastName=i}}e.emailAddress||(e.emailAddress=this.model.get("from"));e.originalEmailId=this.model.id;s=new o.default,t=await s.showCreate(this,{entityType:"Contact",attributes:e,afterSave:()=>{this.model.fetch();this.removeMenuItem("createContact");this.removeMenuItem("createLead")}});this.listenTo(t,"before:save",()=>{this.getRecordView().blockUpdateWebSocket(!0)})}actionReply(e,t,i){var s=new r.default,s=s.getReplyAttributes(this.model,e,i),a=(Espo.Ui.notifyWait(),this.getMetadata().get("clientDefs.Email.modalViews.compose")||"views/modals/compose-email");this.createView("quickCreate",a,{attributes:s,focusForCreate:!0},e=>{e.render();e.notify(!1);this.listenTo(e,"after:save",()=>{this.model.fetch()})})}actionReplyToAll(e,t){this.actionReply(e,t,!0)}actionForward(){let s=new r.default;Espo.Ui.notifyWait();Espo.Ajax.postRequest("Email/action/getDuplicateAttributes",{id:this.model.id}).then(e=>{var t=this.model.clone(),t=(t.set("body",e.body),s.getForwardAttributes(t));t.attachmentsIds=e.attachmentsIds;t.attachmentsNames=e.attachmentsNames;Espo.Ui.notifyWait();var i=thi...
  Line 209:     `;data(){let t=super.data();t.navbarOptionList=this.getNavbarOptionList();t.navbar=this.getNavbarValue()||this.getDefaultNavbar();t.navbarTranslatedOptions={};t.navbarOptionList.forEach(e=>{t.navbarTranslatedOptions[e]=this.translate(e,"themeNavbars")});return t}setup(){super.setup();this.initThemeManager();this.model.on("change:theme",(e,t,i)=>{this.initThemeManager();i.ui&&this.reRender().then(()=>s.default.focus(this.$element,{noTrigger:!0}))})}afterRenderEdit(){this.$navbar=this.$el.find('[data-name="themeNavbar"]');this.$navbar.on("change",()=>this.trigger("change"));s.default.init(this.$navbar)}getNavbarValue(){var e=this.model.get("themeParams")||{};return e.navbar}getNavbarDefs(){var e;return this.themeManager&&(e=this.themeManager.getParam("params"),e)&&e.navbar?Espo.Utils.cloneDeep(e.navbar):null}getNavbarOptionList(){var e=this.getNavbarDefs();return e&&(e=e.options||[],e.length)&&1!==e.length?e:[]}getDefaultNavbar(){var e=this.getNavbarDefs()||{};return e.default||null}initThemeManager(){var e=this.model.get("theme");this.themeManager=e?new i.default(this.getConfig(),this.getPreferences(),this.getMetadata(),e):null}getAttributeList(){return[this.name,"themeParams"]}setupOptions(){this.params.options=Object.keys(this.getMetadata().get("themes")||{}).sort((e,t)=>"EspoRtl"===t?-1:this.translate(e,"theme").localeCompare(this.translate(t,"theme")))}fetch(){var e=super.fetch(),t={};this.$navbar.length&&(t.navbar=this.$navbar.val());e.themeParams=t;return e}}e.default=r});define("views/settings/fields/tab-list",["exports","views/fields/array"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{addItemModalView="views/settings/modals/tab-list-field-add";noGroups=!1;noDelimiters=!1;setup(){super.setup();this.selected.forEach(e=>{!e||"object"!=typeof e||e.id||(e.id=this.generateItemId())});this.addActionHandler("editGroup",(e,t)=>{this.editGroup(t.dataset.value)}...
  Line 305:     `;shortcutKeys={"Control+Enter":"post"};setup(){this.headerText=this.translate("Create Post");this.buttonList=[{name:"post",label:"Post",style:"primary",title:"Ctrl+Enter",onClick:()=>this.post()},{name:"cancel",label:"Cancel",title:"Esc",onClick:e=>{e.close()}}];this.wait(!0);this.getModelFactory().create("Note",e=>{this.createView("record","views/stream/record/edit",{model:e,selector:".record"},e=>{this.listenTo(e,"after:save",()=>{this.trigger("after:save")});this.listenTo(e,"disable-post-button",()=>this.disableButton("post"));this.listenTo(e,"enable-post-button",()=>this.enableButton("post"))});this.wait(!1)})}getRecordView(){return this.getView("record")}post(){this.getRecordView().save()}}e.default=s});define("views/stream/fields/post",["exports","views/fields/text"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{data(){var e=super.data();(this.isDetailMode()||this.isListMode())&&(e.htmlValue=this.getTransformedValue());return e}getTransformedValue(){let s=super.getValueForDisplay();if("string"!=typeof s&&!(s instanceof String))return"";var e=this.model.attributes.data||{};let r=e.mentions||{};e=Object.keys(r).sort((e,t)=>t.length-e.length);if(!e.length)return this.getHelper().transformMarkdownText(s);e.forEach(e=>{var t=r[e].name,i=r[e].id,t=`[${t}](#User/view/${i})`;s=s.replace(new RegExp(e,"g"),t)});var t=this.getHelper().transformMarkdownText(s).toString();let o=(new DOMParser).parseFromString(t,"text/html").body;e.forEach(e=>{let s=r[e].id;var t="#User/view/"+s,a=this.getHelper().getAvatarHtml(s,"small",16,"avatar-link");if(a){let i=(new DOMParser).parseFromString(a,"text/html").body.childNodes[0];o.querySelectorAll(`a[href="${t}"]`).forEach(e=>{s===this.getUser().id&&e.classList.add("text-warning");var t=document.createElement("span");t.classList.add("nowrap","name-avatar");t.append(i.cloneNode());e.parentNode.replaceChild(t,e);t.append(e)})}});t=o...
  Line 307:             id="nav-quick-create-dropdown"
  Line 314:         <ul class="dropdown-menu" role="menu" aria-labelledby="nav-quick-create-dropdown">
  Line 320:                     data-action="quickCreate"
  Line 324:     `;data(){return{list:this.list}}setup(){this.addActionHandler("quickCreate",(e,t)=>{e.preventDefault();this.processCreate(t.dataset.name)});let t=this.getMetadata().get("scopes")||{};var e=this.getConfig().get("quickCreateList")||[];this.list=e.filter(e=>!!t[e]&&((t[e]||{}).disabled?void 0:!(t[e]||{}).acl||this.getAcl().check(e,"create")))}isAvailable(){return 0<this.list.length}async processCreate(e){Espo.Ui.notifyWait();var t=this.getMetadata().get(`clientDefs.${e}.quickCreateModalType`);if(t){t=this.getMetadata().get(`clientDefs.${e}.modalViews.`+t);if(t){t=await this.createView("modal",t,{scope:e});await t.render();Espo.Ui.notify();return}}t=new i.default;await t.showCreate(this,{entityType:e})}}e.default=a});define("views/record/panel-actions",["exports","view"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{template="record/panel-actions";data(){return{defs:this.options.defs,buttonList:this.getButtonList(),actionList:this.getActionList(),entityType:this.options.entityType,scope:this.options.scope}}setup(){this.buttonList=this.options.defs.buttonList||[];this.actionList=this.options.defs.actionList||[];this.defs=this.options.defs}getButtonList(){let t=[];this.buttonList.forEach(e=>{e.hidden||t.push(e)});return t}getActionList(){return this.actionList.filter(e=>!e.hidden).map(e=>{e=Espo.Utils.clone(e);if(e.action){e.data=Espo.Utils.clone(e.data||{});e.data.panel=this.options.defs.name}return e})}}e.default=s});define("views/record/merge",["exports","view","jquery"],function(e,t,n){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=i(t);n=i(n);function i(e){return e&&e.__esModule?e:{default:e}}class s extends t.default{template="record/merge";scope="";events={'change input[type="radio"][name="check-all"]':function(e){e.stopPropagation();var t=e.currentTarget.value;(0,n.default)('input[data-id="'+t+'"]').prop("checked",!0)},'click button[data...
  Line 488:     `;baseName="created";getAttributeList(){return[this.fieldAt,this.fieldBy]}init(){this.baseName=this.options.baseName||this.baseName;this.fieldAt=this.baseName+"At";this.fieldBy=this.baseName+"By";super.init()}setup(){super.setup();this.createField("at");this.createField("by")}data(){var e=this.model.has(this.fieldBy+"Id"),t=this.model.has(this.fieldAt);let i=null;if(e){var s=this.model.get(this.fieldBy+"Id");s&&(i=this.getHelper().getAvatarHtml(s,"small",16,"avatar-link"))}return{baseName:this.baseName,hasBy:e,hasAt:t,hasBoth:t&&e,byUserAvatar:i,...super.data()}}createField(e){var t=this.baseName+Espo.Utils.upperCaseFirst(e),i=this.model.getFieldType(t)||"base",i=this.model.getFieldParam(t,"view")||this.getFieldManager().getViewName(i);this.createView(e+"Field",i,{name:t,model:this.model,mode:this.MODE_DETAIL,readOnly:!0,readOnlyLocked:!0,selector:'[data-name="'+t+'"]'})}fetch(){return{}}}e.default=s});define("views/fields/collaborators",["exports","views/fields/link-multiple"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{linkClass="text-default";init(){this.assignmentPermission=this.getAcl().getPermissionLevel("assignmentPermission");"no"===this.assignmentPermission&&(this.readOnly=!0);super.init()}getSelectBoolFilterList(){if("team"===this.assignmentPermission)return["onlyMyTeam"]}getSelectPrimaryFilterName(){return"active"}getDetailLinkHtml(e,t){var i=super.getDetailLinkHtml(e),s=this.isDetailMode()?this.getHelper().getAvatarHtml(e,"small",18,"avatar-link"):"";return s?""+s+i:i}getOnEmptyAutocomplete(){if(!this.params.autocompleteOnEmpty)return this.ids&&this.ids.includes(this.getUser().id)?Promise.resolve([]):Promise.resolve([{id:this.getUser().id,name:this.getUser().attributes.name}])}prepareEditItemElement(e,t){var i=super.prepareEditItemElement(e,t),s=this.getHelper().getAvatarHtml(e,"small",18,"avatar-link");if(s){var s=(new DOMParser).parseFromString...
  Line 639:     `;setup(){this.scope=this.model.entityType;this.createView("header","views/header",{model:this.model,fullSelector:"#main > .header",scope:this.model.entityType,fontSizeFlexible:!0})}afterRender(){var e=this.options.field,t=this.model.getFieldParam(e+"Map","view")||this.getFieldManager().getViewName("map");this.createView("map",t,{model:this.model,name:e+"Map",selector:".map-container",height:this.getHelper().calculateContentContainerHeight(this.$el.find(".map-container"))},e=>{e.render()})}getHeader(){let e=this.model.get("name");e=e||this.model.id;var t=`#${this.model.entityType}/view/`+this.model.id,i=this.getLanguage().translate(this.model.entityType,"scopeNamesPlural"),s=this.translate(this.options.field,"fields",this.model.entityType),a=this.options.rootUrl||this.options.params.rootUrl||"#"+this.model.entityType,t=$("<a>").attr("href",t).append($("<span>").addClass("font-size-flexible title").text(e)),a=(this.model.get("deleted")&&t.css("text-decoration","line-through"),$("<span>").append($("<a>").attr("href",a).addClass("action").attr("data-action","navigateToRoot").text(i))),i=this.getHeaderIconHtml(),i=(i&&a.prepend(i),$("<span>").text(s));return this.buildHeaderHtml([a,t,i])}}e.default=s});define("views/action-history-record/record/list",["exports","views/record/list"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{rowActionsView="views/record/row-actions/view-and-remove";massActionList=["remove","export"]}e.default=s});define("views/action-history-record/modals/detail",["exports","views/modals/detail"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{fullFormDisabled=!0;editDisabled=!0;sideDisabled=!0}e.default=s});define("views/action-history-record/fields/target",["exports","views/fields/link-parent"],function(e,t){Object.defineProperty(e,"__esModule...

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/list-with-categories.js:
  Line 37:     quickCreate = true

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/list.js:
  Line 109:     quickCreate = false;
  Line 365:         if (this.quickCreate) {
  Line 367:                 action: "quickCreate",
  Line 878:      * Action `quickCreate`.
  Line 979:         if (this.quickCreate) {

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/waha/src/views/waha-session/panels/apps.js:
  Line 115:             this.createView('quickCreate', 'waha:views/waha-session-app/modals/edit', {

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/opportunity/record/kanban-item.js:
  Line 342:             this.createView('quickCreateTask', 'views/modals/edit', {

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/list.js:
  Line 241:                 action: "quickCreate",
  Line 280:             "quickCreate",

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/fields/relationship-list.js:
  Line 328:             "quickCreate",

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/chatwoot/src/views/chatwoot-conversation/record/kanban-item.js:
  Line 513:                 "quickCreate",
  Line 560:                 "quickCreateAgendamento",
  Line 607:                 "quickCreateTask",

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/advanced/lib/module-advanced.js:
  Line 17: define("advanced:views/workflow/actions/base",["view","model"],function(t,e){return t.extend({template:"advanced:workflow/actions/base",defaultActionData:{execution:{type:"immediately",field:!1,shiftDays:0}},data:function(){const t={};if(this.actionData.fields)for(const[e,i]of Object.entries(this.actionData.fields))if("add"===i.actionType||"remove"===i.actionType){let s;s="remove"===i.actionType?this.translate("Remove"):this.translate("Add"),t[e]=this.translate(s)}return{entityType:this.entityType,actionType:this.actionType,linkedEntityName:this.linkedEntityName||this.entityType,displayedLinkedEntityName:this.displayedLinkedEntityName||this.linkedEntityName||this.entityType,actionData:this.actionData,readOnly:this.readOnly,fieldActionLabelMap:t}},events:{'click [data-action="editAction"]':function(){this.edit()}},setup:function(){if(this.actionType=this.options.actionType,this.id=this.options.id,this.readOnly=this.options.readOnly,this.actionData=this.options.actionData||{},this.hasFormulaAvailable=!!this.getMetadata().get("app.formula.functionList"),this.options.isNew){const t={};for(const e in this.defaultActionData)t[e]=Espo.Utils.clone(this.defaultActionData[e]);if("execution"in t)for(const e in t.execution)t.execution[e]=Espo.Utils.clone(t.execution[e]);this.actionData=_.extend(t,this.actionData)}this.entityType=this.options.entityType,this.additionalSetup()},afterRender:function(){this.renderFields(),this.$formulaField=this.$el.find('.field[data-name="formula"]'),this.hasFormulaAvailable&&this.renderFormula()},renderFormula:function(){if(this.clearView("formula"),this.actionData.formula&&""!==this.actionData.formula){this.$formulaField.removeClass("hidden");const t=new e;return t.set("formula",this.actionData.formula),void this.createView("formula","views/fields/formula",{name:"formula",model:t,mode:"detail",height:100,selector:' .field[data-name="formula"]',inlineEditDisabled:!0,params:{seeMoreDisabled:!0},smallFont:!0},t=>{t.render()})}this.clearView("formul...

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/site/navbar/quick-create.js:
  Line 36:             id="nav-quick-create-dropdown"
  Line 43:         <ul class="dropdown-menu" role="menu" aria-labelledby="nav-quick-create-dropdown">
  Line 49:                     data-action="quickCreate"
  Line 62:         this.addActionHandler('quickCreate', (e, element) => {
  Line 71:         const list = this.getConfig().get('quickCreateList') || [];
  Line 101:         const type = this.getMetadata().get(`clientDefs.${scope}.quickCreateModalType`);

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/portal/fields/quick-create-list.js:
  Line 29: import SettingsQuickCreateListFieldView from 'views/settings/fields/quick-create-list';

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/list-related.js:
  Line 340:             action: 'quickCreate',
  Line 659:      * A quick-create action.

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/fields/text.js:
  Line 547:         this.createView('quickCreate', viewName, {

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/group-email-folder/list.js:
  Line 33:     quickCreate = true

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/email/list.js:
  Line 445:         this.createView('quickCreate', viewName, options, (view) => {

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/fields/email.js:
  Line 594:         this.createView('quickCreate', viewName, {

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/email/detail.js:
  Line 441:         this.createView('quickCreate', viewName, {
  Line 485:                 this.createView('quickCreate', viewName, {

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/email-folder/list.js:
  Line 33:     quickCreate = true

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/email-template/list.js:
  Line 33:     quickCreate = false

/home/antl3x/repos/monostax/mono/components/crm/source/client/modules/crm/src/views/record/panels/history.js:
  Line 188:                         return this.createView('quickCreate', viewName, {

/home/antl3x/repos/monostax/mono/components/crm/source/client/modules/crm/src/views/record/panels/activities.js:
  Line 613:             this.createView('quickCreate', 'views/modals/compose-email', {
```

---

## Assistant (Scope · glm-5-free · 25.0s)

_Thinking:_

I'm getting a good picture. Now let me look at:
1. The quick-create view implementation in the navbar
2. Saved filters implementation
3. Entity icons
4. The existing navbar.js implementation in the Global module

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/site/navbar/quick-create.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/site/navbar/quick-create.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of EspoCRM.
3:  *
4:  * EspoCRM – Open Source CRM application.
5:  * Copyright (C) 2014-2026 EspoCRM, Inc.
6:  * Website: https://www.espocrm.com
7:  *
8:  * This program is free software: you can redistribute it and/or modify
9:  * it under the terms of the GNU Affero General Public License as published by
10:  * the Free Software Foundation, either version 3 of the License, or
11:  * (at your option) any later version.
12:  *
13:  * This program is distributed in the hope that it will be useful,
14:  * but WITHOUT ANY WARRANTY; without even the implied warranty of
15:  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
16:  * GNU Affero General Public License for more details.
17:  *
18:  * You should have received a copy of the GNU Affero General Public License
19:  * along with this program. If not, see <https://www.gnu.org/licenses/>.
20:  *
21:  * The interactive user interfaces in modified source and object code versions
22:  * of this program must display Appropriate Legal Notices, as required under
23:  * Section 5 of the GNU Affero General Public License version 3.
24:  *
25:  * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
26:  * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
27:  ************************************************************************/
28: 
29: import View from 'view';
30: import RecordModal from 'helpers/record-modal';
31: 
32: class QuickCreateNavbarView extends View {
33: 
34:     templateContent = `
35:         <a
36:             id="nav-quick-create-dropdown"
37:             class="dropdown-toggle"
38:             data-toggle="dropdown"
39:             role="button"
40:             tabindex="0"
41:             title="{{translate 'Create'}}"
42:         ><i class="fas fa-plus icon"></i></a>
43:         <ul class="dropdown-menu" role="menu" aria-labelledby="nav-quick-create-dropdown">
44:             <li class="dropdown-header">{{translate 'Create'}}</li>
45:             {{#each list}}
46:                 <li><a
47:                     href="#{{./this}}/create"
48:                     data-name="{{./this}}"
49:                     data-action="quickCreate"
50:                 >{{translate this category='scopeNames'}}</a></li>
51:             {{/each}}
52:         </ul>
53:     `
54: 
55:     data() {
56:         return {
57:             list: this.list,
58:         };
59:     }
60: 
61:     setup() {
62:         this.addActionHandler('quickCreate', (e, element) => {
63:             e.preventDefault();
64: 
65:             this.processCreate(element.dataset.name);
66:         });
67: 
68:         const scopes = this.getMetadata().get('scopes') || {};
69: 
70:         /** @type {string[]} */
71:         const list = this.getConfig().get('quickCreateList') || [];
72: 
73:         this.list = list.filter(scope => {
74:             if (!scopes[scope]) {
75:                 return false;
76:             }
77: 
78:             if ((scopes[scope] || {}).disabled) {
79:                 return;
80:             }
81: 
82:             if ((scopes[scope] || {}).acl) {
83:                 return this.getAcl().check(scope, 'create');
84:             }
85: 
86:             return true;
87:         });
88:     }
89: 
90:     isAvailable() {
91:         return this.list.length > 0;
92:     }
93: 
94:     /**
95:      * @private
96:      * @param {string} scope
97:      */
98:     async processCreate(scope) {
99:         Espo.Ui.notifyWait();
100: 
101:         const type = this.getMetadata().get(`clientDefs.${scope}.quickCreateModalType`);
102: 
103:         if (type) {
104:             const viewName = this.getMetadata().get(`clientDefs.${scope}.modalViews.${type}`);
105: 
106:             if (viewName) {
107:                 const view = await this.createView('modal', viewName , {scope: scope});
108: 
109:                 await view.render();
110: 
111:                 Espo.Ui.notify();
112: 
113:                 return;
114:             }
115:         }
116: 
117:         const helper = new RecordModal();
118: 
119:         await helper.showCreate(this, {
120:             entityType: scope,
121:         });
122:     }
123: }
124: 
125: export default QuickCreateNavbarView;
126: 

(End of file - total 126 lines)
</content>
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/site/navbar.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/site/navbar.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of Monostax.
3:  *
4:  * Monostax – Custom EspoCRM extensions.
5:  * Copyright (C) 2025 Antonio Moura. All rights reserved.
6:  * Website: https://www.monostax.ai
7:  *
8:  * PROPRIETARY AND CONFIDENTIAL
9:  ************************************************************************/
10: 
11: import NavbarSiteView from "views/site/navbar";
12: import $ from "jquery";
13: 
14: const DEFAULT_TABLIST_ID = '__default_tablist__';
15: 
16: /**
17:  * Custom navbar view that:
18:  * 1. Filters out Conversas menu items for users without chatSsoUrl
19:  * 2. Implements Linear.app-style mobile drawer navigation
20:  * 3. Supports multi-sidenav sidebar via team-scoped SidenavConfig entities
21:  * Uses appParams from the /api/v1/App/user response.
22:  */
23: class CustomNavbarSiteView extends NavbarSiteView {
24:     /** @private */
25:     isMobileDrawerOpen = false;
26: 
27:     /** @private */
28:     _switchingConfig = false;
29: 
30:     /**
31:      * @private
32:      * @return {boolean}
33:      */
34:     hasChatwootAccess() {
35:         return !!this.getHelper().getAppParam("chatSsoUrl");
36:     }
37: 
38:     /**
39:      * Filter out Conversas menu items if user doesn't have chatSsoUrl.
40:      * @private
41:      * @param {Array} tabList
42:      * @return {Array}
43:      */
44:     filterConversasItems(tabList) {
45:         if (this.hasChatwootAccess()) {
46:             return tabList;
47:         }
48: 
49:         return tabList.filter((item) => {
50:             if (!item || typeof item !== "object") {
51:                 return true;
52:             }
53: 
54:             if (item.type === "divider" && item.text === "$Conversations") {
55:                 return false;
56:             }
57: 
58:             if (item.type === "url" && item.id && /^8535\d{2}$/.test(item.id)) {
59:                 return false;
60:             }
61: 
62:             return true;
63:         });
64:     }
65: 
66:     /**
67:      * Override getTabList to use team-scoped navbar config system.
68:      * Resolution priority:
69:      *   1. Team SidenavConfig (if any configs exist for user's teams)
70:      *   2. Legacy tab customization (existing useCustomTabList/addCustomTabs)
71:      *   3. System default tabList
72:      * @return {(Object|string)[]}
73:      */
74:     getTabList() {
75:         if (this.hasNavbarConfigSystem()) {
76:             const activeConfig = this.getActiveNavbarConfig();
77: 
78:             if (activeConfig) {
79:                 if (activeConfig.isDefaultTabList) {
80:                     return this.filterConversasItems(this.getLegacyTabList());
81:                 }
82: 
83:                 if (activeConfig.tabList) {
84:                     let tabList = Espo.Utils.cloneDeep(activeConfig.tabList);
85: 
86:                     if (this.isSide()) {
87:                         tabList.unshift('Home');
88:                     }
89: 
90:                     return this.filterConversasItems(tabList);
91:                 }
92:             }
93:         }
94: 
95:         return this.filterConversasItems(this.getLegacyTabList());
96:     }
97: 
98:     /**
99:      * Get the legacy tabList via parent's getTabList, filtered for Conversas.
100:      * @return {(Object|string)[]}
101:      */
102:     getLegacyTabList() {
103:         return super.getTabList();
104:     }
105: 
106:     /**
107:      * @return {boolean}
108:      */
109:     hasNavbarConfigSystem() {
110:         const configList = this.getNavbarConfigList();
111: 
112:         return configList && configList.length > 0;
113:     }
114: 
115:     /**
116:      * Get the navbar config list from team-scoped SidenavConfig entities.
117:      * Fetches from `teamSidenavConfigs` appParam (already filtered server-side).
118:      * Optionally adds a "Default" tabList option.
119:      * @return {Object[]}
120:      */
121:     getNavbarConfigList() {
122:         const configs = [...(this.getHelper().getAppParam('teamSidenavConfigs') || [])];
123: 
124:         if (this.getConfig().get('navbarConfigShowDefaultTabList')) {
125:             configs.push({
126:                 id: DEFAULT_TABLIST_ID,
127:                 name: this.getLanguage().translate('defaultConfig', 'navbarConfig', 'Global'),
128:                 isDefaultTabList: true,
129:             });
130:         }
131: 
132:         return configs;
133:     }
134: 
135:     /**
136:      * Get the active navbar config from the resolved list.
137:      * @return {Object|null}
138:      */
139:     getActiveNavbarConfig() {
140:         const configList = this.getNavbarConfigList();
141: 
142:         if (!configList || configList.length === 0) {
143:             return null;
144:         }
145: 
146:         const activeId = this.getPreferences().get('activeNavbarConfigId');
147: 
148:         if (activeId) {
149:             if (activeId === DEFAULT_TABLIST_ID) {
150:                 const defaultOption = configList.find(c => c.id === DEFAULT_TABLIST_ID);
151: 
152:                 if (defaultOption) {
153:                     return defaultOption;
154:                 }
155: 
156:                 console.warn('Default tabList option selected but setting is disabled, falling back');
157:             } else {
158:                 const found = configList.find(c => c.id === activeId);
159: 
160:                 if (found) {
161:                     return found;
162:                 }
163: 
164:                 console.warn('Active navbar config ID not found, falling back to default');
165:             }
166:         }
167: 
168:         return configList.find(c => c.isDefault) || configList[0];
169:     }
170: 
171:     /**
172:      * Override setup to add preference listener for activeNavbarConfigId.
173:      */
174:     setup() {
175:         super.setup();
176: 
177:         this.listenTo(this.getHelper().preferences, 'update', (attributeList) => {
178:             if (!attributeList) {
179:                 return;
180:             }
181: 
182:             if (attributeList.includes('activeNavbarConfigId')) {
183:                 this.setupTabDefsList();
184:                 this.reRender();
185:             }
186:         });
187:     }
188: 
189:     /**
190:      * Override afterRender to inject drawer styles, move header icons,
191:      * close-on-navigate, and set up the navbar config selector.
192:      */
193:     afterRender() {
194:         super.afterRender();
195: 
196:         this.injectMobileDrawerStyles();
197:         this.injectNavbarConfigSelectorStyles();
198:         this.setupMobileHeaderIcons();
199:         this.setupNavbarConfigSelector();
200: 
201:         this.listenTo(this.getRouter(), "routed", () => {
202:             if (this.isMobileDrawerOpen) {
203:                 this.closeMobileDrawer();
204:             }
205:         });
206:     }
207: 
208:     /**
209:      * Set up the navbar config selector in the sidebar.
210:      * @private
211:      */
212:     setupNavbarConfigSelector() {
213:         if (!this.shouldShowConfigSelector()) {
214:             return;
215:         }
216: 
217:         const leftContainer = this.element.querySelector('.navbar-left-container');
218:         const tabs = leftContainer ? leftContainer.querySelector('.tabs') : null;
219: 
220:         if (!leftContainer || !tabs) {
221:             return;
222:         }
223: 
224:         let container = leftContainer.querySelector('.navbar-config-selector-container');
225: 
226:         if (!container) {
227:             container = document.createElement('div');
228:             container.className = 'navbar-config-selector-container';
229:             leftContainer.insertBefore(container, tabs);
230:         }
231: 
232:         const configList = this.getNavbarConfigList();
233:         const activeConfig = this.getActiveNavbarConfig();
234: 
235:         this.createView(
236:             'navbarConfigSelector',
237:             'global:views/site/navbar-config-selector',
238:             {
239:                 selector: '.navbar-config-selector-container',
240:                 configList: configList,
241:                 activeConfigId: activeConfig ? activeConfig.id : null,
242:             },
243:             (view) => {
244:                 view.render();
245: 
246:                 this.listenTo(view, 'switch', (id) => {
247:                     this.switchNavbarConfig(id);
248:                 });
249:             }
250:         );
251:     }
252: 
253:     /**
254:      * @private
255:      * @return {boolean}
256:      */
257:     shouldShowConfigSelector() {
258:         if (!this.isSide()) {
259:             return false;
260:         }
261: 
262:         const configList = this.getNavbarConfigList();
263: 
264:         return configList && configList.length > 1;
265:     }
266: 
267:     /**
268:      * Switch the active navbar config and persist to preferences.
269:      * @param {string} configId
270:      */
271:     async switchNavbarConfig(configId) {
272:         if (this._switchingConfig) {
273:             return;
274:         }
275: 
276:         this._switchingConfig = true;
277: 
278:         Espo.Ui.notify(' ... ');
279: 
280:         try {
281:             await Espo.Ajax.putRequest('Preferences/' + this.getUser().id, {
282:                 activeNavbarConfigId: configId,
283:             });
284: 
285:             this.getPreferences().set('activeNavbarConfigId', configId);
286:             this.getPreferences().trigger('update', ['activeNavbarConfigId']);
287: 
288:             this.setupTabDefsList();
289:             this.reRender();
290: 
291:             Espo.Ui.notify(false);
292:         } catch (e) {
293:             console.error('Error switching navbar config:', e);
294:             Espo.Ui.error(
295:                 this.getLanguage().translate('errorSavingPreference', 'messages', 'Global')
296:             );
297:         } finally {
298:             this._switchingConfig = false;
299:         }
300:     }
301: 
302:     // =========================================================================
303:     // Mobile Drawer Navigation (Linear.app-style)
304:     // =========================================================================
305: 
306:     /**
307:      * @private
308:      * @return {boolean}
309:      */
310:     isMobileScreen() {
311:         const smallScreenWidth =
312:             this.getThemeManager().getParam("screenWidthXs") || 768;
313: 
314:         return window.innerWidth < smallScreenWidth;
315:     }
316: 
317:     /**
318:      * Override toggleCollapsable to use drawer on mobile.
319:      */
320:     toggleCollapsable() {
321:         if (this.isMobileScreen()) {
322:             if (this.isMobileDrawerOpen) {
323:                 this.closeMobileDrawer();
324:             } else {
325:                 this.openMobileDrawer();
326:             }
327: 
328:             return;
329:         }
330: 
331:         super.toggleCollapsable();
332:     }
333: 
334:     /**
335:      * Open the mobile drawer.
336:      * @private
337:      */
338:     openMobileDrawer() {
339:         this.isMobileDrawerOpen = true;
340: 
341:         document.body.classList.add("mobile-drawer-open");
342: 
343:         this.$mobileDrawerBackdrop = $("<div>")
344:             .addClass("mobile-drawer-backdrop")
345:             .on("click", () => this.closeMobileDrawer())
346:             .appendTo(document.body);
347: 
348:         requestAnimationFrame(() => {
349:             this.$mobileDrawerBackdrop.addClass("visible");
350:         });
351:     }
352: 
353:     /**
354:      * Close the mobile drawer.
355:      * @private
356:      */
357:     closeMobileDrawer() {
358:         if (!this.isMobileDrawerOpen) {
359:             return;
360:         }
361: 
362:         this.isMobileDrawerOpen = false;
363: 
364:         document.body.classList.remove("mobile-drawer-open");
365: 
366:         if (this.$mobileDrawerBackdrop) {
367:             this.$mobileDrawerBackdrop.remove();
368:             this.$mobileDrawerBackdrop = null;
369:         }
370:     }
371: 
372:     /**
373:      * Override xsCollapse to close drawer instead of just hiding collapsable.
374:      */
375:     xsCollapse() {
376:         if (this.isMobileDrawerOpen) {
377:             this.closeMobileDrawer();
378: 
379:             return;
380:         }
381: 
382:         super.xsCollapse();
383:     }
384: 
385:     /**
386:      * Move notification bell and user menu into .navbar-header on mobile.
387:      * @private
388:      */
389:     setupMobileHeaderIcons() {
390:         if (!this.isMobileScreen()) {
391:             return;
392:         }
393: 
394:         if (this.element.querySelector(".navbar-header-right")) {
395:             return;
396:         }
397: 
398:         const navbarHeader = this.element.querySelector(".navbar-header");
399: 
400:         if (!navbarHeader) {
401:             return;
402:         }
403: 
404:         const search = this.element.querySelector(".global-search-container");
405:         const quickCreate = this.element.querySelector(
406:             ".quick-create-container",
407:         );
408:         const bell = this.element.querySelector(
409:             ".notifications-badge-container",
410:         );
411:         const menu = this.element.querySelector(".menu-container");
412: 
413:         const rightIcons = document.createElement("div");
414:         rightIcons.className = "navbar-header-right";
415: 
416:         if (search) {
417:             search.classList.remove("navbar-form");
418:             rightIcons.appendChild(search);
419:         }
420: 
421:         if (quickCreate) {
422:             quickCreate.classList.remove("hidden-xs");
423:             rightIcons.appendChild(quickCreate);
424:         }
425: 
426:         if (bell) rightIcons.appendChild(bell);
427:         if (menu) rightIcons.appendChild(menu);
428: 
429:         navbarHeader.appendChild(rightIcons);
430:     }
431: 
432:     /**
433:      * Load mobile drawer CSS stylesheet (idempotent).
434:      * @private
435:      */
436:     injectMobileDrawerStyles() {
437:         if (document.getElementById("mobile-drawer-styles")) {
438:             return;
439:         }
440: 
441:         const link = document.createElement("link");
442:         link.id = "mobile-drawer-styles";
443:         link.rel = "stylesheet";
444:         link.href = "client/custom/modules/global/css/mobile-drawer.css";
445: 
446:         document.head.appendChild(link);
447:     }
448: 
449:     /**
450:      * Load navbar config selector CSS stylesheet (idempotent).
451:      * @private
452:      */
453:     injectNavbarConfigSelectorStyles() {
454:         if (document.getElementById("navbar-config-selector-styles")) {
455:             return;
456:         }
457: 
458:         const link = document.createElement("link");
459:         link.id = "navbar-config-selector-styles";
460:         link.rel = "stylesheet";
461:         link.href = "client/custom/modules/global/css/navbar-config-selector.css";
462: 
463:         document.head.appendChild(link);
464:     }
465: }
466: 
467: export default CustomNavbarSiteView;
468: 

(End of file - total 468 lines)
</content>
```

**Tool: grep**

**Input:**
```json
{
  "include": "*.js",
  "pattern": "savedFilter|SavedFilter"
}
```

**Output:**
```
No files found
```

---

## Assistant (Scope · glm-5-free · 10.8s)

_Thinking:_

Now let me search for SavedFilter entity implementation and look for how entity icons are retrieved in EspoCRM.

**Tool: grep**

**Input:**
```json
{
  "include": "*.json",
  "pattern": "SavedFilter|savedFilter"
}
```

**Output:**
```
No files found
```

**Tool: grep**

**Input:**
```json
{
  "include": "*.js",
  "pattern": "iconClass|getIconClass|scopeIcon"
}
```

**Output:**
```
Found 125 matches (showing first 100)
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/modals/navbar-config-field-add.js:
  Line 49:                             name: 'iconClass',
  Line 50:                             labelText: this.translate('iconClass', 'fields', 'EntityManager'),
  Line 74:                 iconClass: {

/home/antl3x/repos/monostax/mono/components/crm/source/client/modules/crm/lib/fullcalendar.js:
  Line 6: var FullCalendar=(e=>{var H,C,B,j,z,U,L,W,F={},V=[],G=/acit|ex(?:s|g|n|p|$)|rph|grid|ows|mnc|ntw|ine[ch]|zoo|^ord|itera/i;function R(e,t){for(var n in t)e[n]=t[n];return e}function Q(e){var t=e.parentNode;t&&t.removeChild(e)}function y(e,t,n){var r,i,s,a={};for(s in t)"key"==s?r=t[s]:"ref"==s?i=t[s]:a[s]=t[s];if(2<arguments.length&&(a.children=3<arguments.length?H.call(arguments,2):n),"function"==typeof e&&null!=e.defaultProps)for(s in e.defaultProps)void 0===a[s]&&(a[s]=e.defaultProps[s]);return q(e,a,r,i,null)}function q(e,t,n,r,i){var s={type:e,props:t,key:n,ref:r,__k:null,__:null,__b:0,__e:null,__d:void 0,__c:null,__h:null,constructor:void 0,__v:null==i?++B:i};return null==i&&null!=C.vnode&&C.vnode(s),s}function t(){return{current:null}}function x(e){return e.children}function Y(e,t,n){"-"===t[0]?e.setProperty(t,null==n?"":n):e[t]=null==n?"":"number"!=typeof n||G.test(t)?n:n+"px"}function Z(e,t,n,r,i){var s;e:if("style"===t)if("string"==typeof n)e.style.cssText=n;else{if("string"==typeof r&&(e.style.cssText=r=""),r)for(t in r)n&&t in n||Y(e.style,t,"");if(n)for(t in n)r&&n[t]===r[t]||Y(e.style,t,n[t])}else if("o"===t[0]&&"n"===t[1])s=t!==(t=t.replace(/Capture$/,"")),t=(t.toLowerCase()in e?t.toLowerCase():t).slice(2),e.l||(e.l={}),e.l[t+s]=n,n?r||e.addEventListener(t,s?J:X,s):e.removeEventListener(t,s?J:X,s);else if("dangerouslySetInnerHTML"!==t){if(i)t=t.replace(/xlink(H|:h)/,"h").replace(/sName$/,"s");else if("width"!==t&&"height"!==t&&"href"!==t&&"list"!==t&&"form"!==t&&"tabIndex"!==t&&"download"!==t&&t in e)try{e[t]=null==n?"":n;break e}catch(e){}"function"!=typeof n&&(null==n||!1===n&&-1==t.indexOf("-")?e.removeAttribute(t):e.setAttribute(t,n))}}function X(e){z=!0;try{return this.l[e.type+!1](C.event?C.event(e):e)}finally{z=!1}}function J(e){z=!0;try{return this.l[e.type+!0](C.event?C.event(e):e)}finally{z=!1}}function T(e,t){this.props=e,this.context=t}function $(e,t){if(null==t)return e.__?$(e.__,e.__.__k.indexOf(e)+1):null;for(var n;t<e.__k.length;t++)if(...

/home/antl3x/repos/monostax/mono/components/crm/source/client/lib/espo-crm.js:
  Line 2: define("modules/crm/views/meeting/fields/attendees",["exports","views/fields/link-multiple-with-role"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{columnName="status";roleFieldIsForeign=!1;emptyRoleValue="None"}e.default=s});define("modules/crm/views/calendar/fields/teams",["exports","views/fields/link-multiple"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{foreignScope="Team";getSelectBoolFilterList(){if("team"===this.getAcl().getPermissionLevel("userCalendar"))return["onlyMy"]}}e.default=s});define("modules/crm/knowledge-base-helper",["exports","ajax"],function(e,s){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;s=(t=s,t&&t.__esModule?t:{default:t});var t;e.default=class{constructor(e){this.language=e}getAttributesForEmail(e,t,i){t=t||{};t.body=e.get("body");t.name?t.name=t.name+" ":t.name="";t.name+=this.language.translate("KnowledgeBaseArticle","scopeNames")+": "+e.get("name");s.default.postRequest("KnowledgeBaseArticle/action/getCopiedAttachments",{id:e.id,parentType:"Email",field:"attachments"}).then(e=>{t.attachmentsIds=e.ids;t.attachmentsNames=e.names;t.isHtml=!0;i(t)})}}});define("modules/crm/views/task/record/list",["exports","views/record/list"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{rowActionsView="crm:views/task/record/row-actions/default";actionSetCompleted(e){var t=e.id;if(t){t=this.collection.get(t);if(t){Espo.Ui.notify(this.translate("saving","messages"));t.save({status:"Completed"},{patch:!0}).then(()=>{Espo.Ui.success(this.translate("Saved"));this.collection.fetch()})}}}}e.default=s});define("modules/crm/views/record/panels/tasks",["exports","views/record/panels/relationship","helpers/record/create-related"],func...
  Line 90:                 class="{{iconClass}} text-muted action icon"
  Line 97:         {{~!}}`,data:function(){return{notRelationship:this.params.notRelationship,viewLabel:this.translate("View"),id:this.model.id,scope:this.model.entityType,iconClass:this.getMetadata().get(["clientDefs",this.model.entityType,"iconClass"])||"far fa-calendar-times"}}})});define("crm:views/event-confirmation/confirmation",["view"],function(e){return e.extend({template:"crm:event-confirmation/confirmation",data:function(){var e=this.actionData.style||"default";return{actionData:this.actionData,style:e,dateStart:this.actionData.dateStart?this.convertDateTime(this.actionData.dateStart):null,sentDateStart:this.actionData.sentDateStart?this.convertDateTime(this.actionData.sentDateStart):null,dateStartChanged:this.actionData.sentDateStart&&this.actionData.dateStart!==this.actionData.sentDateStart,actionDataList:this.getActionDataList()}},setup:function(){this.actionData=this.options.actionData},getActionDataList:function(){let i={Accepted:"accept",Declined:"decline",Tentative:"tentative"};var e=["Accepted","Tentative","Declined"];if(!e.includes(this.actionData.status))return null;let s=window.location.href.replace("action="+i[this.actionData.status],"action={action}");return e.map(e=>{var t=e===this.actionData.status;return{active:t,link:t?"":s.replace("{action}",i[e]),label:this.actionData.statusTranslation[e]}})},convertDateTime:function(e){var t=this.getConfig().get("timeZone"),t=this.getDateTime().toMoment(e).tz(t);return t.format(this.getDateTime().getDateTimeFormat())+" "+t.format("Z z")}})});define("crm:views/email-queue-item/list",["views/list"],function(e){return e.extend({createButton:!1})});define("crm:views/email-queue-item/record/list",["views/record/list"],function(e){return e.extend({rowActionsView:"views/record/row-actions/remove-only"})});define("crm:views/document/list",["views/list-with-categories"],function(e){return e.extend({categoryScope:"DocumentFolder"})});define("crm:views/document/modals/select-records",["views/modals/select-records-with-categ...

/home/antl3x/repos/monostax/mono/components/crm/source/client/lib/espo-admin.js:
  Line 111:     `;noFolderMap=!1;getFoldersUrl="EmailAccount/action/getFolders";folderDataList;getAttributeList(){return[...super.getAttributeList(),"folderMap"]}data(){return{...super.data(),itemDataList:this.getItemDataList()}}setup(){super.setup();if(!this.noFolderMap){this.loadEmailFolders();this.listenTo(this.model,"change:assignedUserId",()=>this.loadEmailFolders())}this.addHandler("change",'select[data-role="folderId"]',()=>{this.trigger("change")})}async loadEmailFolders(){this.folderDataList=await this.fetchEmailFolders();await this.whenRendered();await this.reRender()}setupOptions(){this.params.options=["INBOX"]}getItemDataList(){var e=this.model.attributes[this.name]??[];return e.map(e=>{let t,a=this.getItemMappedFolderId(e);var s=null==(t=this.folderDataList)?void 0:t.find(e=>e.id===a);return{value:e,folderLabel:null==s?void 0:s.name}})}fetchFolders(){return new Promise(t=>{var e={host:this.model.get("host"),port:this.model.get("port"),security:this.model.get("security"),username:this.model.get("username"),emailAddress:this.model.get("emailAddress"),userId:this.model.get("assignedUserId")};this.model.has("password")&&(e.password=this.model.get("password"));this.model.isNew()||(e.id=this.model.id);Espo.Ajax.postRequest(this.getFoldersUrl,e).then(e=>{t(e)}).catch(e=>{Espo.Ui.error(this.translate("couldNotConnectToImap","messages","EmailAccount"));e.errorIsHandled=!0;t(["INBOX"])})})}afterRender(){super.afterRender();this.isDetailMode()}getItemHtml(e){var t=super.getItemHtml(e);if(this.noFolderMap)return t;let a=this.folderDataList;var s=this.getItemMappedFolderId(e);!a&&s&&(a=[{id:s,name:s}]);if(!a)return t;var i=document.createElement("div");i.innerHTML=t;let l=i.querySelector(".list-group-item");var t=document.createElement("div"),o=(t.classList.add("item-input-container"),document.createElement("select"));o.className="form-control native-select input-sm";o.dataset.role="folderId";o.append(document.createElement("option"));for(let e of a){var n=document.createElemen...
  Line 123:     `;portalCollection=null;data(){var e="AuthenticationProvider"!==this.model.entityType||this.portalCollection;return{value:this.getValueForDisplay(),isNotEmpty:e}}copyToClipboard(){var e=this.getValueForDisplay();navigator.clipboard.writeText(e).then(()=>{Espo.Ui.success(this.translate("Copied to clipboard"))})}getValueForDisplay(){var e;return"AuthenticationProvider"===this.model.entityType?this.portalCollection?this.portalCollection.models.map(e=>{var t="oauth-callback.php",a=(e.get("url")||"").replace(/\/+$/,"")+"/"+t,s=`/portal/${e.id}/`+t;return a.endsWith(s)?a.slice(0,-s.length)+"/portal/"+t:a}).join("\n"):null:(e=(this.getConfig().get("siteUrl")||"").replace(/\/+$/,""),e+"/oauth-callback.php")}setup(){super.setup();"AuthenticationProvider"===this.model.entityType&&this.getCollectionFactory().create("Portal").then(e=>{e.data.select=["url","isDefault"].join(",");e.fetch().then(()=>{this.portalCollection=e;this.reRender()})})}}e.default=s});define("views/settings/fields/language",["exports","views/fields/enum"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(a=t,a&&a.__esModule?a:{default:a});var a;class s extends t.default{setupOptions(){this.params.options=Espo.Utils.clone(this.getMetadata().get(["app","language","list"])||[]);this.translatedOptions=Espo.Utils.clone(this.getLanguage().translate("language","options")||{})}}e.default=s});define("views/settings/fields/history-entity-list",["exports","views/fields/entity-type-list"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(a=t,a&&a.__esModule?a:{default:a});var a;class s extends t.default{setupOptions(){super.setupOptions();this.params.options=this.params.options.filter(e=>{if(!this.getMetadata().get("scopes."+e+".disabled")&&this.getMetadata().get("scopes."+e+".object")&&this.getMetadata().get("scopes."+e+".activity"))return!0})}}e.default=s});define("views/settings/fields/group-tab-list",["exports","views/settings/fields/tab-list"],f...
  Line 126:         >{{translate 'Edit Layouts' scope='LayoutSet'}}</a>`;editTemplateContent=""}e.default=s});define("views/inbound-email/record/list",["exports","views/record/list"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(a=t,a&&a.__esModule?a:{default:a});var a;class s extends t.default{quickDetailDisabled=!0;quickEditDisabled=!0;massActionList=["remove","massUpdate"];checkAllResultDisabled=!0}e.default=s});define("views/inbound-email/record/edit",["exports","views/record/edit","views/inbound-email/record/detail"],function(e,t,a){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=s(t);a=s(a);function s(e){return e&&e.__esModule?e:{default:e}}class i extends t.default{setup(){super.setup();a.default.prototype.setupFieldsBehaviour.call(this);a.default.prototype.initSslFieldListening.call(this);a.default.prototype.wasFetched.call(this)&&this.setFieldReadOnly("fetchSince")}modifyDetailLayout(e){a.default.prototype.modifyDetailLayout.call(this,e)}controlStatusField(){a.default.prototype.controlStatusField.call(this)}initSmtpFieldsControl(){a.default.prototype.initSmtpFieldsControl.call(this)}controlSmtpFields(){a.default.prototype.controlSmtpFields.call(this)}controlSentFolderField(){a.default.prototype.controlSentFolderField.call(this)}controlSmtpAuthField(){a.default.prototype.controlSmtpAuthField.call(this)}wasFetched(){a.default.prototype.wasFetched.call(this)}}e.default=i});define("views/inbound-email/fields/test-send",["exports","views/email-account/fields/test-send"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(a=t,a&&a.__esModule?a:{default:a});var a;class s extends t.default{getSmtpData(){return{server:this.model.get("smtpHost"),port:this.model.get("smtpPort"),auth:this.model.get("smtpAuth"),security:this.model.get("smtpSecurity"),username:this.model.get("smtpUsername"),password:this.model.get("smtpPassword")||null,authMechanism:this.model.get("smtpAuthMechanism"),fromName:th...
  Line 144:     `;getAttributeList(){return["width","widthPx"]}setup(){this.auxModel=new s.default;this.syncAuxModel();this.listenTo(this.model,"change",(e,t)=>{t.ui||this.syncAuxModel()});var e=new a.default({name:"unit",mode:"edit",model:this.auxModel,params:{options:["%","px"]}}),t=this.valueView=new i.default({name:"value",mode:"edit",model:this.auxModel,params:{min:this.getMinValue(),max:this.getMaxValue()},labelText:this.translate("Value")});this.assignView("unit",e,'[data-name="unit"]');this.assignView("value",t,'[data-name="value"]');this.listenTo(this.auxModel,"change",(e,t)=>{if(t.ui){this.valueView.params.max=this.getMaxValue();this.valueView.params.min=this.getMinValue();this.model.set(this.fetch(),{ui:!0})}})}getMinValue(){return"px"===this.auxModel.attributes.unit?30:5}getMaxValue(){return"px"===this.auxModel.attributes.unit?768:95}validate(){return this.valueView.validate()}fetch(){return"px"===this.auxModel.attributes.unit?{width:null,widthPx:this.auxModel.attributes.value}:{width:this.auxModel.attributes.value,widthPx:null}}syncAuxModel(){var e=this.model.attributes.width,t=this.model.attributes.widthPx,a=e||!t?"%":"px";this.auxModel.set({unit:a,value:"px"==a?t:e})}}e.default=o});define("views/admin/label-manager/index",["exports","view","ui/select"],function(e,t,a){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=s(t);a=s(a);function s(e){return e&&e.__esModule?e:{default:e}}class i extends t.default{template="admin/label-manager/index";scopeList=null;scope=null;language=null;languageList=null;events={'click [data-action="selectScope"]':function(e){let t=$(e.currentTarget).data("name");this.getRouter().checkConfirmLeaveOut(()=>{this.selectScope(t)})},'change select[data-name="language"]':function(e){let t=$(e.currentTarget).val();this.getRouter().checkConfirmLeaveOut(()=>{this.selectLanguage(t)})}};data(){return{scopeList:this.scopeList,languageList:this.languageList,scope:this.scope,language:this.language}}setup(){this.languageList=this.get...

/home/antl3x/repos/monostax/mono/components/crm/source/client/lib/espo-main.js:
  Line 2: define("di",["exports"],function(e){Object.defineProperty(e,"__esModule",{value:!0});e.container=void 0;e.inject=function(i){return function(e,t){t.addInitializer(function(){let e=a.get(i);if(!e){e=Reflect.construct(i,s.get(i));a.set(i,e)}this[t.name]=e})}};e.register=function(){let t=0<arguments.length&&void 0!==arguments[0]?arguments[0]:[];return function(e){s.set(e,t)}};let s=new Map,a=e.container=new Map});define("date-time",["exports","moment"],function(e,a){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;a=(t=a,t&&t.__esModule?t:{default:t});var t;e.default=class{constructor(){}internalDateFormat="YYYY-MM-DD";internalDateTimeFormat="YYYY-MM-DD HH:mm";internalDateTimeFullFormat="YYYY-MM-DD HH:mm:ss";dateFormat="MM/DD/YYYY";timeFormat="HH:mm";timeZone=null;systemTimeZone;weekStart=1;readableDateFormatMap={"DD.MM.YYYY":"DD MMM","DD/MM/YYYY":"DD MMM"};readableShortDateFormatMap={"DD.MM.YYYY":"D MMM","DD/MM/YYYY":"D MMM"};hasMeridian(){return new RegExp("A","i").test(this.timeFormat)}getDateFormat(){return this.dateFormat}getTimeFormat(){return this.timeFormat}getDateTimeFormat(){return this.dateFormat+" "+this.timeFormat}getReadableDateFormat(){return this.readableDateFormatMap[this.getDateFormat()]||"MMM DD"}getReadableShortDateFormat(){return this.readableShortDateFormatMap[this.getDateFormat()]||"MMM D"}getReadableDateTimeFormat(){return this.getReadableDateFormat()+" "+this.timeFormat}getReadableShortDateTimeFormat(){return this.getReadableShortDateFormat()+" "+this.timeFormat}fromDisplayDate(e){var t=(0,a.default)(e,this.dateFormat);return t.isValid()?t.format(this.internalDateFormat):-1}getTimeZone(){return this.timeZone||"UTC"}toDisplayDate(e){var t;return e&&"string"==typeof e&&(t=(0,a.default)(e,this.internalDateFormat),t.isValid())?t.format(this.dateFormat):""}fromDisplay(e){let t;t=this.timeZone?a.default.tz(e,this.getDateTimeFormat(),this.timeZone).utc():a.default.utc(e,this.getDateTimeFormat());return t.isValid()?t.format(this.intern...
  Line 88:     `;maxNumberToDisplay=3;numberList;lastNumber;data(){return{dataList:this.getDataList()}}init(){this.on("render",()=>{if(!document.querySelector(".collapsed-modal-bar")){var e=document.createElement("div");e.classList.add("collapsed-modal-bar");document.body.append(e)}})}setup(){this.lastNumber=0;this.numberList=[]}getDataList(){let i=[],e=[...this.numberList];this.numberList.length>this.maxNumberToDisplay&&(e=e.slice(this.numberList.length-this.maxNumberToDisplay));e.reverse().forEach((e,t)=>{i.push({number:e.toString(),key:this.composeKey(e),index:t})});return i}calculateDuplicateNumber(e){let t=0;for(var i of this.numberList){i=this.getCollapsedModalViewByNumber(i);i&&i.title===e&&t++}return 0===t?null:t}getCollapsedModalViewByNumber(e){var t=this.composeKey(e);return this.getView(t)}getModalViewList(){return this.numberList.map(e=>this.getCollapsedModalViewByNumber(e)).filter(e=>e).map(e=>e.modalView)}async addModalView(t,e){let i=this.lastNumber;this.numberList.push(this.lastNumber);var s=this.composeKey(i),a=(this.lastNumber++,new r.default({modalView:t,title:e.title,duplicateNumber:this.calculateDuplicateNumber(e.title),onClose:()=>this.removeModalView(i),onExpand:()=>{this.removeModalView(i,!0);setTimeout(async()=>{var e="dialog-"+i;this.setView(e,t);t.setSelector(t.containerSelector);await this.getView(e).render();t.trigger("after:expand")},5)}}));await this.assignView(s,a,`[data-number="${i}"]`);await this.reRender(!0)}removeModalView(e){var t=1<arguments.length&&void 0!==arguments[1]&&arguments[1],i=this.composeKey(e),s=this.numberList.indexOf(e);~s&&this.numberList.splice(s,1);if(this.isRendered()){s=this.element.querySelector(`.collapsed-modal[data-number="${e}"]`);s&&s.remove()}t||this.reRender();this.clearView(i)}composeKey(e){return"key-"+e}}e.default=s});define("views/stream/note",["exports","view"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default...
  Line 101:                 ><span class="{{iconClass}}"></span></a>
  Line 104:     `;constructor(e){super(e);this.reactions=e.reactions}data(){return{reactions:this.reactions}}}e.default=s});define("views/record/search",["exports","view","helpers/misc/stored-text-search","ui/autocomplete","views/search/filter"],function(e,t,s,a,o){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=i(t);s=i(s);a=i(a);o=i(o);function i(e){return e&&e.__esModule?e:{default:e}}class r extends t.default{template="record/search";scope="";entityType="";searchManager;fieldFilterList=null;fieldFilterTranslations=null;textFilter="";primary=null;presetFilterList=null;advanced;bool=null;filtersLayoutName="filters";disableSavePreset=!1;textFilterDisabled=!1;toShowApplyFiltersButton=!1;toShowResetFiltersText=!1;isSearchedWithAdvancedFilter=!1;primaryFiltersDisabled=!1;viewModeIconClassMap={list:"fas fa-align-justify",kanban:"fas fa-align-left fa-rotate-90"};FIELD_QUICK_SEARCH_COUNT_THRESHOLD=4;autocompleteLimit=7;data(){return{scope:this.scope,entityType:this.entityType,textFilter:this.textFilter,bool:this.bool||{},boolFilterList:this.boolFilterList,hasFieldQuickSearch:this.fieldFilterList.length>=this.FIELD_QUICK_SEARCH_COUNT_THRESHOLD,filterFieldDataList:this.getFilterFieldDataList(),filterDataList:this.getFilterDataList(),presetName:this.presetName,presetFilterList:this.getPresetFilterList(),leftDropdown:this.hasLeftDropdown(),textFilterDisabled:this.textFilterDisabled,viewMode:this.viewMode,viewModeDataList:this.viewModeDataList||[],hasViewModeSwitcher:this.viewModeList&&1<this.viewModeList.length,isWide:this.options.isWide,toShowApplyFiltersButton:this.toShowApplyFiltersButton,toShowResetFiltersText:this.toShowResetFiltersText,primaryFiltersDisabled:this.primaryFiltersDisabled}}setup(){this.entityType=this.collection.entityType;this.scope=this.options.scope||this.entityType;this.filtersLayoutName=this.options.filtersLayoutName||this.filtersLayoutName;this.primaryFiltersDisabled=this.options.primaryFiltersDisabled||this.primaryFiltersDisabled;this.viewM...
  Line 126:         `;constructor(e){super(e);this.options=e}setup(){this.headerText=this.translate("Select");this.options.fieldLabel&&(this.headerText+=" · "+this.options.fieldLabel);this.addActionHandler("select",(e,t)=>{this.options.onSelect(t.dataset.id);this.close()})}}e.default=s});define("ui/app-init",["exports","jquery"],function(e,d){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;d=(t=d,t&&t.__esModule?t:{default:t});var t;e.default=function(){var e=(0,d.default)(document);e.on("keydown.espo.button",e=>{if(!("Enter"!==e.code||"A"!==e.target.tagName||"button"!==e.target.getAttribute("role")||e.target.getAttribute("href")||e.ctrlKey||e.altKey||e.metaKey)){(0,d.default)(e.target).click();e.preventDefault()}});e.on("hidden.bs.dropdown",e=>{(0,d.default)(e.target).removeClass("dropup")});e.on("show.bs.dropdown",e=>{var s=e.target,a=(0,d.default)(e.target).find(".dropdown-menu");let t=a.get(0);if(t){var r=a.outerHeight(),a=a.outerWidth(),o=(0,d.default)(s),n=(0,d.default)(window).height(),l=e.target.getBoundingClientRect().bottom,n=n-(l+r),n=n<0&&r<l-100;if(!o.hasClass("more")&&!o.hasClass("tab")){n?o.addClass("dropup"):o.removeClass("dropup");if(t.classList.contains("pull-right")&&s.getBoundingClientRect().left-a<0){l=s.getBoundingClientRect().right-s.getBoundingClientRect().width/2,o=(t.style.maxWidth=l+"px",(0,d.default)(s));o.one("hidden.bs.dropdown",()=>{t.style.maxWidth=""})}else{a=(0,d.default)(s).closest(".dashlet-body"),l=e.target.parentElement.classList.contains("fix-position");if(a.length||l){let e=a;l&&(e=(0,d.default)(window));(0,d.default)(s).removeClass("dropup");let t=(0,d.default)(s);o=s.getBoundingClientRect(),a=t.find(".dropdown-menu"),l=s.classList.contains("pull-right");let i=t.find(".dropdown-toggle");e.on("scroll.dd",()=>{if(t.hasClass("open")){i.dropdown("toggle");e.off("scroll.dd")}});t.one("hidden.bs.dropdown",()=>{e.off("scroll.dd")});l=l?o.left-a.outerWidth()+o.width:o.left,n=n?o.top-r:o.top+s.getBoundingClientRect().height;a.cs...
  Line 154:                 <span data-role="icon" class="{{iconClass}} {{#if reacted}} text-warning {{/if}}"></span>
  Line 158:     `;availableReactions;iconClassMap;popover;constructor(e){super(e)}data(){let t=this.model.attributes.reactionCounts||{},i=this.model.attributes.myReactions||[];return{dataList:this.availableReactions.filter(e=>t[e]).map(e=>({type:e,count:t[e].toString(),label:this.translate("Reactions")+" · "+this.translate(e,"reactions"),iconClass:this.iconClassMap[e],reacted:i.includes(e)}))}}setup(){var e=new i.default,e=(this.availableReactions=e.getAvailableReactions(),e.getDefinitionList());this.iconClassMap=e.reduce((e,t)=>{e[t.type]=t.iconClass;return e},{});this.addHandler("click","a.reaction-count",(e,t)=>this.showUsers(t.dataset.type))}async showUsers(e){var t=this.element.querySelector(`a.reaction-count[data-type="${e}"]`);let i=Espo.Ui.popover(t,{placement:"bottom",content:`
  Line 162:             `,preventDestroyOnRender:!0,noToggleInit:!0,keepElementTitle:!0,title:this.translate("Reactions")+" · "+this.translate(e,"reactions"),onHide:()=>{this.popover=void 0;this.trigger("popover-hidden")}},this);this.popover=i;var t=i.show(),t=(document.querySelector("#"+t).classList.add("popover-list-view"),`#${t} .popover-content`),s=document.querySelector(t),a=await this.getCollectionFactory().create("User");a.url=`Note/${this.model.id}/reactors/`+e;a.maxSize=this.getConfig().get("recordsPerPageSmall")||5;await a.fetch();if(document.body.contains(s)){s=new r.default({collection:a,listLayout:[{name:"name",view:"views/user/fields/name",link:!0}],checkboxes:!1,displayTotalCount:!1,headerDisabled:!0,buttonsDisabled:!0,rowActionsDisabled:!0});await this.assignView("users",s);s.setSelector(t);await s.render();this.listenToOnce(s,"modal-shown",()=>i.destroy())}else i.hide()}reRenderWhenNoPopover(){this.popover?this.once("popover-hidden",()=>this.reRender()):this.reRender()}}e.default=a});define("views/stream/panel",["exports","views/record/panels/relationship","underscore","views/note/fields/post","view-record-helper","views/fields/text"],function(e,t,i,s,a,r){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=o(t);i=o(i);s=o(s);a=o(a);r=o(r);function o(e){return e&&e.__esModule?e:{default:e}}class n extends t.default{template="stream/panel";rowActionsView="views/stream/record/row-actions/default";postingMode=!1;postDisabled=!1;relatedListFiltersDisabled=!0;layoutName=null;filterList=["all","posts","updates"];collection;_justPosted=!1;pinnedCollection;placeholderText;seed;additionalEvents={'focus textarea[data-name="post"]':function(){this.enablePostingMode(!0)},'keydown textarea[data-name="post"]':function(e){if("Control+Enter"===Espo.Utils.getKeyFromKeyEvent(e)){e.stopPropagation();e.preventDefault();this.post()}},'input textarea[data-name="post"]':function(){this.controlPreviewButton();this.controlPostButtonAvailability(this.postFieldView.getTex...
  Line 380:     `;messageName="userPostReaction";reactionIconClass;noteId;data(){return{...super.data(),reactionIconClass:this.reactionIconClass,noteId:this.noteId}}setup(){var e=this.model.attributes.data||{},t=this.model.attributes.relatedParentId,i=this.model.attributes.relatedParentType;this.userId=this.model.attributes.createdById||e.userId;this.noteId=this.model.attributes.relatedId;var s=e.userName||this.model.attributes.createdByName,a=(this.messageData.type=this.translate(e.type,"reactions"),new o.default),a=(this.reactionIconClass=a.getIconClass(e.type),document.createElement("a"));a.href="#User/view/"+this.model.attributes.createdById;a.dataset.id=this.model.attributes.createdById;a.dataset.scope="User";a.textContent=s;this.messageData.user=a;if(t&&i){s=document.createElement("a");s.href=`#${i}/view/`+t;s.dataset.id=t;s.dataset.scope=i;s.textContent=e.entityName||i;this.messageData.entityType=this.translateEntityType(i);this.messageData.entity=s;this.messageName="userPostInParentReaction"}let r=this.getLanguage().translateOption("Post","type","Note");this.toUpperCaseFirstLetter()||(r=Espo.Utils.lowerCaseFirst(r));a=document.createElement("a");a.href="#Note/view/"+this.noteId;a.dataset.id=this.noteId;a.dataset.scope="Note";a.textContent=r;this.messageData.post=a;this.createMessage()}}e.default=s});define("views/notification/items/system",["exports","views/notification/items/base"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{template="notification/items/system";data(){return{...super.data(),message:this.model.get("message")}}setup(){var e=this.model.get("data")||{};this.userId=e.userId}}e.default=s});define("views/notification/items/message",["exports","views/notification/items/base","dompurify"],function(e,t,i){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=s(t);i=s(i);function s(e){return e&&e.__esModule?e:{default:e}}class a extends t.defaul...
  Line 488:     `;baseName="created";getAttributeList(){return[this.fieldAt,this.fieldBy]}init(){this.baseName=this.options.baseName||this.baseName;this.fieldAt=this.baseName+"At";this.fieldBy=this.baseName+"By";super.init()}setup(){super.setup();this.createField("at");this.createField("by")}data(){var e=this.model.has(this.fieldBy+"Id"),t=this.model.has(this.fieldAt);let i=null;if(e){var s=this.model.get(this.fieldBy+"Id");s&&(i=this.getHelper().getAvatarHtml(s,"small",16,"avatar-link"))}return{baseName:this.baseName,hasBy:e,hasAt:t,hasBoth:t&&e,byUserAvatar:i,...super.data()}}createField(e){var t=this.baseName+Espo.Utils.upperCaseFirst(e),i=this.model.getFieldType(t)||"base",i=this.model.getFieldParam(t,"view")||this.getFieldManager().getViewName(i);this.createView(e+"Field",i,{name:t,model:this.model,mode:this.MODE_DETAIL,readOnly:!0,readOnlyLocked:!0,selector:'[data-name="'+t+'"]'})}fetch(){return{}}}e.default=s});define("views/fields/collaborators",["exports","views/fields/link-multiple"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{linkClass="text-default";init(){this.assignmentPermission=this.getAcl().getPermissionLevel("assignmentPermission");"no"===this.assignmentPermission&&(this.readOnly=!0);super.init()}getSelectBoolFilterList(){if("team"===this.assignmentPermission)return["onlyMyTeam"]}getSelectPrimaryFilterName(){return"active"}getDetailLinkHtml(e,t){var i=super.getDetailLinkHtml(e),s=this.isDetailMode()?this.getHelper().getAvatarHtml(e,"small",18,"avatar-link"):"";return s?""+s+i:i}getOnEmptyAutocomplete(){if(!this.params.autocompleteOnEmpty)return this.ids&&this.ids.includes(this.getUser().id)?Promise.resolve([]):Promise.resolve([{id:this.getUser().id,name:this.getUser().attributes.name}])}prepareEditItemElement(e,t){var i=super.prepareEditItemElement(e,t),s=this.getHelper().getAvatarHtml(e,"small",18,"avatar-link");if(s){var s=(new DOMParser).parseFromString...

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/record/detail.js:
  Line 351:                         "iconClass",

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/admin/currency.js:
  Line 64:                 iconClass: 'fas fa-euro-sign',

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/main.js:
  Line 70:      * @property {string} [iconClass] An icon class.

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/view-helper.js:
  Line 470:             const iconClass = options.hash.iconClass;
  Line 485:             } else if (iconClass) {
  Line 487:                     .addClass(iconClass)
  Line 540:             const iconClass = options.hash.iconClass;
  Line 555:             } else if (iconClass) {
  Line 557:                     .addClass(iconClass)
  Line 872:         const iconClass = this.metadata.get(["clientDefs", scope, "iconClass"]);
  Line 876:         if (color || iconClass) {
  Line 897:             if (iconClass) {
  Line 898:                 const $iconSpan = $("<span>").addClass(iconClass);

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/list.js:
  Line 173:                 iconClass: "fas fa-th",
  Line 180:                 iconClass: "fas fa-list",
  Line 244:                 iconClass: this.getMetadata().get([
  Line 247:                     "iconClass",

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/fields/link-multiple-with-icons.js:
  Line 48:         const iconClass = this.getMetadata().get(['clientDefs', entityType, 'iconClass']);
  Line 50:         if (!iconClass) {
  Line 57:         return `<span class="${iconClass}" style="${style}"></span>`;

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/fields/name-with-icon.js:
  Line 41:             iconClass: this.getIconClass(),
  Line 52:     getIconClass() {
  Line 54:         return this.getMetadata().get(['clientDefs', entityType, 'iconClass']) || 'fas fa-circle';

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/fields/entity-type.js:
  Line 40:             iconClass: this.getIconClass(),
  Line 49:     getIconClass() {
  Line 52:             this.getMetadata().get(["clientDefs", entityType, "iconClass"]) ||

/home/antl3x/repos/monostax/mono/components/crm/source/client/lib/ace.js:
  Line 3685:         var iconClassName = gutter.$useSvgGutterIcons ? "ace_icon_svg" : "ace_icon";
  Line 3689:             (_a = iconElement.classList).add.apply(_a, ["ace_".concat(annotation.type[i]), iconClassName]);
  Line 16738:         var iconClassName = this.$useSvgGutterIcons ? "ace_icon_svg" : "ace_icon";
  Line 16839:             annotationIconNode.className = iconClassName;
  Line 16862:             annotationIconNode.className = iconClassName;

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/stream/notes/email-received.js:
  Line 93:             emailIconClassName: this.getMetadata().get(['clientDefs', 'Email', 'iconClass']) || '',

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/stream/record/row-actions/default.js:
  Line 165:                     iconClass: this.reactionHelper.getIconClass(type),
  Line 202:             const iconClass = this.reactionHelper.getIconClass(type);
  Line 206:             let html = iconClass ?
  Line 207:                 `<span class="${iconClass} text-soft item-icon"></span><span class="item-text">${label}</span>` :

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/stream/record/row-actions/reactions/reactions.js:
  Line 47:                 ><span class="{{iconClass}}"></span></a>
  Line 55:      *         iconClass: string|null,

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/stream/reactions.js:
  Line 44:                 <span data-role="icon" class="{{iconClass}} {{#if reacted}} text-warning {{/if}}"></span>
  Line 60:     iconClassMap
  Line 91:                         iconClass: this.iconClassMap[type],
  Line 105:         this.iconClassMap = list.reduce((o, it) => {
  Line 106:             o[it.type] = it.iconClass;

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/stream/note.js:
  Line 250:         const iconClass = this.getMetadata().get(`clientDefs.${scope}.iconClass`);
  Line 253:         if (!iconClass) {
  Line 258:             .addClass(iconClass)

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/site/navbar.js:
  Line 1263:      *     iconClass: null
  Line 1269:         let iconClass = null;
  Line 1292:             iconClass = tab.iconClass;
  Line 1299:             iconClass = tab.iconClass;
  Line 1324:             iconClass = this.getMetadata().get(['clientDefs', tab, 'iconClass'])
  Line 1334:             iconClass: iconClass,
  Line 1351:         if (color && !iconClass) {

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/record/search.js:
  Line 320:                 iconClass: this.viewModeIconClassMap[item]

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/tab-list-field-add.js:
  Line 42:                 iconClass: 'fas fa-plus fa-sm',
  Line 51:             iconClass: 'fas fa-plus fa-sm',
  Line 59:             iconClass: 'fas fa-plus fa-sm',
  Line 67:             iconClass: null,
  Line 84:             iconClass: null,

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/edit-tab-url.js:
  Line 76:                             name: 'iconClass',
  Line 78:                                 this.translate('iconClass', 'tabFields', 'Preferences') :
  Line 79:                                 this.translate('iconClass', 'fields', 'EntityManager'),
  Line 111:                 iconClass: {

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/edit-tab-group.js:
  Line 69:                             name: 'iconClass',
  Line 71:                                 this.translate('iconClass', 'tabFields', 'Preferences') :
  Line 72:                                 this.translate('iconClass', 'fields', 'EntityManager'),
  Line 104:                 iconClass: {

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/fields/available-reactions.js:
  Line 39:     iconClassMap
  Line 50:         this.iconClassMap = this.reactionsHelper.getDefinitionList().reduce((o, it) => {
  Line 51:             o[it.type] = it.iconClass;
  Line 96:         (this.iconClassMap[value] || '')

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/modals/select-records.js:
  Line 211:                 iconClass: 'fas fa-plus fa-sm',

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/notification/items/base.js:
  Line 138:         const iconClass = this.getMetadata().get(`clientDefs.${entityType}.iconClass`);
  Line 141:         if (!iconClass) {
  Line 146:         span.className = `action text-muted icon ${iconClass}`;

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/notification/items/user-reaction.js:
  Line 94:         this.reactionIconClass = reactionsHelper.getIconClass(data.type);

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/modal.js:
  Line 65:      * @property {string} [iconClass] An icon class.
  Line 518:             else if (o.iconClass && !o.html) {
  Line 519:                 o.html = `<span class="${o.iconClass}"></span>` +

(Results truncated: showing 100 of 125 matches (25 hidden). Consider using a more specific path or pattern.)
```

**Tool: glob**

**Input:**
```json
{
  "pattern": "**/SavedFilter*"
}
```

**Output:**
```
No files found
```

---

## Assistant (Scope · glm-5-free · 7.9s)

_Thinking:_

Let me look for SavedSearch and SearchFolder - these are related to saved filters in EspoCRM.

**Tool: glob**

**Input:**
```json
{
  "pattern": "**/SavedSearch*"
}
```

**Output:**
```
No files found
```

**Tool: grep**

**Input:**
```json
{
  "include": "*.json",
  "pattern": "\"SavedSearch\"|'SavedSearch'"
}
```

**Output:**
```
No files found
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/tab-list-field-add.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/tab-list-field-add.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of EspoCRM.
3:  *
4:  * EspoCRM – Open Source CRM application.
5:  * Copyright (C) 2014-2026 EspoCRM, Inc.
6:  * Website: https://www.espocrm.com
7:  *
8:  * This program is free software: you can redistribute it and/or modify
9:  * it under the terms of the GNU Affero General Public License as published by
10:  * the Free Software Foundation, either version 3 of the License, or
11:  * (at your option) any later version.
12:  *
13:  * This program is distributed in the hope that it will be useful,
14:  * but WITHOUT ANY WARRANTY; without even the implied warranty of
15:  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
16:  * GNU Affero General Public License for more details.
17:  *
18:  * You should have received a copy of the GNU Affero General Public License
19:  * along with this program. If not, see <https://www.gnu.org/licenses/>.
20:  *
21:  * The interactive user interfaces in modified source and object code versions
22:  * of this program must display Appropriate Legal Notices, as required under
23:  * Section 5 of the GNU Affero General Public License version 3.
24:  *
25:  * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
26:  * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
27:  ************************************************************************/
28: 
29: import ArrayFieldAddModalView from 'views/modals/array-field-add';
30: 
31: class TabListFieldAddSettingsModalView extends ArrayFieldAddModalView {
32: 
33:     setup() {
34:         super.setup();
35: 
36:         if (!this.options.noGroups) {
37:             this.buttonList.push({
38:                 name: 'addGroup',
39:                 text: this.translate('Group Tab', 'labels', 'Settings'),
40:                 onClick: () => this.actionAddGroup(),
41:                 position: 'right',
42:                 iconClass: 'fas fa-plus fa-sm',
43:             });
44:         }
45: 
46:         this.buttonList.push({
47:             name: 'addDivider',
48:             text: this.translate('Divider', 'labels', 'Settings'),
49:             onClick: () => this.actionAddDivider(),
50:             position: 'right',
51:             iconClass: 'fas fa-plus fa-sm',
52:         });
53: 
54:         this.addButton({
55:             name: 'addUrl',
56:             text: this.translate('URL', 'labels', 'Settings'),
57:             onClick: () => this.actionAddUrl(),
58:             position: 'right',
59:             iconClass: 'fas fa-plus fa-sm',
60:         });
61:     }
62: 
63:     actionAddGroup() {
64:         this.trigger('add', {
65:             type: 'group',
66:             text: this.translate('Group Tab', 'labels', 'Settings'),
67:             iconClass: null,
68:             color: null,
69:         });
70:     }
71: 
72:     actionAddDivider() {
73:         this.trigger('add', {
74:             type: 'divider',
75:             text: null,
76:         });
77:     }
78: 
79:     actionAddUrl() {
80:         this.trigger('add', {
81:             type: 'url',
82:             text: this.translate('URL', 'labels', 'Settings'),
83:             url: null,
84:             iconClass: null,
85:             color: null,
86:             aclScope: null,
87:             onlyAdmin: false,
88:         });
89:     }
90: }
91: 
92: // noinspection JSUnusedGlobalSymbols
93: export default TabListFieldAddSettingsModalView;
94: 

(End of file - total 94 lines)
</content>
```

---

## Assistant (Scope · glm-5-free · 30.5s)

_Thinking:_

Now I understand how the tab-list system works. The key points are:

1. Tab list items can be:
   - A string (scope name like "Accounts")
   - An object with type "group", "url", or "divider"
   - A delimiter string like "_delimiter_"

2. The `tab-list-field-add.js` shows how to add new types like addGroup, addDivider, addUrl

3. The user wants a new type called "virtualFolder" (or something similar) that:
   - Uses saved filters to group record items
   - Shows entity icon + name
   - Has collapse/expand functionality
   - On hover, shows more options menu and quick create button
   - Has a custom "label" that serves as the divider text

Let me now look at the existing Global module navbar-config-field-add.js and the SidenavConfig entity to understand how this should integrate.

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/modals/navbar-config-field-add.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/modals/navbar-config-field-add.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of Monostax.
3:  *
4:  * Monostax – Custom EspoCRM extensions.
5:  * Copyright (C) 2025 Antonio Moura. All rights reserved.
6:  * Website: https://www.monostax.ai
7:  *
8:  * PROPRIETARY AND CONFIDENTIAL
9:  ************************************************************************/
10: 
11: import Modal from 'views/modal';
12: import Model from 'model';
13: 
14: class NavbarConfigFieldAddModalView extends Modal {
15: 
16:     className = 'dialog dialog-record'
17: 
18:     templateContent = `<div class="record no-side-margin">{{{record}}}</div>`
19: 
20:     setup() {
21:         super.setup();
22: 
23:         this.headerText = this.translate('Add Navbar Configuration', 'labels', 'Settings');
24: 
25:         this.buttonList.push({
26:             name: 'add',
27:             label: 'Add',
28:             style: 'danger',
29:         });
30: 
31:         this.buttonList.push({
32:             name: 'cancel',
33:             label: 'Cancel',
34:         });
35: 
36:         this.shortcutKeys = {
37:             'Control+Enter': () => this.actionAdd(),
38:         };
39: 
40:         const detailLayout = [
41:             {
42:                 rows: [
43:                     [
44:                         {
45:                             name: 'name',
46:                             labelText: this.translate('name', 'fields'),
47:                         },
48:                         {
49:                             name: 'iconClass',
50:                             labelText: this.translate('iconClass', 'fields', 'EntityManager'),
51:                         },
52:                     ],
53:                     [
54:                         {
55:                             name: 'color',
56:                             labelText: this.translate('color', 'fields', 'EntityManager'),
57:                         },
58:                         false,
59:                     ],
60:                 ],
61:             },
62:         ];
63: 
64:         const model = this.model = new Model();
65: 
66:         model.name = 'NavbarConfig';
67: 
68:         model.setDefs({
69:             fields: {
70:                 name: {
71:                     type: 'varchar',
72:                     required: true,
73:                 },
74:                 iconClass: {
75:                     type: 'base',
76:                     view: 'views/admin/entity-manager/fields/icon-class',
77:                 },
78:                 color: {
79:                     type: 'base',
80:                     view: 'views/fields/colorpicker',
81:                 },
82:             },
83:         });
84: 
85:         this.createView('record', 'views/record/edit-for-modal', {
86:             detailLayout: detailLayout,
87:             model: model,
88:             selector: '.record',
89:         });
90:     }
91: 
92:     actionAdd() {
93:         const recordView = this.getView('record');
94: 
95:         if (recordView.validate()) {
96:             return;
97:         }
98: 
99:         const data = recordView.fetch();
100: 
101:         this.trigger('add', data);
102:         this.close();
103:     }
104: }
105: 
106: export default NavbarConfigFieldAddModalView;
107: 

(End of file - total 107 lines)
</content>
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json</path>
<type>file</type>
<content>1: {
2:     "fields": {
3:         "name": {
4:             "type": "varchar",
5:             "required": true,
6:             "maxLength": 255,
7:             "trim": true
8:         },
9:         "order": {
10:             "type": "int",
11:             "default": 10,
12:             "min": 1,
13:             "tooltip": true
14:         },
15:         "iconClass": {
16:             "type": "varchar",
17:             "maxLength": 100
18:         },
19:         "color": {
20:             "type": "varchar",
21:             "maxLength": 7
22:         },
23:         "tabList": {
24:             "type": "jsonArray",
25:             "view": "views/settings/fields/tab-list"
26:         },
27:         "isDefault": {
28:             "type": "bool",
29:             "default": false,
30:             "tooltip": true
31:         },
32:         "isDisabled": {
33:             "type": "bool",
34:             "default": false,
35:             "tooltip": true
36:         },
37:         "createdAt": {
38:             "type": "datetime",
39:             "readOnly": true
40:         },
41:         "modifiedAt": {
42:             "type": "datetime",
43:             "readOnly": true
44:         },
45:         "createdBy": {
46:             "type": "link",
47:             "readOnly": true,
48:             "view": "views/fields/user"
49:         },
50:         "modifiedBy": {
51:             "type": "link",
52:             "readOnly": true,
53:             "view": "views/fields/user"
54:         },
55:         "teams": {
56:             "type": "linkMultiple",
57:             "view": "views/fields/teams"
58:         }
59:     },
60:     "links": {
61:         "teams": {
62:             "type": "hasMany",
63:             "entity": "Team",
64:             "relationName": "entityTeam",
65:             "layoutRelationshipsDisabled": true
66:         },
67:         "createdBy": {
68:             "type": "belongsTo",
69:             "entity": "User"
70:         },
71:         "modifiedBy": {
72:             "type": "belongsTo",
73:             "entity": "User"
74:         }
75:     },
76:     "collection": {
77:         "orderBy": "order",
78:         "order": "asc"
79:     },
80:     "indexes": {
81:         "order": {
82:             "columns": ["order"]
83:         }
84:     }
85: }
86: 

(End of file - total 86 lines)
</content>
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/site/navbar.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/site/navbar.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of EspoCRM.
3:  *
4:  * EspoCRM – Open Source CRM application.
5:  * Copyright (C) 2014-2026 EspoCRM, Inc.
6:  * Website: https://www.espocrm.com
7:  *
8:  * This program is free software: you can redistribute it and/or modify
9:  * it under the terms of the GNU Affero General Public License as published by
10:  * the Free Software Foundation, either version 3 of the License, or
11:  * (at your option) any later version.
12:  *
13:  * This program is distributed in the hope that it will be useful,
14:  * but WITHOUT ANY WARRANTY; without even the implied warranty of
15:  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
16:  * GNU Affero General Public License for more details.
17:  *
18:  * You should have received a copy of the GNU Affero General Public License
19:  * along with this program. If not, see <https://www.gnu.org/licenses/>.
20:  *
21:  * The interactive user interfaces in modified source and object code versions
22:  * of this program must display Appropriate Legal Notices, as required under
23:  * Section 5 of the GNU Affero General Public License version 3.
24:  *
25:  * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
26:  * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
27:  ************************************************************************/
28: 
29: import View from 'view';
30: import $ from 'jquery';
31: import TabsHelper from 'helpers/site/tabs';
32: 
33: class NavbarSiteView extends View {
34: 
35:     template = 'site/navbar'
36: 
37:     /**
38:      * @private
39:      * @type {string|false|null}
40:      */
41:     currentTab = null
42: 
43:     /**
44:      * @private
45:      * @type {HTMLElement}
46:      */
47:     navbarHeaderElement
48: 
49:     events = {
50:         /** @this NavbarSiteView */
51:         'click .navbar-collapse.in a.nav-link': function (e) {
52:             const $a = $(e.currentTarget);
53:             const href = $a.attr('href');
54: 
55:             if (href) {
56:                 this.xsCollapse();
57:             }
58:         },
59:         /** @this NavbarSiteView */
60:         'click a.nav-link': function () {
61:             if (this.isSideMenuOpened) {
62:                 this.closeSideMenu();
63:             }
64:         },
65:         /** @this NavbarSiteView */
66:         'click a.navbar-brand.nav-link': function () {
67:             this.xsCollapse();
68:         },
69:         /** @this NavbarSiteView */
70:         'click a.minimizer': function () {
71:             this.switchMinimizer();
72:         },
73:         /** @this NavbarSiteView */
74:         'click a.side-menu-button': function () {
75:             this.switchSideMenu();
76:         },
77:         /** @this NavbarSiteView */
78:         'click [data-action="toggleCollapsable"]': function () {
79:             this.toggleCollapsable();
80:         },
81:         /** @this NavbarSiteView */
82:         'click li.show-more a': function (e) {
83:             e.stopPropagation();
84:             this.showMoreTabs();
85:         },
86:         /** @this NavbarSiteView */
87:         'click .not-in-more > .nav-link-group': function (e) {
88:             this.handleGroupDropdownClick(e);
89:         },
90:         /** @this NavbarSiteView */
91:         'click .in-more .nav-link-group': function (e) {
92:             this.handleGroupDropdownClick(e);
93:         },
94:     }
95: 
96:     data() {
97:         return {
98:             tabDefsList1: this.tabDefsList.filter(item => !item.isInMore),
99:             tabDefsList2: this.tabDefsList.filter(item => item.isInMore),
100:             title: this.options.title,
101:             menuDataList: this.menuDataList,
102:             userId: this.getUser().id,
103:             logoSrc: this.getLogoSrc(),
104:             itemDataList: this.getItemDataList(),
105:         };
106:     }
107: 
108:     /**
109:      * @private
110:      */
111:     handleGroupDropdownClick(e) {
112:         const $target = $(e.currentTarget).parent();
113: 
114:         if ($target.parent().hasClass('more-dropdown-menu')) {
115:             e.stopPropagation();
116: 
117:             if ($target.hasClass('open')) {
118:                 $target.removeClass('open');
119: 
120:                 return;
121:             }
122: 
123:             this.handleGroupDropdownInMoreOpen($target);
124: 
125:             return;
126:         }
127: 
128:         if ($target.hasClass('open')) {
129:             return;
130:         }
131: 
132:         this.handleGroupDropdownOpen($target);
133:     }
134: 
135:     /**
136:      * @private
137:      */
138:     handleGroupMenuPosition($menu, $target) {
139:         if (this.navbarAdjustmentHandler && this.navbarAdjustmentHandler.handleGroupMenuPosition()) {
140:             this.handleGroupMenuPosition($menu, $target);
141: 
142:             return;
143:         }
144: 
145:         const rectItem = $target.get(0).getBoundingClientRect();
146: 
147:         const windowHeight = window.innerHeight;
148: 
149:         const isSide = this.isSide();
150: 
151:         if (
152:             !isSide &&
153:             !$target.parent().hasClass('more-dropdown-menu')
154:         ) {
155:             const maxHeight = windowHeight - rectItem.bottom;
156: 
157:             this.handleGroupMenuScrolling($menu, $target, maxHeight);
158: 
159:             return;
160:         }
161: 
162:         const itemCount = $menu.children().length;
163: 
164:         const tabHeight = isSide ?
165:             this.$tabs.find('> .tab:not(.tab-divider)').height() :
166:             this.$tabs.find('.tab-group > ul > li:visible').height();
167: 
168:         const menuHeight = tabHeight * itemCount;
169: 
170:         let top = rectItem.top - 1;
171: 
172:         if (top + menuHeight > windowHeight) {
173:             top = windowHeight - menuHeight - 2;
174: 
175:             if (top < 0) {
176:                 top = 0;
177:             }
178:         }
179: 
180:         $menu.css({top: top + 'px'});
181: 
182:         const maxHeight = windowHeight - top;
183: 
184:         this.handleGroupMenuScrolling($menu, $target, maxHeight);
185:     }
186: 
187:     /**
188:      * @private
189:      */
190:     handleGroupMenuScrolling($menu, $target, maxHeight) {
191:         $menu.css({
192:             maxHeight: maxHeight + 'px',
193:         });
194: 
195:         const $window = $(window);
196: 
197:         $window.off('scroll.navbar-tab-group');
198: 
199:         $window.on('scroll.navbar-tab-group', () => {
200:             if (!$menu.get(0) || !$target.get(0)) {
201:                 return;
202:             }
203: 
204:             if (!$target.hasClass('open')) {
205:                 return;
206:             }
207: 
208:             $menu.scrollTop($window.scrollTop());
209:         });
210:     }
211: 
212:     /**
213:      * @private
214:      */
215:     handleGroupDropdownOpen($target) {
216:         const $menu = $target.find('.dropdown-menu');
217: 
218:         this.handleGroupMenuPosition($menu, $target);
219: 
220:         setTimeout(() => {
221:             this.adjustBodyMinHeight();
222:         }, 50);
223: 
224:         $target.off('hidden.bs.dropdown');
225: 
226:         $target.on('hidden.bs.dropdown', () => {
227:             this.adjustBodyMinHeight();
228:         });
229:     }
230: 
231:     /**
232:      * @private
233:      */
234:     handleGroupDropdownInMoreOpen($target) {
235:         this.$el.find('.tab-group.tab.dropdown').removeClass('open');
236: 
237:         const $parentDropdown = this.$el.find('.more-dropdown-menu');
238: 
239:         $target.addClass('open');
240: 
241:         const $menu = $target.find('.dropdown-menu');
242: 
243:         const rectDropdown = $parentDropdown.get(0).getBoundingClientRect();
244: 
245:         const left = rectDropdown.right;
246: 
247:         $menu.css({
248:             left: left + 'px',
249:         });
250: 
251:         this.handleGroupMenuPosition($menu, $target);
252: 
253:         this.adjustBodyMinHeight();
254: 
255:         if (!this.isSide()) {
256:             if (left + $menu.width() > window.innerWidth) {
257:                 $menu.css({
258:                     left: rectDropdown.left - $menu.width() - 2,
259:                 });
260:             }
261:         }
262:     }
263: 
264:     /**
265:      * @private
266:      */
267:     isCollapsibleVisible() {
268:         return this.$el.find('.navbar-body').hasClass('in');
269:     }
270: 
271:     /**
272:      * @private
273:      */
274:     toggleCollapsable() {
275:         if (this.isCollapsibleVisible()) {
276:             this.hideCollapsable();
277:         } else {
278:             this.showCollapsable();
279:         }
280:     }
281: 
282:     /**
283:      * @private
284:      */
285:     hideCollapsable() {
286:         this.$el.find('.navbar-body').removeClass('in');
287:     }
288: 
289:     /**
290:      * @private
291:      */
292:     showCollapsable() {
293:         this.$el.find('.navbar-body').addClass('in');
294:     }
295: 
296:     /**
297:      * @private
298:      */
299:     xsCollapse() {
300:         this.hideCollapsable();
301:     }
302: 
303:     /**
304:      * @private
305:      * @return {boolean}
306:      */
307:     isMinimized() {
308:         return document.body.classList.contains('minimized');
309:     }
310: 
311:     switchSideMenu() {
312:         if (!this.isMinimized()) return;
313: 
314:         if (this.isSideMenuOpened) {
315:             this.closeSideMenu();
316:         } else {
317:             this.openSideMenu();
318:         }
319:     }
320: 
321:     openSideMenu() {
322:         this.isSideMenuOpened = true;
323: 
324:         document.body.classList.add('side-menu-opened');
325: 
326:         this.$sideMenuBackdrop =
327:             $('<div>')
328:                 .addClass('side-menu-backdrop')
329:                 .click(() => this.closeSideMenu())
330:                 .appendTo(document.body);
331: 
332:         this.$sideMenuBackdrop2 =
333:             $('<div>')
334:                 .addClass('side-menu-backdrop')
335:                 .click(() => this.closeSideMenu())
336:                 .appendTo(this.$navbarRightContainer);
337:     }
338: 
339:     /**
340:      * @private
341:      */
342:     closeSideMenu() {
343:         this.isSideMenuOpened = false;
344: 
345:         document.body.classList.remove('side-menu-opened')
346: 
347:         this.$sideMenuBackdrop.remove();
348:         this.$sideMenuBackdrop2.remove();
349:     }
350: 
351:     /**
352:      * @private
353:      */
354:     switchMinimizer() {
355:         if (this.isMinimized()) {
356:             if (this.isSideMenuOpened) {
357:                 this.closeSideMenu();
358:             }
359: 
360:             document.body.classList.remove('minimized');
361: 
362:             this.getStorage().set('state', 'siteLayoutState', 'expanded');
363:         }  else {
364:             document.body.classList.add('minimized');
365: 
366:             this.getStorage().set('state', 'siteLayoutState', 'collapsed');
367:         }
368: 
369:         if (window.Event) {
370:             try {
371:                 window.dispatchEvent(new Event('resize'));
372:             } catch (e) {}
373:         }
374:     }
375: 
376:     getLogoSrc() {
377:         const companyLogoId = this.getConfig().get('companyLogoId');
378: 
379:         if (!companyLogoId) {
380:             return this.getBasePath() + (this.getThemeManager().getParam('logo') || 'client/img/logo.svg');
381:         }
382: 
383:         return `${this.getBasePath()}?entryPoint=LogoImage&id=${companyLogoId}`;
384:     }
385: 
386:     /**
387:      * @return {(Object|string)[]}
388:      */
389:     getTabList() {
390:         const tabList = this.tabsHelper.getTabList();
391: 
392:         if (this.isSide()) {
393:             tabList.unshift('Home');
394:         }
395: 
396:         return tabList;
397:     }
398: 
399:     setup() {
400:         this.addHandler('click', 'a.action', (/** MouseEvent */event, target) => {
401:             let actionData;
402:             const name = target.dataset.name;
403: 
404:             if (name) {
405:                 const item = this.menuDataList.find(it => it.name === name);
406: 
407:                 if (item.handler && item.actionFunction) {
408:                     actionData = {
409:                         handler: item.handler,
410:                         actionFunction: item.actionFunction,
411:                     };
412:                 }
413:             }
414: 
415:             Espo.Utils.handleAction(this, event, target, actionData);
416:         });
417: 
418:         this.getRouter().on('routed', (e) => {
419:             if (e.controller) {
420:                 this.selectTab(e.controller);
421: 
422:                 return;
423:             }
424: 
425:             this.selectTab(false);
426:         });
427: 
428:         /** @private */
429:         this.tabsHelper = new TabsHelper(
430:             this.getConfig(),
431:             this.getPreferences(),
432:             this.getUser(),
433:             this.getAcl(),
434:             this.getMetadata(),
435:             this.getLanguage()
436:         );
437: 
438:         const itemDefs = this.getMetadata().get('app.clientNavbar.items') || {};
439: 
440:         /** @type {string[]} */
441:         this.itemList = Object.keys(itemDefs)
442:             .filter(name => !itemDefs[name].disabled)
443:             .sort((name1, name2) => {
444:                 const order1 = itemDefs[name1].order || 0;
445:                 const order2 = itemDefs[name2].order || 0;
446: 
447:                 return order1 - order2;
448:             });
449: 
450:         const setup = () => {
451:             this.setupTabDefsList();
452: 
453:             return Promise
454:                 .all(this.itemList.map(item => this.createItemView(item)));
455:         };
456: 
457:         const update = () => {
458:             setup().then(() => this.reRender());
459:         };
460: 
461:         setup();
462: 
463:         this.listenTo(this.getHelper().settings, 'sync', () => update());
464:         this.listenTo(this.getHelper().language, 'sync', () => update());
465: 
466:         this.listenTo(this.getHelper().preferences, 'update', (/** string[] */attributeList) => {
467:             if (!attributeList) {
468:                 return;
469:             }
470: 
471:             if (
472:                 attributeList.includes('tabList') ||
473:                 attributeList.includes('addCustomTabs') ||
474:                 attributeList.includes('useCustomTabList')
475:             ) {
476:                 update();
477:             }
478:         });
479: 
480: 
481:         this.once('remove', () => {
482:             $(window).off('resize.navbar');
483:             $(window).off('scroll.navbar');
484:             $(window).off('scroll.navbar-tab-group');
485: 
486:             document.body.classList.remove('has-navbar');
487:         });
488: 
489:         this.setupMenu();
490:     }
491: 
492:     getItemDataList() {
493:         const defsMap = {};
494: 
495:         this.itemList.forEach(name => {
496:             defsMap[name] = this.getItemDefs(name);
497:         });
498: 
499:         return this.itemList
500:             .filter(name => {
501:                 const item = defsMap[name];
502: 
503:                 if (!item) {
504:                     return false;
505:                 }
506: 
507:                 if (
508:                     item.accessDataList &&
509:                     !Espo.Utils.checkAccessDataList(item.accessDataList, this.getAcl(), this.getUser())
510:                 ) {
511:                     return false;
512:                 }
513: 
514:                 const view = this.getView(name + 'Item');
515: 
516:                 if ('isAvailable' in view) {
517:                     return view.isAvailable();
518:                 }
519: 
520:                 return true;
521:             })
522:             .map(name => {
523:                 return {
524:                     key: name + 'Item',
525:                     name: name,
526:                     class: defsMap[name].class || '',
527:                 };
528:             });
529:     }
530: 
531:     /**
532:      *
533:      * @param {string} name
534:      * @return {{
535:      *     view: string,
536:      *     class: string,
537:      *     accessDataList?: module:utils~AccessDefs[],
538:      * }}
539:      */
540:     getItemDefs(name) {
541:         return this.getMetadata().get(['app', 'clientNavbar', 'items', name]);
542:     }
543: 
544:     /**
545:      * @param {string} name
546:      * @return {Promise}
547:      */
548:     createItemView(name) {
549:         const defs = this.getItemDefs(name)
550: 
551:         if (!defs || !defs.view) {
552:             return Promise.resolve();
553:         }
554: 
555:         const key = name + 'Item';
556: 
557:         return this.createView(key, defs.view, {selector: `[data-item="${name}"]`});
558:     }
559: 
560:     /**
561:      * @private
562:      */
563:     adjustTop() {
564:         const smallScreenWidth = this.getThemeManager().getParam('screenWidthXs');
565:         const navbarHeight = this.getNavbarHeight();
566: 
567:         const $window = $(window);
568: 
569:         const $tabs = this.$tabs;
570:         const $more = this.$more;
571:         const $moreDropdown = this.$moreDropdown;
572: 
573: 
574:         $window.off('scroll.navbar');
575:         $window.off('resize.navbar');
576:         this.$moreDropdown.off('shown.bs.dropdown.navbar');
577:         this.off('show-more-tabs');
578: 
579:         $window.on('resize.navbar', () => updateWidth());
580: 
581:         $window.on('scroll.navbar', () => {
582:             if (!this.isMoreDropdownShown) {
583:                 return;
584:             }
585: 
586:             $more.scrollTop($window.scrollTop());
587:         });
588: 
589:         this.$moreDropdown.on('shown.bs.dropdown.navbar', () => {
590:             $more.scrollTop($window.scrollTop());
591:         });
592: 
593:         this.on('show-more-tabs', () => {
594:             $more.scrollTop($window.scrollTop());
595:         });
596: 
597:         const updateMoreHeight = () => {
598:             const windowHeight = window.innerHeight;
599:             const windowWidth = window.innerWidth;
600: 
601:             if (windowWidth < smallScreenWidth) {
602:                 $more.css('max-height', '');
603:                 $more.css('overflow-y', '');
604:             } else {
605:                 $more.css('overflow-y', 'hidden');
606:                 $more.css('max-height', (windowHeight - navbarHeight) + 'px');
607:             }
608:         };
609: 
610:         $window.on('resize.navbar', () => updateMoreHeight());
611: 
612:         updateMoreHeight();
613: 
614:         const hideOneTab = () => {
615:             const count = $tabs.children().length;
616: 
617:             if (count <= 1) {
618:                 return;
619:             }
620: 
621:             const $one = $tabs.children().eq(count - 2);
622: 
623:             $one.prependTo($more);
624:         };
625: 
626:         const unhideOneTab = () => {
627:             const $one = $more.children().eq(0);
628: 
629:             if ($one.length) {
630:                 $one.insertBefore($moreDropdown);
631:             }
632:         };
633: 
634:         const $navbar = $('#navbar .navbar');
635: 
636:         if (window.innerWidth >= smallScreenWidth) {
637:             $tabs.children('li').each(() => {
638:                 hideOneTab();
639:             });
640: 
641:             $navbar.css('max-height', 'unset');
642:             $navbar.css('overflow', 'visible');
643:         }
644: 
645:         const navbarBaseWidth = this.navbarHeaderElement.clientWidth + this.$navbarRight.width();
646: 
647:         const tabCount = this.tabList.length;
648: 
649:         const navbarNeededHeight = navbarHeight + 1;
650: 
651:         this.adjustBodyMinHeightMethodName = 'adjustBodyMinHeightTop';
652: 
653:         const $moreDd = $('#nav-more-tabs-dropdown');
654:         const $moreLi = $moreDd.closest('li');
655: 
656:         const updateWidth = () => {
657:             const windowWidth = window.innerWidth;
658:             const moreWidth = $moreLi.width();
659: 
660:             $more.children('li.not-in-more').each(() => {
661:                 unhideOneTab();
662:             });
663: 
664:             if (windowWidth < smallScreenWidth) {
665:                 return;
666:             }
667: 
668:             $navbar.css('max-height', navbarHeight + 'px');
669:             $navbar.css('overflow', 'hidden');
670: 
671:             $more.parent().addClass('hidden');
672: 
673:             const headerWidth = this.$el.width();
674: 
675:             const maxWidth = headerWidth - navbarBaseWidth - moreWidth;
676:             let width = $tabs.width();
677: 
678:             let i = 0;
679: 
680:             while (width > maxWidth) {
681:                 hideOneTab();
682:                 width = $tabs.width();
683:                 i++;
684: 
685:                 if (i >= tabCount) {
686:                     setTimeout(() => updateWidth(), 100);
687: 
688:                     break;
689:                 }
690:             }
691: 
692:             $navbar.css('max-height', 'unset');
693:             $navbar.css('overflow', 'visible');
694: 
695:             if ($more.children().length > 0) {
696:                 $moreDropdown.removeClass('hidden');
697:             }
698:         };
699: 
700:         const processUpdateWidth = isRecursive => {
701:             if ($navbar.height() > navbarNeededHeight) {
702:                 updateWidth();
703:                 setTimeout(() => processUpdateWidth(true), 200);
704: 
705:                 return;
706:             }
707: 
708:             if (!isRecursive) {
709:                 updateWidth();
710:                 setTimeout(() => processUpdateWidth(true), 10);
711:             }
712: 
713:             setTimeout(() => processUpdateWidth(true), 1000);
714:         };
715: 
716:         if ($navbar.height() <= navbarNeededHeight && $more.children().length === 0) {
717:             $more.parent().addClass('hidden');
718:         }
719: 
720:         processUpdateWidth();
721:     }
722: 
723:     /**
724:      * @private
725:      */
726:     adjustSide() {
727:         const smallScreenWidth = this.getThemeManager().getParam('screenWidthXs');
728: 
729:         const $window = $(window);
730:         const $tabs = this.$tabs;
731:         const $more = this.$more;
732: 
733:         /** @type {HTMLElement} */
734:         const tabsElement = this.$tabs.get(0);
735: 
736:         /** @type {HTMLElement} */
737:         const moreElement = this.$more.get(0);
738: 
739:         this.adjustBodyMinHeightMethodName = 'adjustBodyMinHeightSide';
740: 
741:         if ($more.children().length === 0) {
742:             $more.parent().addClass('hidden');
743:         }
744: 
745:         $window.off('scroll.navbar');
746:         $window.off('resize.navbar');
747:         this.$moreDropdown.off('shown.bs.dropdown.navbar');
748:         this.off('show-more-tabs');
749: 
750:         $window.on('scroll.navbar', () => {
751:             $window.scrollTop() ?
752:                 this.$navbarRight.addClass('shadowed') :
753:                 this.$navbarRight.removeClass('shadowed');
754: 
755:             $tabs.scrollTop($window.scrollTop());
756: 
757:             if (!this.isMoreDropdownShown) {
758:                 return;
759:             }
760: 
761:             $more.scrollTop($window.scrollTop());
762:         });
763: 
764:         this.$moreDropdown.on('shown.bs.dropdown.navbar', () => {
765:             $more.scrollTop($window.scrollTop());
766:         });
767: 
768:         this.on('show-more-tabs', () => {
769:             $more.scrollTop($window.scrollTop());
770:         });
771: 
772:         const updateSizeForSide = () => {
773:             const windowHeight = window.innerHeight;
774:             const windowWidth = window.innerWidth;
775: 
776:             const navbarStaticItemsHeight = this.getStaticItemsHeight();
777: 
778:             this.$minimizer.removeClass('hidden');
779: 
780:             if (windowWidth < smallScreenWidth) {
781:                 tabsElement.style.height = 'auto';
782: 
783:                 if (moreElement) {
784:                     moreElement.style.maxHeight = '';
785:                 }
786: 
787:                 return;
788:             }
789: 
790:             tabsElement.style.height = (windowHeight - navbarStaticItemsHeight) + 'px';
791: 
792:             if (moreElement) {
793:                 moreElement.style.maxHeight = windowHeight + 'px';
794:             }
795:         };
796: 
797:         $window.on('resize.navbar', () => {
798:             updateSizeForSide();
799:             this.adjustBodyMinHeight();
800:         });
801: 
802:         updateSizeForSide();
803:         this.adjustBodyMinHeight();
804:     }
805: 
806:     /**
807:      * @private
808:      * @return {number}
809:      */
810:     getNavbarHeight() {
811:         return this.getFontSizeFactor() * (this.getThemeManager().getParam('navbarHeight') || 43);
812:     }
813: 
814:     /**
815:      * @private
816:      * @return {boolean}
817:      */
818:     isSide() {
819:         return this.getThemeManager().getParam('navbar') === 'side';
820:     }
821: 
822:     /**
823:      * @private
824:      * @return {number}
825:      */
826:     getStaticItemsHeight() {
827:         return this.getFontSizeFactor() * (this.getThemeManager().getParam('navbarStaticItemsHeight') || 97);
828:     }
829: 
830:     /**
831:      * @private
832:      */
833:     getFontSizeFactor() {
834:         return this.getThemeManager().getFontSizeFactor();
835:     }
836: 
837:     /**
838:      * @private
839:      */
840:     adjustBodyMinHeight() {
841:         if (!this.adjustBodyMinHeightMethodName) {
842:             return;
843:         }
844: 
845:         this[this.adjustBodyMinHeightMethodName]();
846:     }
847: 
848:     /**
849:      * @private
850:      */
851:     adjustBodyMinHeightSide() {
852:         let minHeight = this.$tabs.get(0).scrollHeight + this.getStaticItemsHeight();
853: 
854:         let moreHeight = 0;
855: 
856:         this.$more.find('> li:visible').each((i, el) => {
857:             const $el = $(el);
858: 
859:             moreHeight += $el.outerHeight(true);
860:         });
861: 
862:         minHeight = Math.max(minHeight, moreHeight);
863: 
864:         const tabHeight = this.$tabs.find('> .tab:not(.tab-divider)').height();
865: 
866:         this.tabList.forEach((item, i) => {
867:             if (typeof item !== 'object') {
868:                 return;
869:             }
870: 
871:             const $li = this.$el.find('li.tab[data-name="group-' + i + '"]');
872: 
873:             if (!$li.hasClass('open')) {
874:                 return;
875:             }
876: 
877:             const tabCount = (item.itemList || []).length;
878: 
879:             const menuHeight = tabHeight * tabCount;
880: 
881:             if (menuHeight > minHeight) {
882:                 minHeight = menuHeight;
883:             }
884:         });
885: 
886:         document.body.style.minHeight = minHeight + 'px';
887:     }
888: 
889:     /**
890:      * @private
891:      */
892:     adjustBodyMinHeightTop() {
893:         let minHeight = this.getNavbarHeight();
894: 
895:         this.$more.find('> li').each((i, el) => {
896:             const $el = $(el);
897: 
898:             if (!this.isMoreTabsShown) {
899:                 if ($el.hasClass('after-show-more')) {
900:                     return;
901:                 }
902:             }
903:             else {
904:                 if ($el.hasClass('show-more')) {
905:                     return;
906:                 }
907:             }
908: 
909:             minHeight += $el.height();
910:         });
911: 
912:         const tabHeight = this.$tabs.find('.tab-group > ul > li:visible').height();
913: 
914:         this.tabList.forEach((item, i) => {
915:             if (typeof item !== 'object') {
916:                 return;
917:             }
918: 
919:             const $li = this.$el.find('li.tab[data-name="group-' + i + '"]');
920: 
921:             if (!$li.hasClass('open')) {
922:                 return;
923:             }
924: 
925:             const tabCount = (item.itemList || []).length;
926: 
927:             const menuHeight = tabHeight * tabCount;
928: 
929:             if (menuHeight > minHeight) {
930:                 minHeight = menuHeight;
931:             }
932:         });
933: 
934:         document.body.style.minHeight = minHeight + 'px';
935:     }
936: 
937:     afterRender() {
938:          this.$tabs = this.$el.find('ul.tabs');
939:         this.$more = this.$tabs.find('li.more > ul');
940:         this.$minimizer = this.$el.find('a.minimizer');
941: 
942:         document.body.classList.add('has-navbar');
943: 
944:         const $moreDd = this.$moreDropdown = this.$tabs.find('li.more');
945: 
946:         $moreDd.on('shown.bs.dropdown', () => {
947:             this.isMoreDropdownShown = true;
948:             this.adjustBodyMinHeight();
949:         });
950: 
951:         $moreDd.on('hidden.bs.dropdown', () => {
952:             this.isMoreDropdownShown = false;
953:             this.hideMoreTabs();
954:             this.adjustBodyMinHeight();
955:         });
956: 
957:         this.selectTab(this.getRouter().getLast().controller);
958: 
959:         let layoutState = this.getStorage().get('state', 'siteLayoutState');
960: 
961:         if (!layoutState) {
962:             layoutState = $(window).width() > 1320 ? 'expanded' : 'collapsed';
963:         }
964: 
965:         let layoutMinimized = false;
966: 
967:         if (layoutState === 'collapsed') {
968:             layoutMinimized = true;
969:         }
970: 
971:         if (layoutMinimized) {
972:             document.body.classList.add('minimized');
973:         }
974: 
975:         this.$navbar = this.$el.find('> .navbar');
976:         this.$navbarRightContainer = this.$navbar.find('> .navbar-body > .navbar-right-container');
977:         this.$navbarRight = this.$navbarRightContainer.children();
978: 
979:         this.navbarHeaderElement = this.element.querySelector('.navbar-header');
980: 
981:         const handlerClassName = this.getThemeManager().getParam('navbarAdjustmentHandler');
982: 
983:         if (handlerClassName) {
984:             Espo.loader.require(handlerClassName, Handler => {
985:                 const handler = new Handler(this);
986: 
987:                 this.navbarAdjustmentHandler = handler;
988: 
989:                 handler.process();
990:             });
991: 
992:             return;
993:         }
994: 
995:         if (this.getThemeManager().getParam('skipDefaultNavbarAdjustment')) {
996:             return;
997:         }
998: 
999:         this.adjustAfterRender();
1000:     }
1001: 
1002:     /**
1003:      * @private
1004:      */
1005:     adjustAfterRender() {
1006:         if (this.isSide()) {
1007:             const processSide = () => {
1008:                 if (this.$navbar.height() < $(window).height() / 2) {
1009:                     setTimeout(() => processSide(), 50);
1010: 
1011:                     return;
1012:                 }
1013: 
1014:                 if (this.getThemeManager().isUserTheme()) {
1015:                     setTimeout(() => this.adjustSide(), 10);
1016: 
1017:                     return;
1018:                 }
1019: 
1020:                 this.adjustSide();
1021:             };
1022: 
1023:             processSide();
1024: 
1025:             return;
1026:         }
1027: 
1028:         const process = () => {
1029:             if (this.$el.width() < $(window).width() / 2) {
1030:                 setTimeout(() => process(), 50);
1031: 
1032:                 return;
1033:             }
1034: 
1035:             if (this.getThemeManager().isUserTheme()) {
1036:                 setTimeout(() => this.adjustTop(), 10);
1037: 
1038:                 return;
1039:             }
1040: 
1041:             this.adjustTop();
1042:         };
1043: 
1044:         process();
1045:     }
1046: 
1047:     /**
1048:      * @param {string|false} name
1049:      */
1050:     selectTab(name) {
1051:         const $tabs = this.$el.find('ul.tabs');
1052: 
1053:         $tabs.find('li.active').removeClass('active');
1054: 
1055:         if (name) {
1056:             $tabs.find(`li[data-name="${name}"]`).addClass('active');
1057:         }
1058: 
1059:         this.currentTab = name;
1060: 
1061:         const url = this.getRouter().getCurrentUrl();
1062: 
1063:         this.urlList
1064:             .filter(item => url.startsWith(item.url))
1065:             .forEach(item => {
1066:                 $tabs.find(`li[data-name="${item.name}"]`).addClass('active');
1067:             });
1068:     }
1069: 
1070:     /**
1071:      * @private
1072:      */
1073:     setupTabDefsList() {
1074:         /** @type {{url: string, name: string}[]} */
1075:         this.urlList = [];
1076: 
1077:         const allTabList = this.getTabList();
1078: 
1079:         this.tabList = allTabList.filter((item, i) => {
1080:             if (!item) {
1081:                 return false;
1082:             }
1083: 
1084:             if (typeof item !== 'object') {
1085:                 return this.tabsHelper.checkTabAccess(item);
1086:             }
1087: 
1088:             if (this.tabsHelper.isTabDivider(item)) {
1089:                 if (!this.isSide()) {
1090:                     return false;
1091:                 }
1092: 
1093:                 if (i === allTabList.length - 1) {
1094:                     return false;
1095:                 }
1096: 
1097:                 return true;
1098:             }
1099: 
1100:             if (this.tabsHelper.isTabUrl(item)) {
1101:                 return this.tabsHelper.checkTabAccess(item);
1102:             }
1103: 
1104:             /** @type {(Record|string)[]} */
1105:             let itemList = (item.itemList || []).filter(item => {
1106:                 if (this.tabsHelper.isTabDivider(item)) {
1107:                     return true;
1108:                 }
1109: 
1110:                 return this.tabsHelper.checkTabAccess(item);
1111:             });
1112: 
1113:             itemList = itemList.filter((item, i) => {
1114:                 if (!this.tabsHelper.isTabDivider(item)) {
1115:                     return true;
1116:                 }
1117: 
1118:                 const nextItem = itemList[i + 1];
1119: 
1120:                 if (!nextItem) {
1121:                     return true;
1122:                 }
1123: 
1124:                 if (this.tabsHelper.isTabDivider(nextItem)) {
1125:                     return false;
1126:                 }
1127: 
1128:                 return true;
1129:             });
1130: 
1131:             itemList = itemList.filter((item, i) => {
1132:                 if (!this.tabsHelper.isTabDivider(item)) {
1133:                     return true;
1134:                 }
1135: 
1136:                 if (i === 0 || i === itemList.length - 1) {
1137:                     return false;
1138:                 }
1139: 
1140:                 return true;
1141:             });
1142: 
1143:             item.itemList = itemList;
1144: 
1145:             return !!itemList.length;
1146:         });
1147: 
1148:         let moreIsMet = false;
1149: 
1150:         this.tabList = this.tabList.filter((item, i) => {
1151:             const nextItem = this.tabList[i + 1];
1152:             const prevItem = this.tabList[i - 1];
1153: 
1154:             if (this.tabsHelper.isTabMoreDelimiter(item)) {
1155:                 moreIsMet = true;
1156:             }
1157: 
1158:             if (!this.tabsHelper.isTabDivider(item)) {
1159:                 return true;
1160:             }
1161: 
1162:             if (!nextItem) {
1163:                 return true;
1164:             }
1165: 
1166:             if (this.tabsHelper.isTabDivider(nextItem)) {
1167:                 return false;
1168:             }
1169: 
1170:             if (this.tabsHelper.isTabDivider(prevItem) && this.tabsHelper.isTabMoreDelimiter(nextItem) && moreIsMet) {
1171:                 return false;
1172:             }
1173: 
1174:             return true;
1175:         });
1176: 
1177:         if (moreIsMet) {
1178:             let end = this.tabList.length;
1179: 
1180:             for (let i = this.tabList.length - 1; i >= 0; i --) {
1181:                 const item = this.tabList[i];
1182: 
1183:                 if (!this.tabsHelper.isTabDivider(item)) {
1184:                     break;
1185:                 }
1186: 
1187:                 end = this.tabList.length - 1;
1188:             }
1189: 
1190:             this.tabList = this.tabList.slice(0, end);
1191:         }
1192: 
1193:         const tabDefsList = [];
1194: 
1195:         const colorsDisabled =
1196:             this.getConfig().get('scopeColorsDisabled') ||
1197:             this.getConfig().get('tabColorsDisabled');
1198: 
1199:         const tabIconsDisabled = this.getConfig().get('tabIconsDisabled');
1200: 
1201:         const params = {
1202:             colorsDisabled: colorsDisabled,
1203:             tabIconsDisabled: tabIconsDisabled,
1204:         };
1205: 
1206:         const vars = {
1207:             moreIsMet: false,
1208:             isHidden: false,
1209:         };
1210: 
1211:         this.tabList.forEach((tab, i) => {
1212:             if (this.tabsHelper.isTabMoreDelimiter(tab)) {
1213:                 if (!vars.moreIsMet) {
1214:                     vars.moreIsMet = true;
1215: 
1216:                     return;
1217:                 }
1218: 
1219:                 if (i === this.tabList.length - 1) {
1220:                     return;
1221:                 }
1222: 
1223:                 vars.isHidden = true;
1224: 
1225:                 tabDefsList.push({
1226:                     name: 'show-more',
1227:                     isInMore: true,
1228:                     className: 'show-more',
1229:                     html: '<span class="fas fa-ellipsis-h more-icon"></span>',
1230:                 });
1231: 
1232:                 return;
1233:             }
1234: 
1235:             tabDefsList.push(
1236:                 this.prepareTabItemDefs(params, tab, i, vars)
1237:             );
1238:         });
1239: 
1240:         this.tabDefsList = tabDefsList;
1241:     }
1242: 
1243:     /**
1244:      * @private
1245:      * @param {{
1246:      *     colorsDisabled: boolean,
1247:      *     tabIconsDisabled: boolean,
1248:      * }} params
1249:      * @param {Record|string} tab
1250:      * @param {number} i
1251:      * @param {Object} vars
1252:      * @return {{
1253:      *     isAfterShowMore: boolean,
1254:      *     isDivider: boolean,
1255:      *     color: null,
1256:      *     link: string,
1257:      *     name: string,
1258:      *     isInMore: boolean,
1259:      *     shortLabel: string,
1260:      *     label: string,
1261:      *     isGroup: boolean,
1262:      *     aClassName: string,
1263:      *     iconClass: null
1264:      * }}
1265:      */
1266:     prepareTabItemDefs(params, tab, i, vars) {
1267:         let link;
1268: 
1269:         let iconClass = null;
1270:         let color = null;
1271:         let isGroup = false;
1272:         let isDivider = false;
1273:         let isUrl = false;
1274:         let name = tab;
1275:         let aClassName = 'nav-link';
1276: 
1277:         const label = this.tabsHelper.getTranslatedTabLabel(tab);
1278: 
1279:         if (tab === 'Home') {
1280:             link = '#';
1281:         } else if (this.tabsHelper.isTabDivider(tab)) {
1282:             isDivider = true;
1283: 
1284:             aClassName = 'nav-divider-text';
1285:             name = `divider-${i}`;
1286:         } else if (this.tabsHelper.isTabUrl(tab)) {
1287:             isUrl = true;
1288: 
1289:             name = `url-${i}`;
1290:             link = tab.url || '#';
1291:             color = tab.color;
1292:             iconClass = tab.iconClass;
1293: 
1294:             this.urlList.push({name: name, url: link});
1295:         } else if (this.tabsHelper.isTabGroup(tab)) {
1296:             isGroup = true;
1297: 
1298:             color = tab.color;
1299:             iconClass = tab.iconClass;
1300: 
1301:             name = `group-${i}`;
1302: 
1303:             link = null;
1304: 
1305:             aClassName = 'nav-link-group';
1306:         } else {
1307:             link = '#' + tab;
1308:         }
1309: 
1310:         const shortLabel = label.substring(0, 2);
1311: 
1312:         if (!params.colorsDisabled && !isGroup && !isDivider && !isUrl) {
1313:             color = this.getMetadata().get(['clientDefs', tab, 'color']);
1314:         }
1315: 
1316:         if (
1317:             color &&
1318:             !/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/.test(color)
1319:         ) {
1320:             color = null;
1321:         }
1322: 
1323:         if (!params.tabIconsDisabled && !isGroup && !isDivider && !isUrl) {
1324:             iconClass = this.getMetadata().get(['clientDefs', tab, 'iconClass'])
1325:         }
1326: 
1327:         const o = {
1328:             link: link,
1329:             label: label,
1330:             shortLabel: shortLabel,
1331:             name: name,
1332:             isInMore: vars.moreIsMet,
1333:             color: color,
1334:             iconClass: iconClass,
1335:             isAfterShowMore: vars.isHidden,
1336:             aClassName: aClassName,
1337:             isGroup: isGroup,
1338:             isDivider: isDivider,
1339:         };
1340: 
1341:         if (isGroup) {
1342:             o.itemList = tab.itemList.map((tab, i) => {
1343:                 return this.prepareTabItemDefs(params, tab, i, vars);
1344:             });
1345:         }
1346: 
1347:         if (vars.isHidden) {
1348:             o.className = 'after-show-more';
1349:         }
1350: 
1351:         if (color && !iconClass) {
1352:             o.colorIconClass = 'color-icon fas fa-square';
1353:         }
1354: 
1355:         return o;
1356:     }
1357: 
1358:     /**
1359:      * @typedef {Object} MenuDataItem
1360:      * @property {string} [link]
1361:      * @property {string} [name]
1362:      * @property {string} [html]
1363:      * @property {string} [handler]
1364:      * @property {string} [actionFunction]
1365:      * @property {true} [divider]
1366:      */
1367: 
1368:     /**
1369:      * @private
1370:      */
1371:     setupMenu() {
1372:         let avatarHtml = this.getHelper().getAvatarHtml(this.getUser().id, 'small', 20, 'avatar-link');
1373: 
1374:         if (avatarHtml) {
1375:             avatarHtml += ' ';
1376:         }
1377: 
1378:         /** @type {MenuDataItem[]} */
1379:         this.menuDataList = [
1380:             {
1381:                 link: `#User/view/${this.getUser().id}`,
1382:                 html: avatarHtml + this.getHelper().escapeString(this.getUser().get('name')),
1383:             },
1384:             {divider: true}
1385:         ];
1386: 
1387:         /**
1388:          * @type {Record<string, {
1389:          *     order?: number,
1390:          *     groupIndex?: number,
1391:          *     link?: string,
1392:          *     labelTranslation?: string,
1393:          *     configCheck?: string,
1394:          *     disabled:? boolean,
1395:          *     handler?: string,
1396:          *     actionFunction?: string,
1397:          *     accessDataList?: module:utils~AccessDefs[],
1398:          * }>} items
1399:          */
1400:         const items = this.getMetadata().get('app.clientNavbar.menuItems') || {};
1401: 
1402:         const nameList = Object.keys(items).sort((n1, n2) => {
1403:             const o1 = items[n1].order;
1404:             const o2 = items[n2].order;
1405: 
1406:             const g1 = items[n1].groupIndex;
1407:             const g2 = items[n2].groupIndex;
1408: 
1409:             if (g2 === g1) {
1410:                 return o1 - o2;
1411:             }
1412: 
1413:             return g1 - g2;
1414:         });
1415: 
1416:         let currentGroup = 0;
1417: 
1418:         for (const name of nameList) {
1419:             const item = items[name];
1420: 
1421:             if (item.groupIndex !== currentGroup) {
1422:                 currentGroup = item.groupIndex;
1423: 
1424:                 this.menuDataList.push({divider: true});
1425:             }
1426: 
1427:             if (item.disabled) {
1428:                 continue;
1429:             }
1430: 
1431:             if (
1432:                 item.configCheck &&
1433:                 !Espo.Utils.checkActionAvailability(this.getHelper(), item)
1434:             ) {
1435:                 continue;
1436:             }
1437: 
1438:             if (
1439:                 item.accessDataList &&
1440:                 !Espo.Utils.checkAccessDataList(item.accessDataList, this.getAcl(), this.getUser())
1441:             ) {
1442:                 continue;
1443:             }
1444: 
1445:             this.menuDataList.push({
1446:                 name: name,
1447:                 link: item.link,
1448:                 label: this.getLanguage().translatePath(item.labelTranslation),
1449:                 handler: item.handler,
1450:                 actionFunction: item.actionFunction,
1451:             });
1452:         }
1453:     }
1454: 
1455:     showMoreTabs() {
1456:         this.$el.find('.tab-group.tab.dropdown').removeClass('open');
1457: 
1458:         this.isMoreTabsShown = true;
1459:         this.$more.addClass('more-expanded');
1460:         this.adjustBodyMinHeight();
1461:         this.trigger('show-more-tabs');
1462:     }
1463: 
1464:     hideMoreTabs() {
1465:         if (!this.isMoreTabsShown) {
1466:             return;
1467:         }
1468: 
1469:         this.$more.removeClass('more-expanded');
1470:         this.adjustBodyMinHeight();
1471:         this.isMoreTabsShown = false;
1472:     }
1473: }
1474: 
1475: export default NavbarSiteView;
1476: 

(End of file - total 1476 lines)
</content>
```

---

## Assistant (Scope · glm-5-free · 25.2s)

_Thinking:_

Now I have a comprehensive understanding of the sidenav system. Let me now look at the navbar template and search for how filters work in EspoCRM to understand how saved filters could be used.

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/res/templates/site/navbar.tpl"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/res/templates/site/navbar.tpl</path>
<type>file</type>
<content>1: <div class="navbar navbar-inverse" role="navigation">
2:     <div class="navbar-header">
3:         <button type="button" class="navbar-toggle" data-action="toggleCollapsable">
4:             <span class="fas fa-bars"></span>
5:         </button>
6:         <div class="navbar-logo-container"
7:             ><a
8:                 class="navbar-brand nav-link"
9:                 href="#"
10:             ><img src="{{logoSrc}}" class="logo" alt="logo"></a></div>
11:         <a role="button" class="side-menu-button"><span class="fas fa-bars"></span></a>
12:     </div>
13: 
14:     <div class="navbar-collapse navbar-body">
15:         <div class="navbar-left-container">
16:             <ul class="nav navbar-nav tabs">
17:                 {{#each tabDefsList1}}
18:                     <li
19:                         data-name="{{name}}"
20:                         class="not-in-more tab{{#if isGroup}} tab-group dropdown{{/if}}{{#if isDivider}} tab-divider{{/if}}"
21:                     >
22:                         {{#if isDivider}}
23:                             <div class="{{aClassName}}"><span class="label-text">{{#if label}}{{label}}{{/if}}</span></div>
24:                         {{/if}}
25:                         {{#unless isDivider}}
26:                             <a
27:                                     {{#if link}}href="{{link}}"{{else}}role="button"{{/if}}
28:                                     class="{{aClassName}}"
29:                                     {{#if color}}style="border-color: {{color}}"{{/if}}
30:                                 {{#if isGroup}}
31:                                     id="nav-tab-group-{{name}}"
32:                                     data-toggle="dropdown"
33:                                 {{/if}}
34:                             >
35:                     <span class="short-label"{{#if label}} title="{{label}}"{{/if}}{{#if color}} style="color: {{color}}"{{/if}}>
36:                         {{#if iconClass}}
37:                             <span class="{{iconClass}}"></span>
38:                         {{else}}
39:                             {{#if colorIconClass}}
40:                                 <span class="{{colorIconClass}}" style="color: {{color}}"></span>
41:                             {{/if}}
42:                             <span class="short-label-text">{{shortLabel}}</span>
43:                         {{/if}}
44:                     </span>
45:                                 {{#if label}}
46:                                     <span class="full-label">{{label}}</span>
47:                                 {{/if}}
48:                                 {{#if html}}{{{html}}}{{/if}}
49: 
50:                                 {{#if isGroup}}
51:                                     <span class="fas fa-caret-right group-caret"></span>
52:                                 {{/if}}
53:                             </a>
54:                         {{/unless}}
55:                         {{#if isGroup}}
56:                             <ul class="dropdown-menu" role="menu" aria-labelledby="nav-tab-group-{{name}}">
57:                                 {{#each itemList}}
58:                                     {{#if isDivider}}
59:                                         <li class="divider"></li>
60:                                     {{else}}
61:                                         <li data-name="{{name}}" class="in-group tab">
62:                                             <a
63:                                                     {{#if link}}href="{{link}}"{{else}}role="button"{{/if}}
64:                                                     class="{{aClassName}}"
65:                                                 {{#if color}}
66:                                                     style="border-color: {{color}}"
67:                                                 {{/if}}
68:                                                 {{#if isGroup}}
69:                                                     id="nav-tab-group-{{name}}"
70:                                                     data-toggle="dropdown"
71:                                                 {{/if}}
72:                                             >
73:                             <span class="short-label"{{#if color}} style="color: {{color}}"{{/if}}>
74:                                 {{#if iconClass}}
75:                                     <span class="{{iconClass}}"></span>
76:                                 {{else}}
77:                                     {{#if colorIconClass}}
78:                                         <span class="{{colorIconClass}}" style="color: {{color}}"></span>
79:                                     {{/if}}
80:                                     <span class="short-label-text">&nbsp;</span>
81:                                 {{/if}}
82:                             </span>
83:                                                 <span class="full-label">{{label}}</span>
84:                                             </a>
85:                                         </li>
86:                                     {{/if}}
87:                                 {{/each}}
88:                             </ul>
89:                         {{/if}}
90:                     </li>
91:                 {{/each}}
92:                 <li class="dropdown more{{#unless tabDefsList2.length}} hidden{{/unless}}">
93:                     <a
94:                         id="nav-more-tabs-dropdown"
95:                         class="dropdown-toggle"
96:                         data-toggle="dropdown"
97:                         role="button"
98:                         tabindex="0"
99:                     ><span class="fas fa-ellipsis-h more-icon"></span></a>
100:                     <ul class="dropdown-menu more-dropdown-menu" role="menu" aria-labelledby="nav-more-tabs-dropdown">
101:                         {{#each tabDefsList2}}
102:                             <li
103:                                 data-name="{{name}}"
104:                                 class="in-more tab{{#if className}} {{className}}{{/if}}{{#if isGroup}} dropdown tab-group{{/if}}{{#if isDivider}} tab-divider{{/if}}"
105:                             >
106:                                 {{#if isDivider}}
107:                                     <div class="{{aClassName}}{{#unless label}} no-text{{/unless}}"><span class="label-text">{{#if label}}{{label}}{{/if}}</span></div>
108:                                 {{/if}}
109:                                 {{#unless isDivider}}
110:                                     <a
111:                                             {{#if link}}href="{{link}}"{{else}}role="button"{{/if}}
112:                                             tabindex="0"
113:                                             class="{{aClassName}}"
114:                                         {{#if color}} style="border-color: {{color}}"{{/if}}
115:                                         {{#if isGroup}}
116:                                             id="nav-tab-group-{{name}}"
117:                                             data-toggle="dropdown"
118:                                         {{/if}}
119:                                     >
120:                             <span class="short-label"{{#if color}} style="color: {{color}}"{{/if}}>
121:                                 {{#if iconClass}}
122:                                     <span class="{{iconClass}}"></span>
123:                                 {{else}}
124:                                     {{#if colorIconClass}}
125:                                         <span class="{{colorIconClass}}" style="color: {{color}}"></span>
126:                                     {{/if}}
127:                                     <span class="short-label-text">&nbsp;</span>
128:                                 {{/if}}
129:                             </span>
130:                                         {{#if label}}
131:                                             <span class="full-label">{{label}}</span>
132:                                         {{/if}}
133:                                         {{#if html}}{{{html}}}{{/if}}
134: 
135:                                         {{#if isGroup}}
136:                                             <span class="fas fa-caret-right group-caret"></span>
137:                                         {{/if}}
138:                                     </a>
139:                                 {{/unless}}
140:                                 {{#if isGroup}}
141:                                     <ul class="dropdown-menu" role="menu" aria-labelledby="nav-tab-group-{{name}}">
142:                                         {{#each itemList}}
143:                                             {{#if isDivider}}
144:                                                 <li class="divider"></li>
145:                                             {{else}}
146:                                                 <li data-name="{{name}}" class="in-group tab">
147:                                                     <a
148:                                                             {{#if link}}href="{{link}}"{{else}}role="button"{{/if}}
149:                                                             tabindex="0"
150:                                                             class="{{aClassName}}"
151:                                                         {{#if color}}
152:                                                             style="border-color: {{color}}"
153:                                                         {{/if}}
154:                                                         {{#if isGroup}}
155:                                                             id="nav-tab-group-{{name}}"
156:                                                             data-toggle="dropdown"
157:                                                         {{/if}}
158:                                                     >
159:                                     <span class="short-label"{{#if color}} style="color: {{color}}"{{/if}}>
160:                                         {{#if iconClass}}
161:                                             <span class="{{iconClass}}"></span>
162:                                         {{else}}
163:                                             {{#if colorIconClass}}
164:                                                 <span class="{{colorIconClass}}" style="color: {{color}}"></span>
165:                                             {{/if}}
166:                                             <span class="short-label-text">&nbsp;</span>
167:                                         {{/if}}
168:                                     </span>
169:                                                         <span class="full-label">{{label}}</span>
170:                                                     </a>
171:                                                 </li>
172:                                             {{/if}}
173:                                         {{/each}}
174:                                     </ul>
175:                                 {{/if}}
176:                             </li>
177:                         {{/each}}
178:                     </ul>
179:                 </li>
180:             </ul>
181:             <a class="minimizer hidden" role="button" tabindex="0">
182:                 <span class="fas fa-chevron-right right"></span>
183:                 <span class="fas fa-chevron-left left"></span>
184:             </a>
185:         </div>
186:         <div class="navbar-right-container">
187:             <ul class="nav navbar-nav navbar-right">
188:                 {{#each itemDataList}}
189:                     <li class="{{class}}" data-item="{{name}}">{{{var key ../this}}}</li>
190:                 {{/each}}
191:                 <li class="dropdown menu-container">
192:                     <a
193:                         id="nav-menu-dropdown"
194:                         class="dropdown-toggle"
195:                         data-toggle="dropdown"
196:                         role="button"
197:                         tabindex="0"
198:                         title="{{translate 'Menu'}}"
199:                     ><span class="fas fa-ellipsis-v icon"></span></a>
200:                     <ul class="dropdown-menu" role="menu" aria-labelledby="nav-menu-dropdown">
201:                         {{#each menuDataList}}
202:                             {{#unless divider}}
203:                                 <li><a
204:                                     {{#if name}}data-name="{{name}}"{{/if}}
205:                                     {{#if link}}href="{{link}}"{{else}}role="button"{{/if}}
206:                                     tabindex="0"
207:                                     class="nav-link{{#if handler}} action{{/if}}"
208:                                 >{{#if html}}{{{html}}}{{else}}{{label}}{{/if}}</a></li>
209:                             {{else}}
210:                                 <li class="divider"></li>
211:                             {{/unless}}
212:                         {{/each}}
213:                     </ul>
214:                 </li>
215:             </ul>
216:         </div>
217:     </div>
218: </div>
219: 

(End of file - total 219 lines)
</content>
```

**Tool: grep**

**Input:**
```json
{
  "include": "*.js",
  "pattern": "presetPrimaryFilter|presetFilter|filterList"
}
```

**Output:**
```
Found 143 matches (showing first 100)
/home/antl3x/repos/monostax/mono/components/crm/source/client/lib/espo-crm.js:
  Line 2: define("modules/crm/views/meeting/fields/attendees",["exports","views/fields/link-multiple-with-role"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{columnName="status";roleFieldIsForeign=!1;emptyRoleValue="None"}e.default=s});define("modules/crm/views/calendar/fields/teams",["exports","views/fields/link-multiple"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{foreignScope="Team";getSelectBoolFilterList(){if("team"===this.getAcl().getPermissionLevel("userCalendar"))return["onlyMy"]}}e.default=s});define("modules/crm/knowledge-base-helper",["exports","ajax"],function(e,s){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;s=(t=s,t&&t.__esModule?t:{default:t});var t;e.default=class{constructor(e){this.language=e}getAttributesForEmail(e,t,i){t=t||{};t.body=e.get("body");t.name?t.name=t.name+" ":t.name="";t.name+=this.language.translate("KnowledgeBaseArticle","scopeNames")+": "+e.get("name");s.default.postRequest("KnowledgeBaseArticle/action/getCopiedAttachments",{id:e.id,parentType:"Email",field:"attachments"}).then(e=>{t.attachmentsIds=e.ids;t.attachmentsNames=e.names;t.isHtml=!0;i(t)})}}});define("modules/crm/views/task/record/list",["exports","views/record/list"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{rowActionsView="crm:views/task/record/row-actions/default";actionSetCompleted(e){var t=e.id;if(t){t=this.collection.get(t);if(t){Espo.Ui.notify(this.translate("saving","messages"));t.save({status:"Completed"},{patch:!0}).then(()=>{Espo.Ui.success(this.translate("Saved"));this.collection.fetch()})}}}}e.default=s});define("modules/crm/views/record/panels/tasks",["exports","views/record/panels/relationship","helpers/record/create-related"],func...
  Line 88:         `,setup:function(){t.prototype.setup.call(this);this.$header=$("<span>").append($("<span>").text(this.translate(this.model.entityType,"scopeNames")),' <span class="chevron-right"></span> ',$("<span>").text(this.model.get("name")),' <span class="chevron-right"></span> ',$("<span>").text(this.translate("Acceptance","labels","Meeting")));var e=this.getMetadata().get(["entityDefs",this.model.entityType,"fields","acceptanceStatus","options"])||[];this.statusDataList=[];e.filter(e=>"None"!==e).forEach(e=>{var t={name:e,style:this.getMetadata().get(["entityDefs",this.model.entityType,"fields","acceptanceStatus","style",e])||"default",label:this.getLanguage().translateOption(e,"acceptanceStatus",this.model.entityType),selected:this.model.getLinkMultipleColumn("users","status",this.getUser().id)===e};this.statusDataList.push(t)});this.message=this.translate("selectAcceptanceStatus","messages","Meeting")},actionSetStatus:function(e){this.trigger("set-status",e.status);this.close()}})});define("modules/crm/views/meeting/fields/users",["exports","modules/crm/views/meeting/fields/attendees"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{selectPrimaryFilterName="active";init(){this.assignmentPermission=this.getAcl().getPermissionLevel("assignmentPermission");"no"===this.assignmentPermission&&(this.readOnly=!0);super.init()}getSelectBoolFilterList(){if("team"===this.assignmentPermission)return["onlyMyTeam"]}getIconHtml(e){return this.getHelper().getAvatarHtml(e,"small",18,"avatar-link")}prepareEditItemElement(e,t){var i=super.prepareEditItemElement(e,t),s=this.getHelper().getAvatarHtml(e,"small",18,"avatar-link");if(s){var s=(new DOMParser).parseFromString(s,"text/html").body.childNodes[0],a=i.children[0].querySelector(".link-item-name");a&&a.prepend(s)}return i}}e.default=s});define("modules/crm/views/meeting/fields/reminders",["exports","ui/select","moment","views/fiel...
  Line 107:     `;data(){return{message:this.options.message}}}e.default=s});define("modules/crm/views/campaign/detail",["exports","views/detail"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{}e.default=s});define("crm:views/campaign/record/detail",["views/record/detail"],function(e){return e.extend({duplicateAction:!0,setupActionItems:function(){e.prototype.setupActionItems.call(this);this.dropdownItemList.push({label:"Generate Mail Merge PDF",name:"generateMailMergePdf",hidden:!this.isMailMergeAvailable()});this.listenTo(this.model,"change",function(){this.isMailMergeAvailable()?this.showActionItem("generateMailMergePdf"):this.hideActionItem("generateMailMergePdf")},this)},afterRender:function(){e.prototype.afterRender.call(this)},isMailMergeAvailable:function(){return"Mail"===this.model.get("type")&&!(!this.model.get("targetListsIds")||!this.model.get("targetListsIds").length||!(this.model.get("leadsTemplateId")||this.model.get("contactsTemplateId")||this.model.get("accountsTemplateId")||this.model.get("usersTemplateId")))},actionGenerateMailMergePdf:function(){this.createView("dialog","crm:views/campaign/modals/mail-merge-pdf",{model:this.model},function(e){e.render();this.listenToOnce(e,"proceed",e=>{this.clearView("dialog");Espo.Ui.notifyWait();Espo.Ajax.postRequest(`Campaign/${this.model.id}/generateMailMerge`,{link:e}).then(e=>{Espo.Ui.notify(!1);window.open("?entryPoint=download&id="+e.id,"_blank")})})})}})});define("modules/crm/views/campaign/record/panels/campaign-stats",["exports","views/record/panels/side"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{controlStatsFields(){var e=this.model.attributes.type;let t;switch(e){case"Email":case"Newsletter":t=["sentCount","openedCount","clickedCount","optedOutCount","bouncedCount","leadCreatedCount","optedInCount","re...

/home/antl3x/repos/monostax/mono/components/crm/source/client/lib/espo-admin.js:
  Line 87:     `;data(){return{...super.data(),dateList:this.getValuesItems()}}constructor(e){super(e);this.targetEntityType=e.targetEntityType}getValuesItems(){return(this.model.get(this.name)||[]).map(e=>({name:e,label:this.translate(e,"presetFilters",this.targetEntityType)}))}setup(){super.setup();this.addActionHandler("copyToClipboard",(e,t)=>this.copyToClipboard(t.dataset.name))}copyToClipboard(t){var e=`#${this.targetEntityType}/list/primaryFilter=`+t;navigator.clipboard.writeText(e).then(()=>{var e=this.translate("urlHashCopiedToClipboard","messages","EntityManager").replace("{name}",t);Espo.Ui.notify(e,"success",void 0,{closeButton:!0})})}}e.default=s});define("views/admin/entity-manager/fields/acl-contact-link",["exports","views/fields/enum"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(a=t,a&&a.__esModule?a:{default:a});var a;class s extends t.default{targetEntityType="Contact";setupOptions(){let a=this.model.attributes.name,s=this.getMetadata().get(`entityDefs.${a}.links`)||{};var e=Object.keys(s).filter(e=>{var t=s[e];return!("belongsToParent"!==t.type||!t.entityList||!t.entityList.includes(this.targetEntityType))||t.entity===this.targetEntityType}).sort((e,t)=>this.getLanguage().translate(e,"links",a).localeCompare(this.getLanguage().translate(t,"links",a)));e.unshift("");this.translatedOptions=e.reduce((e,t)=>{e[t]=this.translate(t,"links",a);return e},{});this.params.options=e}}e.default=s});define("views/admin/dynamic-logic/conditions-string/item-operator-only-date",["exports","views/admin/dynamic-logic/conditions-string/item-operator-only-base"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(a=t,a&&a.__esModule?a:{default:a});var a;class s extends t.default{template="admin/dynamic-logic/conditions-string/item-operator-only-date";dateValue;data(){var e=super.data();e.dateValue=this.dateValue;return e}}e.default=s});define("views/admin/dynamic-logic/conditions-string/group-base",["exports"...
  Line 126:         >{{translate 'Edit Layouts' scope='LayoutSet'}}</a>`;editTemplateContent=""}e.default=s});define("views/inbound-email/record/list",["exports","views/record/list"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(a=t,a&&a.__esModule?a:{default:a});var a;class s extends t.default{quickDetailDisabled=!0;quickEditDisabled=!0;massActionList=["remove","massUpdate"];checkAllResultDisabled=!0}e.default=s});define("views/inbound-email/record/edit",["exports","views/record/edit","views/inbound-email/record/detail"],function(e,t,a){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=s(t);a=s(a);function s(e){return e&&e.__esModule?e:{default:e}}class i extends t.default{setup(){super.setup();a.default.prototype.setupFieldsBehaviour.call(this);a.default.prototype.initSslFieldListening.call(this);a.default.prototype.wasFetched.call(this)&&this.setFieldReadOnly("fetchSince")}modifyDetailLayout(e){a.default.prototype.modifyDetailLayout.call(this,e)}controlStatusField(){a.default.prototype.controlStatusField.call(this)}initSmtpFieldsControl(){a.default.prototype.initSmtpFieldsControl.call(this)}controlSmtpFields(){a.default.prototype.controlSmtpFields.call(this)}controlSentFolderField(){a.default.prototype.controlSentFolderField.call(this)}controlSmtpAuthField(){a.default.prototype.controlSmtpAuthField.call(this)}wasFetched(){a.default.prototype.wasFetched.call(this)}}e.default=i});define("views/inbound-email/fields/test-send",["exports","views/email-account/fields/test-send"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(a=t,a&&a.__esModule?a:{default:a});var a;class s extends t.default{getSmtpData(){return{server:this.model.get("smtpHost"),port:this.model.get("smtpPort"),auth:this.model.get("smtpAuth"),security:this.model.get("smtpSecurity"),username:this.model.get("smtpUsername"),password:this.model.get("smtpPassword")||null,authMechanism:this.model.get("smtpAuthMechanism"),fromName:th...
  Line 144:     `;getAttributeList(){return["width","widthPx"]}setup(){this.auxModel=new s.default;this.syncAuxModel();this.listenTo(this.model,"change",(e,t)=>{t.ui||this.syncAuxModel()});var e=new a.default({name:"unit",mode:"edit",model:this.auxModel,params:{options:["%","px"]}}),t=this.valueView=new i.default({name:"value",mode:"edit",model:this.auxModel,params:{min:this.getMinValue(),max:this.getMaxValue()},labelText:this.translate("Value")});this.assignView("unit",e,'[data-name="unit"]');this.assignView("value",t,'[data-name="value"]');this.listenTo(this.auxModel,"change",(e,t)=>{if(t.ui){this.valueView.params.max=this.getMaxValue();this.valueView.params.min=this.getMinValue();this.model.set(this.fetch(),{ui:!0})}})}getMinValue(){return"px"===this.auxModel.attributes.unit?30:5}getMaxValue(){return"px"===this.auxModel.attributes.unit?768:95}validate(){return this.valueView.validate()}fetch(){return"px"===this.auxModel.attributes.unit?{width:null,widthPx:this.auxModel.attributes.value}:{width:this.auxModel.attributes.value,widthPx:null}}syncAuxModel(){var e=this.model.attributes.width,t=this.model.attributes.widthPx,a=e||!t?"%":"px";this.auxModel.set({unit:a,value:"px"==a?t:e})}}e.default=o});define("views/admin/label-manager/index",["exports","view","ui/select"],function(e,t,a){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=s(t);a=s(a);function s(e){return e&&e.__esModule?e:{default:e}}class i extends t.default{template="admin/label-manager/index";scopeList=null;scope=null;language=null;languageList=null;events={'click [data-action="selectScope"]':function(e){let t=$(e.currentTarget).data("name");this.getRouter().checkConfirmLeaveOut(()=>{this.selectScope(t)})},'change select[data-name="language"]':function(e){let t=$(e.currentTarget).val();this.getRouter().checkConfirmLeaveOut(()=>{this.selectLanguage(t)})}};data(){return{scopeList:this.scopeList,languageList:this.languageList,scope:this.scope,language:this.language}}setup(){this.languageList=this.get...

/home/antl3x/repos/monostax/mono/components/crm/source/client/lib/espo-main.js:
  Line 2: define("di",["exports"],function(e){Object.defineProperty(e,"__esModule",{value:!0});e.container=void 0;e.inject=function(i){return function(e,t){t.addInitializer(function(){let e=a.get(i);if(!e){e=Reflect.construct(i,s.get(i));a.set(i,e)}this[t.name]=e})}};e.register=function(){let t=0<arguments.length&&void 0!==arguments[0]?arguments[0]:[];return function(e){s.set(e,t)}};let s=new Map,a=e.container=new Map});define("date-time",["exports","moment"],function(e,a){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;a=(t=a,t&&t.__esModule?t:{default:t});var t;e.default=class{constructor(){}internalDateFormat="YYYY-MM-DD";internalDateTimeFormat="YYYY-MM-DD HH:mm";internalDateTimeFullFormat="YYYY-MM-DD HH:mm:ss";dateFormat="MM/DD/YYYY";timeFormat="HH:mm";timeZone=null;systemTimeZone;weekStart=1;readableDateFormatMap={"DD.MM.YYYY":"DD MMM","DD/MM/YYYY":"DD MMM"};readableShortDateFormatMap={"DD.MM.YYYY":"D MMM","DD/MM/YYYY":"D MMM"};hasMeridian(){return new RegExp("A","i").test(this.timeFormat)}getDateFormat(){return this.dateFormat}getTimeFormat(){return this.timeFormat}getDateTimeFormat(){return this.dateFormat+" "+this.timeFormat}getReadableDateFormat(){return this.readableDateFormatMap[this.getDateFormat()]||"MMM DD"}getReadableShortDateFormat(){return this.readableShortDateFormatMap[this.getDateFormat()]||"MMM D"}getReadableDateTimeFormat(){return this.getReadableDateFormat()+" "+this.timeFormat}getReadableShortDateTimeFormat(){return this.getReadableShortDateFormat()+" "+this.timeFormat}fromDisplayDate(e){var t=(0,a.default)(e,this.dateFormat);return t.isValid()?t.format(this.internalDateFormat):-1}getTimeZone(){return this.timeZone||"UTC"}toDisplayDate(e){var t;return e&&"string"==typeof e&&(t=(0,a.default)(e,this.internalDateFormat),t.isValid())?t.format(this.dateFormat):""}fromDisplay(e){let t;t=this.timeZone?a.default.tz(e,this.getDateTimeFormat(),this.timeZone).utc():a.default.utc(e,this.getDateTimeFormat());return t.isValid()?t.format(this.intern...
  Line 60:     `;backdrop=!0;constructor(e){super(e);this.options=e}setup(){this.headerText=this.translate("Preview")}}e.default=s});define("helpers/mass-action",["exports","di","models/settings","models/user"],function(e,t,i,s){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;i=d(i);s=d(s);var a;let r,o,n,l;function d(e){return e&&e.__esModule?e:{default:e}}function h(d,t,h,i,c,e){var A,P,u,m,p,s,a=Symbol.metadata||Symbol.for("Symbol.metadata"),k=Object.defineProperty,r=Object.create,F=[r(null),r(null)],o=t.length;function _(s,a,r){return function(e,t){a&&(t=e,e=d);for(var i=0;i<s.length;i++)t=s[i].apply(e,r?[t]:[]);return r?t:e}}function O(e,t,i,s){if("function"==typeof e||!s&&void 0===e)return e;throw new TypeError(t+" must "+(i||"be")+" a function"+(s?"":" or undefined"))}function f(r,t,e,i,s,a,o,n,l,d,h){function c(e){if(!h(e))throw new TypeError("Attempted to access private element on non-instance")}var u=[].concat(t[0]),m=t[3],p=!o,f=1===s,g=3===s,v=4===s,y=2===s;function b(i,s,a){return function(e,t){return s&&(t=e,e=r),a&&a(e),w[i].call(e,t)}}if(!p){var w={},M=[],T=g?"get":v||f?"set":"value";if(l?(d||f?w={get:I(function(){return m(this)},i,"get"),set:function(e){t[4](this,e)}}:w[T]=m,d||I(w[T],i,y?"":T)):d||(w=Object.getOwnPropertyDescriptor(r,i)),!d&&!l){if((P=F[+n][i])&&7!=(P^s))throw Error("Decorating two elements with the same name ("+w[T].name+") is not supported yet");F[+n][i]=s<3?1:s}}for(var L=r,C=u.length-1;0<=C;C-=e?2:1){var E=O(u[C],"A decorator","be",!0),x=e?u[C-1]:void 0,S={},D={kind:["field","accessor","method","getter","setter","class"][s],name:i,metadata:A,addInitializer:function(e,t){if(e.v)throw new TypeError("attempted to call addInitializer after decoration was finished");O(t,"An initializer","be",!0),a.push(t)}.bind(null,S)};if(p)P=E.call(x,L,D),S.v=1,O(P,"class decorators","return")&&(L=P);else if(D.static=n,D.private=l,P=D.access={has:l?h.bind():function(e){return i in e}},v||(P.get=l?y?function(e){return c(e),w.value}:b("get",0...
  Line 71: `}return Array.from(t.childNodes).map(e).join("")}(t).replace(/\n{2,}/g,"\n\n").trim()}disableWysiwygMode(){this.destroySummernote();this.$noteEditor=null;this.$summernote&&this.$summernote.addClass("hidden");this.$element.removeClass("hidden");this.$scrollable&&this.$scrollable.off("scroll."+this.cid+"-edit")}fetch(){var t={};if(this.isHtml()){let e=this.$summernote.summernote("code");"<p><br></p>"===e&&(e="");var i=`<img src="${window.location.origin}${window.location.pathname}?entryPoint=attachment`;e=e.replace(new RegExp(i.replace(/([.*+?^=!:${}()|\[\]\/\\])/g,"\\$1"),"g"),'<img src="?entryPoint=attachment');t[this.name]=e}else t[this.name]=this.$element.val();this.fetchEmptyValueAsNull&&!t[this.name]&&(t[this.name]=null);return t}onScrollEdit(e){var t=$(e.target),i=this.$toolbar.height(),s=this.$toolbar.parent().width();let a,r;if(t.get(0)===window.document){var o=t.find(".detail-button-container:not(.hidden)"),n=o.offset();if(n){a=n.top+o.outerHeight();r=a-$(window).scrollTop()}}else{n=t.offset();if(n){a=n.top;r=a-$(window).scrollTop()}}o=this.$el.offset().top,t=o+this.$el.height()-i;let l=!1;a>o&&t>a&&(l=!0);if(l){this.$toolbar.css({top:r+"px",width:s+"px"});this.$toolbar.addClass("sticked");this.$area.css({marginTop:i+"px",backgroundColor:""})}else{this.$toolbar.css({top:"",width:""});this.$toolbar.removeClass("sticked");this.$area.css({marginTop:""})}}attachFile(){var e=this.$el.closest(".record");e.find(`.field[data-name="${this.params.attachmentField}"] input.file`).click();this.stopListening(this.model,"attachment-uploaded:attachments");this.listenToOnce(this.model,"attachment-uploaded:attachments",e=>{if(this.isEditMode()){var t=this.translate("Attached")+"\n"+e.map(e=>e.attributes.name).join("\n");Espo.Ui.notify(t,"success",3e3)}})}initEspoPlugin(){var e=this.getLanguage().get("Global","sets","summernote")||{image:{},link:{},video:{}};(0,i.init)(e)}htmlHasColors(e){return!!~e.indexOf("background-color:")||!!~e.indexOf("color:")||!!~e.indexOf('<font col...
  Line 84:     `;disabled=!1;constructor(e){super(e);this.modeList=e.modeList;this.mode=e.mode;this.scope=e.scope;this.hiddenMap={}}data(){return{disabled:this.disabled,modeDataList:this.modeList.filter(e=>!this.hiddenMap[e]||e===this.mode).map(e=>({name:e,active:e===this.mode,label:this.translate(e,"detailViewModes",this.scope)}))}}changeMode(e){this.mode=e;return this.reRender()}async hideMode(e){this.hiddenMap[e]=!0;await this.reRender()}async showMode(e){delete this.hiddenMap[e];await this.reRender()}disable(){this.disabled=!0;return this.reRender()}enable(){this.disabled=!1;return this.reRender()}}e.default=s});define("helpers/file-upload",["exports","di","models/settings"],function(e,t,i){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;i=(s=i,s&&s.__esModule?s:{default:s});var s,a;let r,o;function n(d,t,h,i,c,e){var A,P,u,m,p,s,a=Symbol.metadata||Symbol.for("Symbol.metadata"),k=Object.defineProperty,r=Object.create,F=[r(null),r(null)],o=t.length;function _(s,a,r){return function(e,t){a&&(t=e,e=d);for(var i=0;i<s.length;i++)t=s[i].apply(e,r?[t]:[]);return r?t:e}}function O(e,t,i,s){if("function"==typeof e||!s&&void 0===e)return e;throw new TypeError(t+" must "+(i||"be")+" a function"+(s?"":" or undefined"))}function f(r,t,e,i,s,a,o,n,l,d,h){function c(e){if(!h(e))throw new TypeError("Attempted to access private element on non-instance")}var u=[].concat(t[0]),m=t[3],p=!o,f=1===s,g=3===s,v=4===s,y=2===s;function b(i,s,a){return function(e,t){return s&&(t=e,e=r),a&&a(e),w[i].call(e,t)}}if(!p){var w={},M=[],T=g?"get":v||f?"set":"value";if(l?(d||f?w={get:I(function(){return m(this)},i,"get"),set:function(e){t[4](this,e)}}:w[T]=m,d||I(w[T],i,y?"":T)):d||(w=Object.getOwnPropertyDescriptor(r,i)),!d&&!l){if((P=F[+n][i])&&7!=(P^s))throw Error("Decorating two elements with the same name ("+w[T].name+") is not supported yet");F[+n][i]=s<3?1:s}}for(var L=r,C=u.length-1;0<=C;C-=e?2:1){var E=O(u[C],"A decorator","be",!0),x=e?u[C-1]:void 0,S={},D={kind:["field","accesso...
  Line 104:     `;constructor(e){super(e);this.reactions=e.reactions}data(){return{reactions:this.reactions}}}e.default=s});define("views/record/search",["exports","view","helpers/misc/stored-text-search","ui/autocomplete","views/search/filter"],function(e,t,s,a,o){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=i(t);s=i(s);a=i(a);o=i(o);function i(e){return e&&e.__esModule?e:{default:e}}class r extends t.default{template="record/search";scope="";entityType="";searchManager;fieldFilterList=null;fieldFilterTranslations=null;textFilter="";primary=null;presetFilterList=null;advanced;bool=null;filtersLayoutName="filters";disableSavePreset=!1;textFilterDisabled=!1;toShowApplyFiltersButton=!1;toShowResetFiltersText=!1;isSearchedWithAdvancedFilter=!1;primaryFiltersDisabled=!1;viewModeIconClassMap={list:"fas fa-align-justify",kanban:"fas fa-align-left fa-rotate-90"};FIELD_QUICK_SEARCH_COUNT_THRESHOLD=4;autocompleteLimit=7;data(){return{scope:this.scope,entityType:this.entityType,textFilter:this.textFilter,bool:this.bool||{},boolFilterList:this.boolFilterList,hasFieldQuickSearch:this.fieldFilterList.length>=this.FIELD_QUICK_SEARCH_COUNT_THRESHOLD,filterFieldDataList:this.getFilterFieldDataList(),filterDataList:this.getFilterDataList(),presetName:this.presetName,presetFilterList:this.getPresetFilterList(),leftDropdown:this.hasLeftDropdown(),textFilterDisabled:this.textFilterDisabled,viewMode:this.viewMode,viewModeDataList:this.viewModeDataList||[],hasViewModeSwitcher:this.viewModeList&&1<this.viewModeList.length,isWide:this.options.isWide,toShowApplyFiltersButton:this.toShowApplyFiltersButton,toShowResetFiltersText:this.toShowResetFiltersText,primaryFiltersDisabled:this.primaryFiltersDisabled}}setup(){this.entityType=this.collection.entityType;this.scope=this.options.scope||this.entityType;this.filtersLayoutName=this.options.filtersLayoutName||this.filtersLayoutName;this.primaryFiltersDisabled=this.options.primaryFiltersDisabled||this.primaryFiltersDisabled;this.viewM...
  Line 112:     `;validations=["required","maxCount"];nameHashName;idsName;nameHash=null;ids=null;foreignScope;autocompleteDisabled=!1;selectRecordsView="views/modals/select-records";createDisabled=!1;forceCreateButton=!1;createButton=!1;sortable=!1;searchTypeList=["anyOf","isEmpty","isNotEmpty","noneOf","allOf"];selectFilterList=null;selectBoolFilterList=null;selectPrimaryFilterName=null;autocompleteMaxCount=null;autocompleteOnEmpty=!1;forceSelectAllAttributes=!1;iconHtml="";linkClass;events={'auxclick a[href]:not([role="button"])':function(e){if(this.isReadMode()){var t=1===e.button&&(e.ctrlKey||e.metaKey);if(t){t=$(e.currentTarget).attr("data-id");if(t){e.preventDefault();e.stopPropagation();this.quickView(t)}}}}};data(){var e=this.model.get(this.idsName),t=this.createButton&&(!this.createDisabled||this.forceCreateButton);return{...super.data(),idValues:this.model.get(this.idsName),idValuesString:e?e.join(","):"",nameHash:this.model.get(this.nameHashName),foreignScope:this.foreignScope,valueIsSet:this.model.has(this.idsName),createButton:t}}getSelectFilters(){return null}getSelectBoolFilterList(){return this.selectBoolFilterList}getSelectPrimaryFilterName(){return this.selectPrimaryFilterName}getSelectFilterList(){return this.selectFilterList}getCreateAttributes(){let t=this.getMetadata().get(["clientDefs",this.entityType,"relationshipPanels",this.name,"createAttributeMap"])||{},i={};Object.keys(t).forEach(e=>i[t[e]]=this.model.get(e));return i}setup(){this.nameHashName=this.name+"Names";this.idsName=this.name+"Ids";this.foreignScope=this.options.foreignScope||this.foreignScope||this.params.entity||this.model.getFieldParam(this.name,"entity")||this.model.getLinkParam(this.name,"entity");"createDisabled"in this.options&&(this.createDisabled=this.options.createDisabled);if(this.isSearchMode()){var e=this.getSearchParamsData().nameHash||this.searchParams.nameHash||{},t=this.getSearchParamsData().idList||this.searchParams.value||[];this.nameHash=Espo.Utils.clone(e);this.ids=Espo...
  Line 162:             `,preventDestroyOnRender:!0,noToggleInit:!0,keepElementTitle:!0,title:this.translate("Reactions")+" · "+this.translate(e,"reactions"),onHide:()=>{this.popover=void 0;this.trigger("popover-hidden")}},this);this.popover=i;var t=i.show(),t=(document.querySelector("#"+t).classList.add("popover-list-view"),`#${t} .popover-content`),s=document.querySelector(t),a=await this.getCollectionFactory().create("User");a.url=`Note/${this.model.id}/reactors/`+e;a.maxSize=this.getConfig().get("recordsPerPageSmall")||5;await a.fetch();if(document.body.contains(s)){s=new r.default({collection:a,listLayout:[{name:"name",view:"views/user/fields/name",link:!0}],checkboxes:!1,displayTotalCount:!1,headerDisabled:!0,buttonsDisabled:!0,rowActionsDisabled:!0});await this.assignView("users",s);s.setSelector(t);await s.render();this.listenToOnce(s,"modal-shown",()=>i.destroy())}else i.hide()}reRenderWhenNoPopover(){this.popover?this.once("popover-hidden",()=>this.reRender()):this.reRender()}}e.default=a});define("views/stream/panel",["exports","views/record/panels/relationship","underscore","views/note/fields/post","view-record-helper","views/fields/text"],function(e,t,i,s,a,r){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=o(t);i=o(i);s=o(s);a=o(a);r=o(r);function o(e){return e&&e.__esModule?e:{default:e}}class n extends t.default{template="stream/panel";rowActionsView="views/stream/record/row-actions/default";postingMode=!1;postDisabled=!1;relatedListFiltersDisabled=!0;layoutName=null;filterList=["all","posts","updates"];collection;_justPosted=!1;pinnedCollection;placeholderText;seed;additionalEvents={'focus textarea[data-name="post"]':function(){this.enablePostingMode(!0)},'keydown textarea[data-name="post"]':function(e){if("Control+Enter"===Espo.Utils.getKeyFromKeyEvent(e)){e.stopPropagation();e.preventDefault();this.post()}},'input textarea[data-name="post"]':function(){this.controlPreviewButton();this.controlPostButtonAvailability(this.postFieldView.getTex...
  Line 209:     `;data(){let t=super.data();t.navbarOptionList=this.getNavbarOptionList();t.navbar=this.getNavbarValue()||this.getDefaultNavbar();t.navbarTranslatedOptions={};t.navbarOptionList.forEach(e=>{t.navbarTranslatedOptions[e]=this.translate(e,"themeNavbars")});return t}setup(){super.setup();this.initThemeManager();this.model.on("change:theme",(e,t,i)=>{this.initThemeManager();i.ui&&this.reRender().then(()=>s.default.focus(this.$element,{noTrigger:!0}))})}afterRenderEdit(){this.$navbar=this.$el.find('[data-name="themeNavbar"]');this.$navbar.on("change",()=>this.trigger("change"));s.default.init(this.$navbar)}getNavbarValue(){var e=this.model.get("themeParams")||{};return e.navbar}getNavbarDefs(){var e;return this.themeManager&&(e=this.themeManager.getParam("params"),e)&&e.navbar?Espo.Utils.cloneDeep(e.navbar):null}getNavbarOptionList(){var e=this.getNavbarDefs();return e&&(e=e.options||[],e.length)&&1!==e.length?e:[]}getDefaultNavbar(){var e=this.getNavbarDefs()||{};return e.default||null}initThemeManager(){var e=this.model.get("theme");this.themeManager=e?new i.default(this.getConfig(),this.getPreferences(),this.getMetadata(),e):null}getAttributeList(){return[this.name,"themeParams"]}setupOptions(){this.params.options=Object.keys(this.getMetadata().get("themes")||{}).sort((e,t)=>"EspoRtl"===t?-1:this.translate(e,"theme").localeCompare(this.translate(t,"theme")))}fetch(){var e=super.fetch(),t={};this.$navbar.length&&(t.navbar=this.$navbar.val());e.themeParams=t;return e}}e.default=r});define("views/settings/fields/tab-list",["exports","views/fields/array"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{addItemModalView="views/settings/modals/tab-list-field-add";noGroups=!1;noDelimiters=!1;setup(){super.setup();this.selected.forEach(e=>{!e||"object"!=typeof e||e.id||(e.id=this.generateItemId())});this.addActionHandler("editGroup",(e,t)=>{this.editGroup(t.dataset.value)}...
  Line 285:     `;data(){return{start:this.convertTimeToDisplay(this.value[0]),end:this.convertTimeToDisplay(this.value[1])}}setup(){this.value=this.options.value}convertTimeToDisplay(e){var t;return e&&(t=(0,i.default)(e,"HH:mm"),t.isValid())?t.format(this.getDateTime().timeFormat):""}}e.default=a});define("views/webhook/record/list",["exports","views/record/list"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{massActionList=["remove","massUpdate","export"]}e.default=s});define("views/webhook/fields/user",["exports","views/fields/link"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{selectPrimaryFilterName="activeApi"}e.default=s});define("views/webhook/fields/event",["exports","views/fields/varchar"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{setupOptions(){let t=[];var e=this.getMetadata().getScopeObjectList().sort((e,t)=>e.localeCompare(t));e.forEach(e=>{t.push(e+".create");t.push(e+".update");t.push(e+".delete")});this.params.options=t}}e.default=s});define("views/user-security/modals/two-factor-sms",["exports","views/modal","model"],function(e,t,i){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=s(t);i=s(i);function s(e){return e&&e.__esModule?e:{default:e}}class a extends t.default{template="user-security/modals/two-factor-sms";className="dialog dialog-record";shortcutKeys={"Control+Enter":"apply"};setup(){this.addActionHandler("sendCode",()=>this.actionSendCode());this.buttonList=[{name:"apply",label:"Apply",style:"danger",hidden:!0,onClick:()=>this.actionApply()},{name:"cancel",label:"Cancel"}];this.headerHtml="&nbsp";var e=this.getConfig().get("auth2FASmsCodeLength")||7;let t=new i.default;t.name="UserSecurity";t.set("phoneNumber",null);t.s...
  Line 488:     `;baseName="created";getAttributeList(){return[this.fieldAt,this.fieldBy]}init(){this.baseName=this.options.baseName||this.baseName;this.fieldAt=this.baseName+"At";this.fieldBy=this.baseName+"By";super.init()}setup(){super.setup();this.createField("at");this.createField("by")}data(){var e=this.model.has(this.fieldBy+"Id"),t=this.model.has(this.fieldAt);let i=null;if(e){var s=this.model.get(this.fieldBy+"Id");s&&(i=this.getHelper().getAvatarHtml(s,"small",16,"avatar-link"))}return{baseName:this.baseName,hasBy:e,hasAt:t,hasBoth:t&&e,byUserAvatar:i,...super.data()}}createField(e){var t=this.baseName+Espo.Utils.upperCaseFirst(e),i=this.model.getFieldType(t)||"base",i=this.model.getFieldParam(t,"view")||this.getFieldManager().getViewName(i);this.createView(e+"Field",i,{name:t,model:this.model,mode:this.MODE_DETAIL,readOnly:!0,readOnlyLocked:!0,selector:'[data-name="'+t+'"]'})}fetch(){return{}}}e.default=s});define("views/fields/collaborators",["exports","views/fields/link-multiple"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{linkClass="text-default";init(){this.assignmentPermission=this.getAcl().getPermissionLevel("assignmentPermission");"no"===this.assignmentPermission&&(this.readOnly=!0);super.init()}getSelectBoolFilterList(){if("team"===this.assignmentPermission)return["onlyMyTeam"]}getSelectPrimaryFilterName(){return"active"}getDetailLinkHtml(e,t){var i=super.getDetailLinkHtml(e),s=this.isDetailMode()?this.getHelper().getAvatarHtml(e,"small",18,"avatar-link"):"";return s?""+s+i:i}getOnEmptyAutocomplete(){if(!this.params.autocompleteOnEmpty)return this.ids&&this.ids.includes(this.getUser().id)?Promise.resolve([]):Promise.resolve([{id:this.getUser().id,name:this.getUser().attributes.name}])}prepareEditItemElement(e,t){var i=super.prepareEditItemElement(e,t),s=this.getHelper().getAvatarHtml(e,"small",18,"avatar-link");if(s){var s=(new DOMParser).parseFromString...
  Line 537:     `;data(){var e;return this.model.has("folderId")?(e=this.getFolderString(),{valueIsSet:!0,value:this.getFolderString(),isList:Array.isArray(e)}):{valueIsSet:!1}}getAttributeList(){return["isUsers","folderId","folderName","groupFolderId","groupFolderName","inArchive","inTrash","isUsersSent","groupStatusFolder"]}getFolderString(){if(this.model.attributes.groupFolderName){let e=this.translate("group","strings","Email")+" · "+this.model.attributes.groupFolderName;"Archive"===this.model.attributes.groupStatusFolder?e+=" · "+this.translate("archive","presetFilters","Email"):"Trash"===this.model.attributes.groupStatusFolder&&(e+=" · "+this.translate("trash","presetFilters","Email"));return this.model.attributes.isUsersSent?[e,this.translate("sent","presetFilters","Email")]:e}let e;this.model.attributes.inTrash&&(e=this.translate("trash","presetFilters","Email"));this.model.attributes.inArchive&&(e=this.translate("archive","presetFilters","Email"));this.model.attributes.folderName&&this.model.attributes.folderId&&(e=this.model.attributes.folderName);return e&&this.model.attributes.isUsersSent?[e,this.translate("sent","presetFilters","Email")]:this.model.attributes.isUsersSent?this.translate("sent","presetFilters","Email"):this.model.attributes.createdById===this.getUser().id&&"Draft"===this.model.attributes.status?this.translate("drafts","presetFilters","Email"):this.model.attributes.isUsers?this.translate("inbox","presetFilters","Email"):void 0}}e.default=s});define("views/email/fields/email-address-varchar",["exports","views/fields/base","views/email/fields/from-address-varchar","views/email/fields/email-address","ui/autocomplete"],function(e,t,r,i,s){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=a(t);r=a(r);i=a(i);s=a(s);function a(e){return e&&e.__esModule?e:{default:e}}class o extends t.default{detailTemplate="email/fields/email-address-varchar/detail";editTemplate="email/fields/email-address-varchar/edit";emailAddressRegExp=new RegExp(/^[-!#$...
  Line 548:     `;setup(){var t=this.getOption("url"),e=this.getConfig().get("iframeSandboxExcludeDomainList")||[];if(t)for(var i of e)try{var s=new URL(t);if(s.hostname===i){this.sandboxDisabled=!0;break}}catch(e){console.warn(`Invalid URL ${t}.`)}}afterRender(){var e=this.$el.find("iframe"),t=this.getOption("url");t&&e.attr("src",t);this.$el.addClass("no-padding");this.$el.css("overflow","hidden");t=this.$el.height();e.css("height",t);e.css("width","100%")}afterAdding(){this.getContainerView().actionOptions()}}e.default=s});define("views/dashlets/emails",["exports","views/dashlets/abstract/record-list"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{name="Emails";scope="Emails";rowActionsView="views/email/record/row-actions/dashlet";listView="views/email/record/list-expanded";setupActionList(){this.getAcl().checkScope(this.scope,"create")&&this.actionList.unshift({name:"compose",text:this.translate("Compose Email","labels",this.scope),iconHtml:'<span class="fas fa-plus"></span>'})}actionCompose(){var e=this.getCreateAttributes()||{},t=(Espo.Ui.notifyWait(),this.getMetadata().get("clientDefs."+this.scope+".modalViews.compose")||"views/modals/compose-email");this.createView("modal",t,{scope:this.scope,attributes:e},e=>{e.render();Espo.Ui.notify(!1);this.listenToOnce(e,"after:save",()=>{this.actionRefresh()})})}getSearchData(){return{advanced:[{attribute:"folderId",type:"inFolder",value:this.getOption("folder")||"inbox"}]}}}e.default=s});define("views/dashlets/options/record-list",["exports","views/dashlets/options/base"],function(e,t){Object.defineProperty(e,"__esModule",{value:!0});e.default=void 0;t=(i=t,i&&i.__esModule?i:{default:i});var i;class s extends t.default{hasCollaborators;setup(){var e=this.getMetadata().get(`dashlets.${this.name}.entityType`);this.hasCollaborators=e&&!!this.getMetadata().get(`scopes.${e}.collaborators`);super.setup();this.hasCollaborators||this.g...
  Line 585:     `;delimiter=":,:";targetEntityType;data(){let i=this.getRowList();var e=[...i,[]].map((e,t)=>({index:t,value:e.map(e=>e.name).join(this.delimiter),hasEdit:t<i.length,itemList:e.map(e=>({name:e.name,label:this.translate(e.name,"fields",this.targetEntityType)}))}));return{rowDataList:e}}setup(){this.addHandler("change",'div[data-role="layoutRow"] input',()=>{setTimeout(()=>{this.trigger("change");this.reRender()},1)});this.addActionHandler("editItem",(e,t)=>this.editItem(t.dataset.name));this.targetEntityType=this.model.get("entityType")||this.getMetadata().get(["dashlets",this.dataObject.dashletName,"entityType"])}getRowList(){return Espo.Utils.cloneDeep((this.model.get(this.name)||{}).rows||[])}afterRenderEdit(){let o=Espo.Utils.cloneDeep(this.getRowList()),n=(o.push([]),this.getFieldDataList());o.forEach((e,i)=>{let s=[],a=[];o.forEach((e,t)=>{a.push(...e.map(e=>e.name));t!==i&&s.push(...e.map(e=>e.name))});var t=n.filter(e=>!s.includes(e.value)).map(e=>{if(a.includes(e.value)){var t=this.getItemData(e.value)||{};t.soft&&(e.style="soft");t.small&&(e.small=!0)}return e}),r=this.element.querySelector(`input[data-index="${i.toString()}"]`),t={items:t,delimiter:this.delimiter,matchAnyWord:this.matchAnyWord,draggable:!0};l.default.init(r,t)})}getFieldDataList(){let i=this.model.get("entityType")||this.getMetadata().get(["dashlets",this.dataObject.dashletName,"entityType"]);if(!i)return[];let s=this.getMetadata().get(["entityDefs",i,"fields"])||{},a=this.getAcl().getScopeForbiddenFieldList(i);var e=Object.keys(s).sort((e,t)=>this.translate(e,"fields",i).localeCompare(this.translate(t,"fields",i))).filter(e=>{var t,i=s[e];return!(i.disabled||i.listLayoutDisabled||i.utility||(t=i.layoutAvailabilityList,t&&!t.includes("list"))||(t=i.layoutIgnoreList||[],t.includes("list"))||-1!==a.indexOf(e))});let t=[];e.forEach(e=>{t.push({value:e,text:this.translate(e,"fields",i)})});return t}async editItem(t){var e=this.getItemData(t),e=new i.default({label:this.translate(t,"fields"...

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/record/search-mobile.js:
  Line 212:                 presetFilterList: this.getPresetFilterList(),

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/modals/mobile-filter.js:
  Line 80:             presetFilterList: this.options.presetFilterList || [],

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/record/list.js:
  Line 2544:     filterListLayout(listLayout) {
  Line 2615:             const filteredListLayout = this.filterListLayout(listLayout);

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/list.js:
  Line 837:                 "presetFilters",

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/search.js:
  Line 105:         const filterList =
  Line 106:             metadata.get(["clientDefs", "Activities", "filterList"]) || [];
  Line 111:             "ActivitiesSearchView: filterList from clientDefs:",
  Line 112:             filterList
  Line 119:         this.fieldFilterList = filterList.filter(

/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/advanced/lib/module-advanced.js:
  Line 17: define("advanced:views/workflow/actions/base",["view","model"],function(t,e){return t.extend({template:"advanced:workflow/actions/base",defaultActionData:{execution:{type:"immediately",field:!1,shiftDays:0}},data:function(){const t={};if(this.actionData.fields)for(const[e,i]of Object.entries(this.actionData.fields))if("add"===i.actionType||"remove"===i.actionType){let s;s="remove"===i.actionType?this.translate("Remove"):this.translate("Add"),t[e]=this.translate(s)}return{entityType:this.entityType,actionType:this.actionType,linkedEntityName:this.linkedEntityName||this.entityType,displayedLinkedEntityName:this.displayedLinkedEntityName||this.linkedEntityName||this.entityType,actionData:this.actionData,readOnly:this.readOnly,fieldActionLabelMap:t}},events:{'click [data-action="editAction"]':function(){this.edit()}},setup:function(){if(this.actionType=this.options.actionType,this.id=this.options.id,this.readOnly=this.options.readOnly,this.actionData=this.options.actionData||{},this.hasFormulaAvailable=!!this.getMetadata().get("app.formula.functionList"),this.options.isNew){const t={};for(const e in this.defaultActionData)t[e]=Espo.Utils.clone(this.defaultActionData[e]);if("execution"in t)for(const e in t.execution)t.execution[e]=Espo.Utils.clone(t.execution[e]);this.actionData=_.extend(t,this.actionData)}this.entityType=this.options.entityType,this.additionalSetup()},afterRender:function(){this.renderFields(),this.$formulaField=this.$el.find('.field[data-name="formula"]'),this.hasFormulaAvailable&&this.renderFormula()},renderFormula:function(){if(this.clearView("formula"),this.actionData.formula&&""!==this.actionData.formula){this.$formulaField.removeClass("hidden");const t=new e;return t.set("formula",this.actionData.formula),void this.createView("formula","views/fields/formula",{name:"formula",model:t,mode:"detail",height:100,selector:' .field[data-name="formula"]',inlineEditDisabled:!0,params:{seeMoreDisabled:!0},smallFont:!0},t=>{t.render()})}this.clearView("formul...

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/stream/panel.js:
  Line 45:     filterList = ['all', 'posts', 'updates']
  Line 802:         this.filterList.forEach(item => {

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/user/modals/select-followers.js:
  Line 34:         this.filterList = ['active'];
  Line 37:             this.filterList.push('activePortal');

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/stream.js:
  Line 34:     filterList = ['all', 'posts', 'updates']
  Line 61:             filterList: this.filterList,
  Line 124:         this.filterList.forEach(item => {

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/record/search.js:
  Line 65:     presetFilterList = null
  Line 98:             presetFilterList: this.getPresetFilterList(),
  Line 226:             for (const i in this.presetFilterList) {
  Line 227:                 const item = this.presetFilterList[i] || {};
  Line 239:                 this.presetFilterList.push(this.presetName);
  Line 261:             this.presetFilterList = [];
  Line 266:         const filterList = this.options.filterList ||
  Line 267:             this.getMetadata().get(['clientDefs', this.scope, 'filterList']) || [];
  Line 269:         this.presetFilterList = filterList.filter(item => {
  Line 298:             this.presetFilterList.unshift({
  Line 303:         ((this.getPreferences().get('presetFilters') || {})[this.scope] || [])
  Line 305:                 this.presetFilterList.push(item);
  Line 356:         return this.presetFilterList.length ||
  Line 641:         const presetFilters = this.getPreferences().get('presetFilters') || {};
  Line 643:         if (!(this.scope in presetFilters)) {
  Line 644:             presetFilters[this.scope] = [];
  Line 655:         presetFilters[this.scope].push(data);
  Line 657:         this.presetFilterList.push(data);
  Line 664:         this.getPreferences().save({'presetFilters': presetFilters}, {patch: true});
  Line 670:         const presetFilters = this.getPreferences().get('presetFilters') || {};
  Line 672:         if (!(this.scope in presetFilters)) {
  Line 673:             presetFilters[this.scope] = [];
  Line 678:         list = presetFilters[this.scope];
  Line 686:         list = this.presetFilterList;
  Line 694:         this.getPreferences().set('presetFilters', presetFilters);
  Line 734:         this.$filterList = this.$el.find('ul.filter-list');
  Line 736:         this.$fieldQuickSearch = this.$filterList.find('input.field-filter-quick-search-input');
  Line 878:         let filterLabel = this.translate('all', 'presetFilters', this.entityType);
  Line 892:             this.presetFilterList.forEach(item => {
  Line 900:             label = label || this.translate(this.presetName, 'presetFilters', this.entityType);
  Line 922:                 const label = this.translate(primary, 'presetFilters', this.entityType);
  Line 1001:         this.presetFilterList.forEach((item) => {
  Line 1277:         const $li = this.$filterList.find('li.filter-item');
  Line 1309:         this.$filterList.find('li.filter-item').removeClass('search-hidden');
  Line 1313:         const $first = this.$filterList.find('li.filter-item:not(.hidden):not(.search-hidden)').first();

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/record/panels/relationship.js:
  Line 195:         this.filterList = this.defs.filterList || this.filterList || null;
  Line 197:         if (this.filterList && this.filterList.length) {
  Line 198:             this.filter = this.getStoredFilter() || this.filterList[0];
  Line 501:         if (!(this.filterList && this.filterList.length)) {
  Line 507:         this.filterList.slice(0).forEach((item) => {
  Line 544:         return this.translate(name, 'presetFilters', this.entityType);
  Line 600:         this.filterList.forEach(item => {
  Line 686:             filterList: this.filterList,

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/modals/select-records.js:
  Line 92:      * @property {string[]} [filterList] A filter list.
  Line 149:         this.filterList = this.options.filterList || this.filterList || null;
  Line 306:                 filterList: this.filterList,

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/modals/related-list.js:
  Line 121:         this.filterList = this.options.filterList;
  Line 374:         let filterList = Espo.Utils.clone(this.getMetadata().get(['clientDefs', this.scope, 'filterList']) || []);
  Line 377:             filterList = [];
  Line 380:         if (this.filterList) {
  Line 381:             this.filterList.forEach(item1 => {
  Line 390:                 filterList.forEach(item2 => {
  Line 399:                     filterList.push(item1);
  Line 405:             filterList = [];
  Line 414:                 filterList: filterList,

/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/list-related.js:
  Line 366:         let filterList = Espo.Utils
  Line 367:             .clone(this.getMetadata().get(['clientDefs', this.foreignScope, 'filterList']) || []);
  Line 369:         if (this.panelDefs.filterList) {
  Line 370:             this.panelDefs.filterList.forEach(item1 => {
  Line 378:                 filterList.forEach(item2 => {
  Line 387:                     filterList.push(item1);
  Line 393:             filterList = [];
  Line 404:             filterList: filterList,

(Results truncated: showing 100 of 143 matches (43 hidden). Consider using a more specific path or pattern.)
```

---

## Assistant (Scope · glm-5-free · 31.5s)

_Thinking:_

Now I have a comprehensive understanding of how EspoCRM's tab list system works and how preset filters work. Now let me understand the specific requirements for the virtual-folder-tablist-type:

1. **Concept**: A new type of tab list item (`type: "virtualFolder"`) that works like a sibling to `addGroup`, `addDivider`, `addUrl`
2. **Features**:
   - Uses entity + saved filter to dynamically fetch records
   - Shows entity icon + label as the "divider" header
   - Shows records as link items under the divider
   - Collapsible/expandable
   - Optional limit on number of visible items
   - On hover: shows more options menu + plus button for quick create

Let me now look at how the collapse/expand feature could be implemented and how to fetch records dynamically.

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/fields/name-with-icon.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/fields/name-with-icon.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of EspoCRM.
3:  *
4:  * EspoCRM – Open Source CRM application.
5:  * Copyright (C) 2014-2025 EspoCRM, Inc.
6:  * Website: https://www.espocrm.com
7:  *
8:  * This program is free software: you can redistribute it and/or modify
9:  * it under the terms of the GNU Affero General Public License as published by
10:  * the Free Software Foundation, either version 3 of the License, or
11:  * (at your option) any later version.
12:  *
13:  * This program is distributed in the hope that it will be useful,
14:  * but WITHOUT ANY WARRANTY; without even the implied warranty of
15:  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
16:  * GNU Affero General Public License for more details.
17:  *
18:  * You should have received a copy of the GNU Affero General Public License
19:  * along with this program. If not, see <https://www.gnu.org/licenses/>.
20:  *
21:  * The interactive user interfaces in modified source and object code versions
22:  * of this program must display Appropriate Legal Notices, as required under
23:  * Section 5 of the GNU Affero General Public License version 3.
24:  *
25:  * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
26:  * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
27:  ************************************************************************/
28: 
29: import VarcharFieldView from 'views/fields/varchar';
30: 
31: class NameWithIconFieldView extends VarcharFieldView {
32:     
33:     listTemplate = 'global:activities/fields/name-with-icon/list'
34:     listLinkTemplate = 'global:activities/fields/name-with-icon/list-link'
35:     
36:     data() {
37:         const data = super.data();
38:         
39:         return {
40:             ...data,
41:             iconClass: this.getIconClass(),
42:             iconStyle: this.getIconStyle(),
43:         };
44:     }
45:     
46:     getEntityType() {
47:         // In MultiCollection models, entityType or name property contains the entity type
48:         // NOT model.get('name') which is the name field value
49:         return this.model.entityType || this.model.name || 'Activities';
50:     }
51:     
52:     getIconClass() {
53:         const entityType = this.getEntityType();
54:         return this.getMetadata().get(['clientDefs', entityType, 'iconClass']) || 'fas fa-circle';
55:     }
56:     
57:     getIconStyle() {
58:         const entityType = this.getEntityType();
59:         const color = this.getMetadata().get(['clientDefs', entityType, 'color']);
60:         
61:         if (color) {
62:             return `color: ${color};`;
63:         }
64:         
65:         return '';
66:     }
67: }
68: 
69: export default NameWithIconFieldView;
70: 
71: 

(End of file - total 71 lines)
</content>
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/css/navbar-config-selector.css"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/css/navbar-config-selector.css</path>
<type>file</type>
<content>1: .navbar-config-selector {
2:     padding: var(--8px) var(--12px);
3:     border-bottom: 1px solid var(--navbar-inverse-border, rgba(255, 255, 255, 0.1));
4: }
5: 
6: .navbar-config-selector-toggle {
7:     display: flex;
8:     align-items: center;
9:     gap: var(--4px);
10:     padding: var(--4px) var(--8px);
11:     border-radius: var(--border-radius);
12:     color: inherit;
13:     cursor: pointer;
14:     text-decoration: none;
15:     font-size: 0.9em;
16:     white-space: nowrap;
17:     overflow: hidden;
18:     text-overflow: ellipsis;
19:     transition: background-color 0.15s ease;
20: }
21: 
22: .navbar-config-selector-toggle:hover,
23: .navbar-config-selector-toggle:focus {
24:     background-color: var(--navbar-inverse-link-hover-bg, rgba(255, 255, 255, 0.1));
25:     color: inherit;
26:     text-decoration: none;
27: }
28: 
29: .navbar-config-name {
30:     flex: 1;
31:     overflow: hidden;
32:     text-overflow: ellipsis;
33: }
34: 
35: .navbar-config-icon {
36:     flex-shrink: 0;
37: }
38: 
39: .navbar-config-color-dot {
40:     flex-shrink: 0;
41:     font-size: 0.5em;
42: }
43: 
44: .navbar-config-caret {
45:     flex-shrink: 0;
46:     font-size: 0.75em;
47:     opacity: 0.7;
48: }
49: 
50: .navbar-config-dropdown {
51:     width: 100%;
52:     max-width: calc(var(--navbar-width, 232px) - var(--12px) * 2);
53: }
54: 
55: .navbar-config-dropdown li.active > a {
56:     font-weight: 600;
57: }
58: 
59: .navbar-config-dropdown li > a {
60:     display: flex;
61:     align-items: center;
62:     gap: var(--4px);
63:     padding: var(--4px) var(--12px);
64:     cursor: pointer;
65: }
66: 
67: .navbar-config-dropdown li > a:hover {
68:     background-color: var(--dropdown-link-hover-bg);
69:     color: var(--dropdown-link-hover-color);
70: }
71: 
72: @media screen and (max-width: 768px) {
73:     .navbar-config-selector {
74:         display: none;
75:     }
76: }
77: 

(End of file - total 77 lines)
</content>
```

---

## Assistant (Scope · glm-5-free · 205.1s)

_Thinking:_

Now I have enough information to create a comprehensive scope document for the virtual-folder-tablist-type feature. Let me create the scope document.

Now I have a comprehensive understanding of the codebase. Let me create the scope document for the virtual-folder-tablist-type feature.

**Tool: bash**

**Input:**
```json
{
  "command": "cat > /home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v1.md << 'EOF'\n# Virtual Folder Tab List Type - v1 Scope Document\n\n> **Version**: 1.0  \n> **Codebase Root**: `components/crm/source/`  \n> **Status**: SCOPE MAPPED  \n> **Uses**: Global module (`custom/Espo/Modules/Global` and `client/custom/modules/global`)\n\n## Overview\n\nThis feature adds a new tab list item type called `virtualFolder` that acts as a sibling to `group`, `divider`, and `url` types. A virtual folder:\n- Displays entity records dynamically fetched via a saved filter\n- Shows a collapsible \"divider-like\" header with entity icon + custom label\n- Lists record items as clickable links under the header\n- Provides a quick-create button and more options menu on hover\n\n### Key Features\n\n1. **Dynamic Record Loading**: Records fetched from entity using saved filter (or no filter)\n2. **Collapsible/Expandable**: Click header to toggle visibility of record items\n3. **Entity Icon Display**: Shows entity icon + label in header (like divider with icon)\n4. **Item Limit Control**: Optional max number of items to display\n5. **Quick Create**: Plus button on hover opens quick-create modal for the entity\n6. **More Options Menu**: Dropdown menu on hover for additional actions\n\n---\n\n## Decisions\n\n| # | Decision | Alternatives Considered | Rationale |\n|---|----------|------------------------|-----------|\n| 1 | Store virtual folder config as new tab type `virtualFolder` in tabList | Separate entity, JSON field | Reuses existing tabList infrastructure, follows group/divider/url pattern |\n| 2 | Fetch records dynamically at navbar render time | Pre-load via AppParam, cache | Simpler implementation, records always fresh |\n| 3 | Collapse state stored in browser localStorage | Server-side preference | Per-device preference, no server load, instant toggle |\n| 4 | Use presetFilters from Preferences for saved filter selection | Create new SavedFilter entity link | Uses existing EspoCRM preset filter system |\n| 5 | Limit visible items with `maxItems` property (default: 5) | Hard-coded limit, config setting | Per-folder customization, reasonable default |\n| 6 | Quick create uses existing `RecordModal` helper | Custom modal | Consistent UX, reuses proven code |\n| 7 | More options menu includes: Edit folder config, Refresh, View all in list | Complex action set | MVP feature set, extensible later |\n| 8 | Custom label overrides entity name in header | Always use entity name | Allows grouping under custom names (e.g., \"My Open Tasks\" vs \"Tasks\") |\n| 9 | No backend PHP changes needed initially | Custom controller endpoints | All fetching via existing collection API |\n\n---\n\n## Data Model Design\n\n### Virtual Folder Item Structure (stored in tabList)\n\n```json\n{\n  \"type\": \"virtualFolder\",\n  \"id\": \"vf-123456\",\n  \"label\": \"My Open Tasks\",\n  \"entityType\": \"Task\",\n  \"filterName\": \"myOpen\",\n  \"maxItems\": 5,\n  \"iconClass\": null,\n  \"color\": null,\n  \"orderBy\": \"createdAt\",\n  \"order\": \"desc\"\n}\n```\n\n### Field Definitions\n\n| Field | Type | Required | Description |\n|-------|------|----------|-------------|\n| `type` | string | Yes | Must be `\"virtualFolder\"` |\n| `id` | string | Yes | Unique identifier for collapse state |\n| `label` | string | No | Custom label (falls back to entity plural name) |\n| `entityType` | string | Yes | Target entity scope (e.g., \"Task\", \"Opportunity\") |\n| `filterName` | string | No | Preset filter name from clientDefs or user presetFilters |\n| `maxItems` | int | No | Max items to display (default: 5, 0 = unlimited) |\n| `iconClass` | string | No | Override entity icon (falls back to entity iconClass) |\n| `color` | string | No | Border/accent color |\n| `orderBy` | string | No | Field to order by (default: entity default) |\n| `order` | string | No | \"asc\" or \"desc\" (default: entity default) |\n\n---\n\n## File Manifest\n\n### Files to CREATE (ordered by complexity/risk, highest first)\n\n#### 1. Virtual Folder Navbar Item View (CRITICAL)\n\n| File Path | Purpose |\n|-----------|---------|\n| `client/custom/modules/global/src/views/site/navbar/virtual-folder.js` | Main view component for rendering a virtual folder in the sidenav. Handles record fetching, collapse/expand, quick create, and more options. |\n\n**Key Implementation Details:**\n- Extends `View` class\n- `template` property: `global:site/navbar/virtual-folder`\n- Fetches records via `Espo.Ajax.getRequest(entityType, {where: filterData, maxItems})`\n- Manages collapse state via `localStorage` key `navbar-vf-{id}-collapsed`\n- Creates quick create view using `RecordModal` helper\n- Handles more options dropdown with refresh/edit actions\n\n**Reference Patterns:**\n- Quick create: `client/src/views/site/navbar/quick-create.js`\n- Record fetching: `client/src/views/record/panels/relationship.js` lines 501-544\n- Collapse toggle: CSS class toggling pattern from existing group dropdowns\n\n---\n\n#### 2. Virtual Folder Template (HIGH)\n\n| File Path | Purpose |\n|-----------|---------|\n| `client/custom/modules/global/res/templates/site/navbar/virtual-folder.tpl` | Handlebars template for virtual folder rendering |\n\n**Structure:**\n```handlebars\n<li class=\"tab tab-virtual-folder{{#if isCollapsed}} collapsed{{/if}}\" data-name=\"vf-{{id}}\">\n    <div class=\"virtual-folder-header\" data-action=\"toggleVirtualFolder\" data-id=\"{{id}}\">\n        <span class=\"virtual-folder-icon {{iconClass}}\"></span>\n        <span class=\"virtual-folder-label\">{{label}}</span>\n        <span class=\"virtual-folder-caret fas fa-chevron-{{#if isCollapsed}}right{{else}}down{{/if}}\"></span>\n        <div class=\"virtual-folder-actions hidden\">\n            <a class=\"action\" data-action=\"quickCreate\" title=\"{{translate 'Create'}}\">\n                <span class=\"fas fa-plus\"></span>\n            </a>\n            <a class=\"dropdown-toggle\" data-toggle=\"dropdown\">\n                <span class=\"fas fa-ellipsis-v\"></span>\n            </a>\n            <ul class=\"dropdown-menu pull-right\">\n                <li><a data-action=\"refresh\">{{translate 'Refresh'}}</a></li>\n                <li><a data-action=\"viewAll\">{{translate 'View All'}}</a></li>\n            </ul>\n        </div>\n    </div>\n    <ul class=\"virtual-folder-items{{#if isCollapsed}} hidden{{/if}}\">\n        {{#each recordList}}\n            <li class=\"virtual-folder-item\">\n                <a href=\"#{{../entityType}}/view/{{id}}\" class=\"nav-link\">\n                    {{name}}\n                </a>\n            </li>\n        {{/each}}\n        {{#if hasMore}}\n            <li class=\"virtual-folder-more\">\n                <a href=\"#{{entityType}}/list{{#if filterQuery}}?{{filterQuery}}{{/if}}\">\n                    {{translate 'View all'}} ({{totalCount}})\n                </a>\n            </li>\n        {{/if}}\n    </ul>\n</li>\n```\n\n---\n\n#### 3. Virtual Folder Styles (HIGH)\n\n| File Path | Purpose |\n|-----------|---------|\n| `client/custom/modules/global/css/virtual-folder.css` | Styles for virtual folder component |\n\n**Key Styles:**\n- `.tab-virtual-folder` - Container styling\n- `.virtual-folder-header` - Header with hover actions\n- `.virtual-folder-actions` - Hidden by default, show on header hover\n- `.virtual-folder-items` - Collapsible item list\n- `.virtual-folder-item` - Individual record item\n- `.virtual-folder-caret` - Collapse/expand indicator\n- Animation for collapse/expand\n\n---\n\n#### 4. Tab List Field Add Modal Extension (MEDIUM)\n\n| File Path | Purpose |\n|-----------|---------|\n| `client/custom/modules/global/src/views/settings/modals/tab-list-field-add.js` | Extends core modal to add \"Add Virtual Folder\" button |\n\n**Changes from base `views/settings/modals/tab-list-field-add.js`:**\n- Add button: `{name: 'addVirtualFolder', text: 'Virtual Folder', iconClass: 'fas fa-folder'}`\n- Add action: `actionAddVirtualFolder()` triggering `add` event with virtual folder skeleton\n\n**Reference:** `client/src/views/settings/modals/tab-list-field-add.js`\n\n---\n\n#### 5. Edit Virtual Folder Modal (MEDIUM)\n\n| File Path | Purpose |\n|-----------|---------|\n| `client/custom/modules/global/src/views/settings/modals/edit-tab-virtual-folder.js` | Modal for configuring virtual folder properties |\n\n**Structure:** Follows pattern from `views/settings/modals/edit-tab-group.js`\n\n**Fields:**\n- `label` (varchar) - Custom display label\n- `entityType` (enum) - Select from scopes with `tab: true`\n- `filterName` (enum) - Dynamic options based on selected entityType (presetFilters from clientDefs + user preferences)\n- `maxItems` (int) - Default 5\n- `iconClass` (base with icon-class view)\n- `color` (colorpicker)\n- `orderBy` (enum) - Fields from selected entity\n- `order` (enum) - asc/desc\n\n**Reference:** `client/src/views/settings/modals/edit-tab-group.js`\n\n---\n\n#### 6. Entity Type Filter Field (MEDIUM)\n\n| File Path | Purpose |\n|-----------|---------|\n| `client/custom/modules/global/src/views/settings/fields/virtual-folder-entity.js` | Field for selecting entity type and loading available filters |\n\n**Implementation:**\n- Extends `views/fields/enum`\n- `setupOptions()`: Load scopes with `tab: true` and ACL read access\n- On change: triggers event to reload filterName options\n\n---\n\n#### 7. Filter Name Field (MEDIUM)\n\n| File Path | Purpose |\n|-----------|---------|\n| `client/custom/modules/global/src/views/settings/fields/virtual-folder-filter.js` | Field for selecting preset filter based on selected entity |\n\n**Implementation:**\n- Extends `views/fields/enum`\n- Dynamic options based on `entityType` field value\n- Merges: clientDefs filterList + user presetFilters for that entity\n- Includes empty option for \"no filter\"\n\n---\n\n#### 8. Tab List Field View Extension (LOW)\n\n| File Path | Purpose |\n|-----------|---------|\n| `client/custom/modules/global/src/views/settings/fields/tab-list.js` | Extends core tab-list field to handle virtual folder type |\n\n**Changes:**\n- Override `getGroupItemHtml()` to handle `type: 'virtualFolder'`\n- Add icon class `fas fa-folder` for virtual folder items\n- Add edit handler via `editVirtualFolder()` method\n- Reference modal: `global:views/settings/modals/edit-tab-virtual-folder`\n\n**Reference:** `client/src/views/settings/fields/tab-list.js`\n\n---\n\n### Files to EDIT\n\n#### 1. Custom Navbar View (CRITICAL)\n\n| File Path | Changes |\n|-----------|---------|\n| `client/custom/modules/global/src/views/site/navbar.js` | Add virtual folder handling in `prepareTabItemDefs()` and rendering |\n\n**Changes:**\n- Add `isTabVirtualFolder()` method to `TabsHelper` pattern\n- In `setupTabDefsList()`: Handle virtual folder items (fetch records, prepare data)\n- In `prepareTabItemDefs()`: Return virtual folder specific defs\n- Create virtual folder views during navbar setup\n\n**Pattern:** Follow existing group/divider handling in base navbar.js\n\n---\n\n#### 2. Tabs Helper Extension (HIGH)\n\n| File Path | Changes |\n|-----------|---------|\n| Create: `client/custom/modules/global/src/helpers/site/tabs.js` OR edit navbar.js directly | Add virtual folder detection method |\n\n**Add Method:**\n```javascript\nisTabVirtualFolder(item) {\n    return typeof item === 'object' && item.type === 'virtualFolder';\n}\n```\n\n---\n\n#### 3. Navbar Template (MEDIUM)\n\n| File Path | Changes |\n|-----------|---------|\n| Create: `client/custom/modules/global/res/templates/site/navbar.tpl` | Override core template to add virtual folder rendering |\n\n**Add after isDivider block:**\n```handlebars\n{{#if isVirtualFolder}}\n    {{{var virtualFolderKey}}}\n{{/if}}\n```\n\n---\n\n#### 4. Global CSS (LOW)\n\n| File Path | Changes |\n|-----------|---------|\n| `client/custom/modules/global/css/navbar-config-selector.css` OR new CSS file | Add import for virtual-folder.css |\n\n---\n\n#### 5. Global Translations (LOW)\n\n| File Path | Changes |\n|-----------|---------|\n| `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json` | Add translations for virtual folder labels |\n\n**Add to appropriate sections:**\n```json\n{\n    \"labels\": {\n        \"Virtual Folder\": \"Virtual Folder\",\n        \"Add Virtual Folder\": \"Add Virtual Folder\",\n        \"Edit Virtual Folder\": \"Edit Virtual Folder\"\n    },\n    \"fields\": {\n        \"entityType\": \"Entity\",\n        \"filterName\": \"Filter\",\n        \"maxItems\": \"Max Items\",\n        \"orderBy\": \"Order By\"\n    }\n}\n```\n\n---\n\n#### 6. Settings Translations (LOW)\n\n| File Path | Changes |\n|-----------|---------|\n| `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json` | Add virtual folder related translations |\n\n---\n\n### Files to CONSIDER\n\n| File Path | Reason |\n|-----------|--------|\n| `application/Espo/Resources/metadata/entityDefs/SidenavConfig.json` | If SidenavConfig.tabList needs custom view override |\n| `client/custom/modules/global/src/views/modals/navbar-config-field-add.js` | May need update if used in SidenavConfig editing context |\n\n---\n\n### Related Files (for reference only, no changes needed)\n\n| File Path | Pattern Reference |\n|-----------|-------------------|\n| `client/src/views/settings/modals/tab-list-field-add.js` | Pattern for adding addGroup/addDivider/addUrl buttons |\n| `client/src/views/settings/modals/edit-tab-group.js` | Pattern for modal with dynamic field model |\n| `client/src/views/settings/modals/edit-tab-url.js` | Another modal pattern |\n| `client/src/views/settings/fields/tab-list.js` | Pattern for handling complex tab items |\n| `client/src/views/site/navbar/quick-create.js` | Quick create implementation pattern |\n| `client/src/helpers/site/tabs.js` | Tab type detection pattern |\n| `client/src/views/site/navbar.js` | Tab rendering and preparation pattern |\n| `client/custom/modules/global/src/views/site/navbar.js` | Existing custom navbar with team configs |\n| `client/custom/modules/global/src/views/activities/fields/name-with-icon.js` | Entity icon retrieval pattern |\n| `client/src/views/record/search.js` | presetFilters handling pattern |\n| `frontend/less/espo/root-variables.less` | CSS variables for consistent styling |\n\n---\n\n## Implementation Order\n\n### Phase 1: Data Model & Core Modal\n1. Create `edit-tab-virtual-folder.js` modal\n2. Create `virtual-folder-entity.js` field\n3. Create `virtual-folder-filter.js` field\n4. Add translations\n\n### Phase 2: Tab List Integration\n1. Extend `tab-list-field-add.js` (or create global override)\n2. Extend `tab-list.js` field view (or create global override)\n3. Test virtual folder item creation/editing in tab list\n\n### Phase 3: Navbar Rendering\n1. Create `virtual-folder.js` view\n2. Create `virtual-folder.tpl` template\n3. Create `virtual-folder.css` styles\n4. Update `navbar.js` to handle virtual folders\n5. Update navbar template if needed\n\n### Phase 4: Testing & Polish\n1. Test collapse/expand persistence\n2. Test quick create functionality\n3. Test refresh action\n4. Test with various entity types\n5. Test with user preset filters\n6. Test ACL restrictions\n7. Mobile/responsive behavior\n\n---\n\n## Error Handling\n\n### Invalid Entity Type\n- If `entityType` is disabled or ACL denied → hide virtual folder\n- Log warning in console\n\n### Invalid Filter\n- If `filterName` doesn't exist → fall back to no filter\n- Use entity default list view\n\n### Fetch Error\n- Show \"Failed to load\" message in virtual folder\n- Provide retry button in more options\n\n### No Records\n- Show empty state message: \"No records found\"\n- Still show quick create button\n\n---\n\n## Summary of File Count\n\n| Category | Count |\n|----------|-------|\n| CREATE | 8 files |\n| EDIT | 6 files |\n| CONSIDER | 2 files |\n| Reference | 12 files |\n\n---\n\n## UI Mockup Description\n\n```\n┌─────────────────────────────────┐\n│ [⚙] Business Config       [▼]  │  <- Config selector\n├─────────────────────────────────┤\n│ 🏠 Home                         │\n├─────────────────────────────────┤\n│ 👥 Accounts                     │\n├─────────────────────────────────┤\n│ 📋 MY OPEN TASKS          [+] [⋮]│  <- Virtual folder header\n│   ▼                             │     (hover shows + and ⋮)\n│   ├─ Follow up with John        │\n│   ├─ Review proposal            │\n│   ├─ Schedule meeting           │\n│   ├─ Send invoice               │\n│   ├─ Call client                │\n│   └─ View all (12)              │  <- Link to full list\n├─────────────────────────────────┤\n│ 💼 Opportunities                │\n├─────────────────────────────────┤\n│ 📊 Reports                      │\n└─────────────────────────────────┘\n\n[+] button → Opens quick create modal for Task\n[⋮] menu:\n  - Refresh\n  - View All\n  - Edit Folder\n```\n\n---\n\n*Scope document v1 - SCOPE MAPPED*\nEOF\necho \"Scope document created successfully\"",
  "description": "Create virtual-folder-tablist-type scope document"
}
```

**Output:**
```
Scope document created successfully

```

---

## Assistant (Scope · glm-5-free)

---

