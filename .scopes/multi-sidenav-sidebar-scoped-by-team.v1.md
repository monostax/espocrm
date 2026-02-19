# Multi-Sidenav Sidebar Scoped by Team - Implementation Plan v1

> **Version**: 1.0  
> **Based on**: `.scopes/multi-sidenav-sidebar.v10.md` (implemented)  
> **Codebase Root**: `components/crm/source/`  
> **Status**: SCOPE MAPPED

## Overview

Move navbar configuration from User Interface (Settings/Preferences) to a team-scoped adminForUser panel. Each team can configure its own navbar tabLists, and users see configs from all teams they belong to.

### Requirements
1. **Team-Level Configuration**: Each team defines its own navbar configs
2. **User Resolution**: User's `teams` determines available configs (all teams user belongs to)
3. **User Selection**: Users select active config from their teams' options
4. **adminForUser Panel**: Configuration accessed via `#Configurations/SidenavConfig`
5. **Default TabList Option**: System default `tabList` can be shown as a dropdown option (enable/disable via setting)

---

## Decisions Made

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

---

## Current Implementation (v10)

The existing implementation stores navbar configs in:

| Location | Fields | Purpose |
|----------|--------|---------|
| `Settings.json` | `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled` | System-level configs |
| `Preferences.json` | `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId` | User-level overrides and active selection |

### Resolution Logic (Current)

```
Priority:
1. Navbar config system (if navbarConfigList exists)
2. Legacy tab customization (useCustomTabList/addCustomTabs)
3. System default tabList
```

### Files Implemented in v10

**JavaScript Views:**
- `client/custom/modules/global/src/views/site/navbar.js` - Config resolution, selector setup
- `client/custom/modules/global/src/views/site/navbar-config-selector.js` - Selector dropdown
- `client/custom/modules/global/src/views/settings/fields/navbar-config-list.js` - Settings field
- `client/custom/modules/global/src/views/preferences/fields/navbar-config-list.js` - Preferences field
- `client/custom/modules/global/src/views/preferences/fields/active-navbar-config.js` - Active config dropdown
- `client/custom/modules/global/src/views/modals/navbar-config-field-add.js` - Add item modal
- `client/custom/modules/global/src/views/settings/modals/edit-navbar-config.js` - Edit config modal

**Metadata:**
- `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Settings.json`
- `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Preferences.json`
- `custom/Espo/Modules/Global/Resources/layouts/Settings/userInterface.json`
- `custom/Espo/Modules/Global/Resources/layouts/Preferences/detail.json`

**Translations:**
- `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json`
- `custom/Espo/Modules/Global/Resources/i18n/en_US/Preferences.json`
- `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json`

**Styles:**
- `client/custom/modules/global/css/navbar-config-selector.css`

---

## New Architecture (Team-Scoped)

### Data Model

#### New Entity: SidenavConfig

```json
{
  "fields": {
    "name": {
      "type": "varchar",
      "required": true,
      "maxLength": 100
    },
    "team": {
      "type": "link",
      "required": true
    },
    "iconClass": {
      "type": "varchar",
      "maxLength": 100
    },
    "color": {
      "type": "varchar",
      "maxLength": 7
    },
    "tabList": {
      "type": "array",
      "view": "views/settings/fields/tab-list"
    },
    "isDefault": {
      "type": "bool",
      "default": false
    },
    "assignedUser": {
      "type": "link",
      "view": "views/fields/assigned-user"
    },
    "teams": {
      "type": "linkMultiple",
      "view": "views/fields/teams"
    },
    "createdAt": {
      "type": "datetime",
      "readOnly": true
    },
    "modifiedAt": {
      "type": "datetime",
      "readOnly": true
    },
    "createdBy": {
      "type": "link",
      "readOnly": true
    },
    "modifiedBy": {
      "type": "link",
      "readOnly": true
    }
  },
  "links": {
    "team": {
      "type": "belongsTo",
      "entity": "Team"
    },
    "assignedUser": {
      "type": "belongsTo",
      "entity": "User"
    },
    "teams": {
      "type": "hasMany",
      "entity": "Team",
      "relationName": "entityTeam"
    }
  },
  "collection": {
    "orderBy": "name",
    "order": "asc"
  }
}
```

