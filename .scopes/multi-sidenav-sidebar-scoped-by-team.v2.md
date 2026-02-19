# Multi-Sidenav Sidebar Scoped by Team - Implementation Scope v2

> **Version**: 2.0  
> **Based on**: v1 scope + v1.audit findings  
> **Codebase Root**: `components/crm/source/`  
> **Status**: SCOPE MAPPED

## Overview

Move navbar configuration from User Interface (Settings/Preferences) to a team-scoped adminForUser panel. Each team can configure its own navbar tabLists, and users see configs from all teams they belong to.

### Key Changes from v1
- **Critical Fix**: Added backend AppParam implementation (`TeamSidenavConfigs.php`)
- **Critical Fix**: Added clientDefs metadata for SidenavConfig entity
- **Warning Addressed**: Added Configurations.json i18n file
- **Warning Addressed**: Specified exact adminForUserPanel.json edit
- **Suggestion Adopted**: Added teamId index for query optimization

---

## Decisions

| # | Decision | Alternatives Considered | Rationale |
|---|----------|------------------------|-----------|
| 1 | Create new `SidenavConfig` entity linked to Team | Add fields directly to Team entity | Allows multiple configs per team, follows existing adminForUser entity patterns (ChatwootInboxIntegration, MsxGoogleCalendarUser) |
| 2 | User's `teams` determines which configs are available | Use only `defaultTeam` | Users get access to configs from ALL teams they belong to, providing flexibility for multi-team users |
| 3 | Keep `activeNavbarConfigId` in Preferences | Store in SidenavConfig or new entity | User's active selection is personal preference, follows existing pattern |
| 4 | Keep `navbarConfigShowDefaultTabList` in Settings | Add to SidenavConfig or Preferences | System-level setting that applies globally, controlled by admin |
| 5 | Remove Settings-level navbar config fields entirely | Keep as fallback/global override | Eliminates complexity of 3-level resolution (team → system → default) |
| 6 | Remove `useCustomNavbarConfig` and Preferences `navbarConfigList` | Keep for user-level override | Team-based configs replace user-level customization |
| 7 | Fallback to legacy `tabList` if no team configs exist | Require configs for all users | Backward compatible with existing installations |
| 8 | Admins without team see system `tabList` | Create implicit "Global" team | Edge case - most users belong to at least one team |
| 9 | Use special ID `__default_tablist__` for default tabList option | Create pseudo-config record | Simple, no database changes needed, easy to detect in resolution logic |
| 10 | Load team configs via AppParam `teamSidenavConfigs` | AJAX call to custom endpoint | AppParam loads with user session data, more efficient, follows Chatwoot pattern |
| 11 | Add index on `teamId` field | No index | AppParam queries by teamId, improves performance for systems with many configs |

---

## File Manifest

### Files to CREATE (ordered by complexity/risk, highest first)

#### 1. Backend AppParam Class (CRITICAL - NEW IN v2)

| File Path | Purpose |
|-----------|---------|
| `custom/Espo/Modules/Global/Classes/AppParams/TeamSidenavConfigs.php` | Implements `Espo\Tools\App\AppParam` interface. Loads all SidenavConfig records for user's teams. Returns array of config objects with id, name, teamId, iconClass, color, tabList, isDefault. Follows pattern from `ChatwootSsoUrl.php`. |

**Key Implementation Details:**
- Constructor: inject `User` and `EntityManager`
- `get()` method: query `SidenavConfig` entity where `teamId` IN user's teamIds
- Return structure: flat array of config objects
- Handle empty teams case: return `[]`

**Reference Pattern:** `custom/Espo/Modules/Chatwoot/Classes/AppParams/ChatwootSsoUrl.php`

#### 2. Entity Definition (CRITICAL)