### Resolution Logic (New)

```javascript
const DEFAULT_TABLIST_ID = '__default_tablist__';

/**
 * Resolution Priority Order:
 * 1. User's selected active config (team config or default tabList)
 * 2. First team config with isDefault=true
 * 3. First team config
 * 4. Legacy tabList (existing fallback)
 */

getNavbarConfigList() {
    const userTeamIds = this.getUser().get('teamsIds') || [];
    const teamConfigs = this.getHelper().getAppParam('teamSidenavConfigs') || [];
    
    const configs = teamConfigs.filter(c => userTeamIds.includes(c.teamId));
    
    // Add default tabList option if enabled
    if (this.getConfig().get('navbarConfigShowDefaultTabList')) {
        configs.push({
            id: DEFAULT_TABLIST_ID,
            name: this.translate('defaultConfig', 'navbarConfig', 'Global'),
            iconClass: 'fas fa-globe',
            isDefault: false,
            isDefaultTabList: true,
        });
    }
    
    return configs;
}

getActiveNavbarConfig() {
    const configList = this.getNavbarConfigList();
    const activeId = this.getPreferences().get('activeNavbarConfigId');
    
    // Handle default tabList selection
    if (activeId === DEFAULT_TABLIST_ID) {
        return configList.find(c => c.id === DEFAULT_TABLIST_ID) || null;
    }
    
    if (!configList || configList.length === 0) {
        return null;
    }
    
    if (activeId) {
        const found = configList.find(c => c.id === activeId);
        
        if (found) {
            return found;
        }
        
        console.warn('Active navbar config ID not found, falling back to default');
    }
    
    return configList.find(c => c.isDefault) || configList[0];
}

getTabList() {
    const activeConfig = this.getActiveNavbarConfig();
    
    // Handle default tabList
    if (activeConfig && activeConfig.isDefaultTabList) {
        return this.getLegacyTabList();
    }
    
    if (activeConfig && activeConfig.tabList) {
        let tabList = Espo.Utils.cloneDeep(activeConfig.tabList);
        
        if (this.isSide()) {
            tabList.unshift('Home');
        }
        
        return this.filterConversasItems(tabList);
    }
    
    // Fallback to legacy tabList
    return this.getLegacyTabList();
}

getLegacyTabList() {
    // Existing logic from parent class
    const tabList = super.getTabList();
    return this.filterConversasItems(tabList);
}
```

---

## File Manifest

### Files to CREATE

#### 1. Entity Definition (CRITICAL)

| File Path | Reason |
|-----------|--------|
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json` | New entity for team-scoped navbar configurations. Pattern follows `ChatwootInboxIntegration.json` and `MsxGoogleCalendarUser.json`. Includes `team` link (required), `tabList` array field, `isDefault` bool. |

#### 2. Entity Scope

| File Path | Reason |
|-----------|--------|
| `custom/Espo/Modules/Global/Resources/metadata/scopes/SidenavConfig.json` | Entity scope definition. `acl: "table"`, `tab: false`, `hasTeams: true`. Pattern follows `MsxGoogleCalendarUser.json`. |

#### 3. adminForUser Panel Entry

| File Path | Reason |
|-----------|--------|
| `custom/Espo/Modules/Global/Resources/metadata/app/adminForUserPanel.json` (EDIT) | Add "sidenav" panel entry with URL `#Configurations/SidenavConfig`. Pattern follows existing panels. |

#### 4. Layouts