| File Path | Purpose |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json` | New entity for team-scoped navbar configurations. Pattern follows `ChatwootInboxIntegration.json` and `MsxGoogleCalendarUser.json`. Includes `team` link (required), `tabList` array field with `views/settings/fields/tab-list` view, `isDefault` bool, index on `teamId`. |

**Key Fields:**
- `name`: varchar, required
- `team`: link to Team, required
- `iconClass`: varchar (optional, for display)
- `color`: varchar max 7 (hex color)
- `tabList`: array with view `views/settings/fields/tab-list`
- `isDefault`: bool, default false
- Standard audit fields: `createdAt`, `modifiedAt`, `createdBy`, `modifiedBy`
- `teams`: linkMultiple (for ACL)

**Indexes:**
```json
"indexes": {
    "teamId": {
        "columns": ["teamId", "deleted"]
    }
}
```

**Reference Pattern:** `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/ChatwootInboxIntegration.json`

#### 3. Navbar View Rewrite (CRITICAL)

| File Path | Purpose |
|-----------|---------|
| `client/custom/modules/global/src/views/site/navbar.js` | Rewrite config resolution logic. Replace `getNavbarConfigList()` to fetch from `teamSidenavConfigs` appParam instead of Settings/Preferences. Add `DEFAULT_TABLIST_ID` constant and `getLegacyTabList()` method. |

**Key Changes:**
- Add constant: `const DEFAULT_TABLIST_ID = '__default_tablist__';`
- Rewrite `getNavbarConfigList()`: 
  - Get `userTeamIds` from `this.getUser().get('teamsIds')`
  - Get `teamConfigs` from `this.getHelper().getAppParam('teamSidenavConfigs')`
  - Filter configs by user's team IDs
  - Add default tabList option if `navbarConfigShowDefaultTabList` setting is enabled
- Rewrite `getActiveNavbarConfig()`: handle `DEFAULT_TABLIST_ID` selection
- Rewrite `getTabList()`: handle `isDefaultTabList` flag
- Add `getLegacyTabList()`: call `super.getTabList()` and filter
- Update `shouldShowConfigSelector()`: remove `navbarConfigSelectorDisabled` check, use team configs
- Update `setup()` preference listener: remove `navbarConfigList`, `useCustomNavbarConfig` listeners

#### 4. Active Navbar Config Field Rewrite (HIGH)

| File Path | Purpose |
|-----------|---------|
| `client/custom/modules/global/src/views/preferences/fields/active-navbar-config.js` | Rewrite to fetch options from team configs via appParam instead of Settings/Preferences configList. |

**Key Changes:**
- Rewrite `setupOptions()`:
  - Get `userTeamIds` from `this.getUser().get('teamsIds')`
  - Get `teamConfigs` from `this.getHelper().getAppParam('teamSidenavConfigs')`
  - Filter by user's teams
  - Add default tabList option if enabled
- Remove listeners for `navbarConfigList`, `useCustomNavbarConfig`
- Remove `getResolvedConfigList()` method (no longer needed)
- Remove `navbarConfigDisabled` check (no longer applicable)

#### 5. Entity Scope Definition

| File Path | Purpose |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/metadata/scopes/SidenavConfig.json` | Entity scope definition. Pattern follows `MsxGoogleCalendarUser.json`. |

**Structure:**
```json
{
    "entity": true,
    "layouts": true,
    "tab": false,
    "acl": "table",
    "module": "Global",
    "customizable": false,
    "importable": false,
    "object": true,
    "type": "Base",
    "hasTeams": true
}
```

#### 6. ClientDefs Metadata (NEW IN v2 - CRITICAL FIX)

| File Path | Purpose |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/metadata/clientDefs/SidenavConfig.json` | Client-side entity metadata. Required for proper rendering in adminForUser panel. |

**Structure:**
```json
{
    "controller": "controllers/record",
    "iconClass": "fas fa-bars",
    "createDisabled": false,
    "defaultSidePanelFieldLists": {
        "detail": ["teams"],
        "edit": ["teams"],
        "detailSmall": ["teams"]
    }
}
```

**Reference Pattern:** `custom/Espo/Modules/PackEnterprise/Resources/metadata/clientDefs/MsxGoogleCalendarUser.json`

#### 7. AppParams Metadata (NEW IN v2 - CRITICAL FIX)

| File Path | Purpose |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/metadata/app/appParams.json` | Register the `teamSidenavConfigs` appParam. Currently empty `{}`. |

**Edit: Replace entire content with:**
```json
{
    "teamSidenavConfigs": {
        "className": "Espo\\Modules\\Global\\Classes\\AppParams\\TeamSidenavConfigs"
    }
}
```

**Reference Pattern:** `custom/Espo/Modules/Chatwoot/Resources/metadata/app/appParams.json`

#### 8. adminForUserPanel Entry

| File Path | Purpose |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/metadata/app/adminForUserPanel.json` | Add "sidenav" panel entry. |

**Edit: Add to existing object (preserve existing `users`, `data`, `sales` keys):**
```json
"sidenav": {
    "label": "Sidenav",
    "itemList": [
        {
            "url": "#Configurations/SidenavConfig",
            "label": "Sidenav Configs",
            "iconClass": "fas fa-bars",
            "description": "sidenavConfigs"
        }
    ],
    "order": 10
}
```

#### 9. Entity Layouts

| File Path | Purpose |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detail.json` | Detail view layout. Rows: name, team, iconClass/color, isDefault, tabList (fullWidth). |
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/list.json` | List view layout. Columns: name, team, isDefault. |
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detailSmall.json` | Small detail view for quick editing. |