| File Path | Reason |
|-----------|--------|
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detail.json` | Detail view layout. Rows: name, team, iconClass, color, isDefault, tabList. |
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/list.json` | List view layout. Columns: name, team, isDefault. |
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detailSmall.json` | Small detail view for quick editing. |

#### 5. Translations

| File Path | Reason |
|-----------|--------|
| `custom/Espo/Modules/Global/Resources/i18n/en_US/SidenavConfig.json` | Entity translations: scopeNames, field labels, tooltips. |
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Configurations.json` (or Global.json) | Panel label "Sidenav" for adminForUser. |

#### 6. Client Views (if needed)

| File Path | Reason |
|-----------|--------|
| `client/custom/modules/global/src/views/sidenav-config/fields/tab-list.js` (optional) | Custom tab-list field if needed for entity context. May reuse existing `views/settings/fields/tab-list.js`. |

---

### Files to EDIT

#### 1. Navbar View (CRITICAL REWRITE)

| File Path | Reason | Changes |
|-----------|--------|---------|
| `client/custom/modules/global/src/views/site/navbar.js` | Update resolution logic to use team configs | Rewrite `getNavbarConfigList()` to fetch from all user's teams' SidenavConfig records. Add appParam handling for `teamSidenavConfigs`. Handle case where user has no teams. |

#### 2. Preferences Active Config Field

| File Path | Reason | Changes |
|-----------|--------|---------|
| `client/custom/modules/global/src/views/preferences/fields/active-navbar-config.js` | Update to fetch options from team configs | Change from reading Settings/Preferences configList to fetching team's SidenavConfig records via AJAX or appParam. |

#### 3. Settings Layout

| File Path | Reason | Changes |
|-----------|--------|---------|
| `custom/Espo/Modules/Global/Resources/layouts/Settings/userInterface.json` | Update navbar config section | DELETE rows containing `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. ADD new field `navbarConfigShowDefaultTabList` to Navbar tab section. Keep tabList and other UI settings. |

#### 4. Preferences Layout

| File Path | Reason | Changes |
|-----------|--------|---------|
| `custom/Espo/Modules/Global/Resources/layouts/Preferences/detail.json` | Remove custom navbar config section | DELETE rows 124-137 containing `useCustomNavbarConfig`, `navbarConfigList`. KEEP `activeNavbarConfigId` field (move to User Interface section). |

#### 5. Settings Entity Definition

| File Path | Reason | Changes |
|-----------|--------|---------|
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Settings.json` | Update navbar config fields | DELETE fields: `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. ADD field: `navbarConfigShowDefaultTabList` (bool, default: false). |

#### 6. Preferences Entity Definition

| File Path | Reason | Changes |
|-----------|--------|---------|
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Preferences.json` | Remove custom config fields | DELETE fields: `navbarConfigList`, `useCustomNavbarConfig`. KEEP `activeNavbarConfigId`. |

#### 7. Settings Translations

| File Path | Reason | Changes |
|-----------|--------|---------|
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json` | Update navbar config translations | DELETE translations for `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. ADD translations for `navbarConfigShowDefaultTabList` (field label and tooltip). |

#### 8. Preferences Translations