**detail.json structure:**
```json
[
    {
        "label": "Overview",
        "rows": [
            [{"name": "name"}, {"name": "team"}],
            [{"name": "iconClass"}, {"name": "color"}],
            [{"name": "isDefault"}, false],
            [{"name": "tabList", "fullWidth": true}]
        ]
    }
]
```

**list.json structure:**
```json
[
    {"name": "name", "width": 30},
    {"name": "team", "width": 40},
    {"name": "isDefault", "width": 15}
]
```

#### 10. Entity Translations

| File Path | Purpose |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/i18n/en_US/SidenavConfig.json` | Entity translations: scopeNames, field labels, tooltips. |

**Structure:**
```json
{
    "scopeNames": {
        "SidenavConfig": "Sidenav Configuration"
    },
    "scopeNamesPlural": {
        "SidenavConfig": "Sidenav Configurations"
    },
    "fields": {
        "name": "Name",
        "team": "Team",
        "iconClass": "Icon",
        "color": "Color",
        "tabList": "Tab List",
        "isDefault": "Default"
    },
    "tooltips": {
        "isDefault": "If checked, this configuration will be the default for users in this team who haven't selected a specific config."
    }
}
```

#### 11. Configurations Translations (NEW IN v2 - WARNING FIX)

| File Path | Purpose |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Configurations.json` | Panel labels for adminForUser. Used by `admin-for-user/index.js` at lines 74-78, 97-101. |

**Structure:**
```json
{
    "labels": {
        "Sidenav": "Navigation",
        "Sidenav Configs": "Sidenav Configurations"
    },
    "descriptions": {
        "sidenavConfigs": "Configure team-specific navigation sidebars"
    },
    "keywords": {
        "sidenavConfigs": "navigation,sidebar,menu,tabs"
    }
}
```

**Reference Pattern:** `custom/Espo/Modules/Chatwoot/Resources/i18n/en_US/Configurations.json`

---

### Files to EDIT

#### 1. Settings Entity Definition

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Settings.json` | DELETE fields: `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. ADD field: `navbarConfigShowDefaultTabList` (bool, default: false, tooltip: true). |

**New field to add:**
```json
"navbarConfigShowDefaultTabList": {
    "type": "bool",
    "default": false,
    "tooltip": true
}
```

#### 2. Preferences Entity Definition

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Preferences.json` | DELETE fields: `navbarConfigList`, `useCustomNavbarConfig`. KEEP `activeNavbarConfigId`. |

#### 3. Settings Layout

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/layouts/Settings/userInterface.json` | DELETE rows containing `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. ADD row with `navbarConfigShowDefaultTabList` in Navbar tab section. |

**Edit the section after the "tabList" rows in the Navbar tab:**
```json
{
    "rows": [
        [{"name": "navbarConfigShowDefaultTabList"}, false]
    ]
}
```

#### 4. Preferences Layout

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/layouts/Preferences/detail.json` | DELETE rows 124-137 containing `useCustomNavbarConfig`, `navbarConfigList`. KEEP `activeNavbarConfigId` - move to User Interface section after tabList rows. |

**Move `activeNavbarConfigId` to User Interface section:**
Add to the User Interface tab section (after the tabList rows around line 111-121):
```json
{
    "rows": [
        [{"name": "activeNavbarConfigId"}, false]
    ]
}
```

#### 5. Settings Translations

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json` | DELETE translations for `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. ADD translations for `navbarConfigShowDefaultTabList`. DELETE labels: `Edit Navbar Configuration`, `Add Navbar Configuration`, `Navbar Configuration`. |

**New translations:**
```json
{
    "fields": {
        "navbarConfigShowDefaultTabList": "Show Default Tab List Option"
    },
    "tooltips": {
        "navbarConfigShowDefaultTabList": "If checked, users will see a 'Default' option in the navbar config selector that uses the system-level tabList. This allows users to switch back to the default navigation from team-specific configs."
    }
}
```

#### 6. Preferences Translations

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Preferences.json` | DELETE translations for `navbarConfigList`, `useCustomNavbarConfig`. KEEP `activeNavbarConfigId` - update tooltip to mention team configs. |

**Updated tooltip:**
```json
{
    "tooltips": {
        "activeNavbarConfigId": "Select your active navbar configuration from the options provided by your teams. Use 'Default' to use the system-level tab list."
    }
}
```

#### 7. Global Translations

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json` | ADD `scopeNames` and `scopeNamesPlural` for SidenavConfig (as fallback). Update `navbarConfig.defaultConfig` translation. |

---

### Files to DELETE