| File Path | Reason | Changes |
|-----------|--------|---------|
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Preferences.json` | Remove custom config translations | DELETE translations for `navbarConfigList`, `useCustomNavbarConfig`. KEEP `activeNavbarConfigId` translation. Update tooltip to mention team configs. |

---

### Files to DELETE (or Deprecate)

| File Path | Reason |
|-----------|--------|
| `client/custom/modules/global/src/views/settings/fields/navbar-config-list.js` | No longer needed - configs are entity records, not Settings field. |
| `client/custom/modules/global/src/views/preferences/fields/navbar-config-list.js` | No longer needed - users don't create custom configs. |
| `client/custom/modules/global/src/views/settings/modals/edit-navbar-config.js` | No longer needed - editing happens via SidenavConfig record edit view. |

---

### Files to CONSIDER

| File Path | Reason |
|-----------|--------|
| Backend service for team config loading | May need API endpoint or appParam injection for `teamSidenavConfigs`. Could be added to `/api/v1/App/user` response. Should load configs for all teams the user belongs to. |
| `client/custom/modules/global/src/views/preferences/record/edit.js` | May need to hide `activeNavbarConfigId` if user has no teams or no configs. |
| Migration script | Optional: copy existing `Settings.navbarConfigList` to a default team's SidenavConfig records. |

---

### Related Files (Reference Only - No Changes)

| File Path | Pattern Reference |
|-----------|-------------------|
| `client/custom/modules/global/src/views/site/navbar-config-selector.js` | Selector component - should work with updated resolution logic |
| `client/custom/modules/global/css/navbar-config-selector.css` | Selector styles - no changes needed |
| `client/custom/modules/global/src/controllers/admin-for-user.js` | Controller for adminForUser routing |
| `client/custom/modules/global/src/views/admin-for-user/index.js` | Panel index view with ACL filtering |
| `custom/Espo/Modules/Chatwoot/Resources/metadata/entityDefs/ChatwootInboxIntegration.json` | Pattern for adminForUser entity with team link |
| `custom/Espo/Modules/PackEnterprise/Resources/metadata/entityDefs/MsxGoogleCalendarUser.json` | Pattern for adminForUser entity with user link |
| `custom/Espo/Modules/Global/Resources/metadata/app/adminForUserPanel.json` | Existing panel structure |
| `application/Espo/Resources/metadata/entityDefs/Team.json` | Team entity structure |
| `application/Espo/Resources/metadata/entityDefs/User.json` | User entity with defaultTeam link |

---

## Error Handling

### Missing Teams Fallback
- User has no `teamsIds` or empty array → return empty config list → triggers legacy `tabList` fallback
- User's teams have no SidenavConfig records → return empty config list → triggers legacy fallback

### Invalid Active Config ID
- `activeNavbarConfigId` references deleted config → log warning → fall back to `isDefault` or first config
- `activeNavbarConfigId` is `DEFAULT_TABLIST_ID` but setting is disabled → fall back to first team config

### Default TabList Option
- `navbarConfigShowDefaultTabList` is disabled → default option not added to selector
- User selects default tabList → uses system `tabList` from Settings
- Switching from default tabList to team config → normal config resolution applies

### AJAX Error Handling
- Maintain existing error handling in `switchNavbarConfig()` method
- Add error handling for team config loading

---

## No Migration Strategy Required (Default)

- Existing `tabList` preferences continue to work unchanged as fallback
- SidenavConfig system activates only when team has config records
- Admins explicitly create team configs via adminForUser panel

---

## Implementation Order

### Phase 1: Data Model
1. Create `SidenavConfig` entity definition
2. Create `SidenavConfig` scope
3. Add translations

### Phase 2: Backend Integration (if needed)
1. Add API endpoint or appParam for `teamSidenavConfigs`
2. Ensure configs load with user session

### Phase 3: UI Updates
1. Add adminForUser panel entry
2. Create SidenavConfig layouts
3. Update navbar.js resolution logic
4. Update active-navbar-config.js field

### Phase 4: Cleanup
1. Remove Settings navbar config fields
2. Remove Preferences custom config fields
3. Delete deprecated views/modals

### Phase 5: Testing (Manual)
1. Test team config creation via adminForUser
2. Test resolution with user having single team
3. Test resolution with user having multiple teams (configs from all teams should be available)
4. Test resolution with user having no teams
5. Test active config selection
6. Test fallback to legacy tabList
7. Test selector visibility (hidden when ≤1 config)
8. Test ACL filtering in adminForUser panel
9. Test `navbarConfigShowDefaultTabList` setting disabled (no default option in selector)
10. Test `navbarConfigShowDefaultTabList` setting enabled (default option appears in selector)
11. Test selecting default tabList option (uses system tabList)
12. Test switching between team config and default tabList

---

## Summary of File Count

| Category | Count |
|----------|-------|
| CREATE | 7 files |
| EDIT | 8 files |
| DELETE | 3 files |
| CONSIDER | 3 items |
| Reference | 9 files |

---

## Key Code Changes

### navbar.js - Constants and Resolution Logic

```javascript
const DEFAULT_TABLIST_ID = '__default_tablist__';

/**
 * Get the resolved navbar config list based on all user's teams.
 * Adds default tabList option if enabled in Settings.
 * @return {Object[]}
 */
getNavbarConfigList() {
    const userTeamIds = this.getUser().get('teamsIds') || [];
    const teamConfigs = this.getHelper().getAppParam('teamSidenavConfigs') || [];
    
    const configs = teamConfigs.filter(c => userTeamIds.includes(c.teamId));
    
    // Add default tabList option if enabled
    if (this.getConfig().get('navbarConfigShowDefaultTabList')) {
        configs.push({
            id: DEFAULT_TABLIST_ID,
            name: this.translate('defaultConfig', 'navbarConfig', 'Global'),
            iconClass: 'fas fa-globe',
            isDefault: false,
            isDefaultTabList: true,
        });
    }
    
    return configs;
}

/**
 * Get the active navbar config, handling default tabList selection.
 * @return {Object|null}
 */
getActiveNavbarConfig() {
    const configList = this.getNavbarConfigList();
    const activeId = this.getPreferences().get('activeNavbarConfigId');
    
    // Handle default tabList selection
    if (activeId === DEFAULT_TABLIST_ID) {
        return configList.find(c => c.id === DEFAULT_TABLIST_ID) || null;
    }
    
    if (!configList || configList.length === 0) {
        return null;
    }
    
    if (activeId) {
        const found = configList.find(c => c.id === activeId);
        
        if (found) {
            return found;
        }
        
        console.warn('Active navbar config ID not found, falling back to default');
    }
    
    return configList.find(c => c.isDefault) || configList[0];
}

/**
 * Get tab list with support for default tabList option.
 * @return {Array}
 */
getTabList() {
    const activeConfig = this.getActiveNavbarConfig();
    
    // Handle default tabList
    if (activeConfig && activeConfig.isDefaultTabList) {
        return this.getLegacyTabList();
    }
    
    if (activeConfig && activeConfig.tabList) {
        let tabList = Espo.Utils.cloneDeep(activeConfig.tabList);
        
        if (this.isSide()) {
            tabList.unshift('Home');
        }
        
        return this.filterConversasItems(tabList);
    }
    
    // Fallback to legacy tabList
    return this.getLegacyTabList();
}

/**
 * Get legacy tabList from parent class.
 * @return {Array}
 */
getLegacyTabList() {
    const tabList = super.getTabList();
    return this.filterConversasItems(tabList);
}
```

### active-navbar-config.js - Fetch Team Options with Default TabList

```javascript
const DEFAULT_TABLIST_ID = '__default_tablist__';

setup() {
    const userTeamIds = this.getUser().get('teamsIds') || [];
    const teamConfigs = this.getHelper().getAppParam('teamSidenavConfigs') || [];
    
    const configs = teamConfigs.filter(c => userTeamIds.includes(c.teamId));
    
    // Add default tabList option if enabled
    if (this.getConfig().get('navbarConfigShowDefaultTabList')) {
        configs.push({
            id: DEFAULT_TABLIST_ID,
            name: this.translate('defaultConfig', 'navbarConfig', 'Global'),
            isDefaultTabList: true,
        });
    }
    
    const teamOptions = configs.map(c => ({
        value: c.id,
        label: c.name + (c.isDefault ? ' (default)' : '') + (c.isDefaultTabList ? ' (system)' : ''),
    }));
    
    this.params.options = teamOptions.map(o => o.value);
    this.translatedOptions = {};
    teamOptions.forEach(o => {
        this.translatedOptions[o.value] = o.label;
    });
}
```

### Settings.json - New Field Definition

```json
{
    "fields": {
        "navbarConfigShowDefaultTabList": {
            "type": "bool",
            "default": false,
            "tooltip": true
        }
    }
}
```

### Settings.json - Translations

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

---

*Scope document v1 generated for team-scoped navbar configuration - SCOPE MAPPED*