| File Path | Reason |
|-----------|--------|
| `client/custom/modules/global/src/views/settings/fields/navbar-config-list.js` | No longer needed - configs are entity records, not Settings field. |
| `client/custom/modules/global/src/views/preferences/fields/navbar-config-list.js` | No longer needed - users don't create custom configs. |
| `client/custom/modules/global/src/views/settings/modals/edit-navbar-config.js` | No longer needed - editing happens via SidenavConfig record edit view. |

---

### Files to CONSIDER

| File Path | Reason |
|-----------|--------|
| `client/custom/modules/global/src/views/preferences/record/edit.js` | May need to hide `activeNavbarConfigId` field if user has no teams or no configs available. Consider dynamic visibility. |
| `custom/Espo/Modules/Global/Classes/RecordHooks/SidenavConfig/BeforeSave.php` | Optional: Validate only one config per team has `isDefault=true`. Clears previous default when new default is set. |
| Migration script or InstallActions | Optional: Copy existing `Settings.navbarConfigList` to a default team's SidenavConfig records for backward compatibility. |

---

### Related Files (for reference only, no changes needed)

| File Path | Pattern Reference |
|-----------|-------------------|
| `client/custom/modules/global/src/views/site/navbar-config-selector.js` | Selector component - should work with updated resolution logic |
| `client/custom/modules/global/css/navbar-config-selector.css` | Selector styles - no changes needed |
| `client/custom/modules/global/src/controllers/admin-for-user.js` | Controller for adminForUser routing |
| `client/custom/modules/global/src/views/admin-for-user/index.js` | Panel index view with ACL filtering - uses `Configurations` scope for translations |
| `application/Espo/Tools/App/AppParam.php` | Interface definition for AppParam implementations |
| `application/Espo/Tools/App/AppService.php` | Loads appParams from metadata - lines 181-203 |
| `application/Espo/Resources/metadata/entityDefs/Team.json` | Team entity structure |
| `application/Espo/Resources/metadata/entityDefs/User.json` | User entity with teams linkMultiple |
| `application/Espo/Resources/metadata/entityDefs/Preferences.json` | Base Preferences entity |

---

## Error Handling

### Missing Teams Fallback
- User has no `teamsIds` or empty array → return empty config list → triggers legacy `tabList` fallback

### Invalid Active Config ID
- `activeNavbarConfigId` references deleted config → log warning → fall back to `isDefault` or first config
- `activeNavbarConfigId` is `DEFAULT_TABLIST_ID` but setting is disabled → fall back to first team config

### Default TabList Option
- `navbarConfigShowDefaultTabList` is disabled → default option not added to selector
- User selects default tabList → uses system `tabList` from Settings
- Switching from default tabList to team config → normal config resolution applies

### AJAX Error Handling
- Maintain existing error handling in `switchNavbarConfig()` method
- AppParam errors handled by Espo core (returns null/empty)

---

## No Migration Strategy Required (Default)

- Existing `tabList` preferences continue to work unchanged as fallback
- SidenavConfig system activates only when team has config records
- Admins explicitly create team configs via adminForUser panel

---

## Implementation Order

### Phase 1: Backend Foundation
1. Create `TeamSidenavConfigs.php` AppParam class
2. Update `appParams.json` metadata
3. Create `SidenavConfig` entity definition
4. Create `SidenavConfig` scope definition
5. Create `SidenavConfig` clientDefs metadata

### Phase 2: UI Components
1. Create SidenavConfig layouts (detail, list, detailSmall)
2. Create SidenavConfig translations
3. Create Configurations translations
4. Add adminForUser panel entry

### Phase 3: Frontend Logic
1. Update navbar.js resolution logic
2. Update active-navbar-config.js field
3. Update Settings/Preferences layouts
4. Update Settings/Preferences entityDefs
5. Update Settings/Preferences translations

### Phase 4: Cleanup
1. Delete deprecated views/modals
2. Remove old fields from Settings/Preferences

### Phase 5: Testing
1. Test team config creation via adminForUser
2. Test resolution with user having single team
3. Test resolution with user having multiple teams
4. Test resolution with user having no teams
5. Test active config selection
6. Test fallback to legacy tabList
7. Test selector visibility (hidden when ≤1 config)
8. Test ACL filtering in adminForUser panel
9. Test `navbarConfigShowDefaultTabList` setting
10. Test selecting default tabList option

---

## Summary of File Count

| Category | Count |
|----------|-------|
| CREATE | 11 files |
| EDIT | 7 files |
| DELETE | 3 files |
| CONSIDER | 3 items |
| Reference | 12 files |

---

*v2 scope document generated addressing all critical findings from v1.audit*
