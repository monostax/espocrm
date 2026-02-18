# Multi-Sidenav Sidebar Mode - Research & Implementation Plan

> **Status**: AUDITED - Contains path errors, see v2.md for corrected version  
> **Audit Date**: 2026-02-18  
> **Audit File**: `.scopes/multi-sidenav-sidebar.v1.audit.md`

## Overview

Feature request to implement a multi-sidenav sidebar mode allowing users to toggle between different navbar configurations using a dropdown selector in the sidebar.

### Requirements
1. **UI Pattern**: Dropdown/selector in the sidebar for switching views
2. **Configuration**: Each navbar config has its own complete `tabList`
3. **Levels**: Both system-level defaults and user-level overrides
4. **Quantity**: Unlimited configurable navbar views

---

## Current System Architecture

### Existing Navbar Modes
- **Location**: `application/Espo/Resources/metadata/themes/Espo.json`
- Two modes supported: `side` (sidebar) and `top` (horizontal navbar)
- Configured via theme `params.navbar` enum field

```json
{
  "params": {
    "navbar": {
      "type": "enum",
      "default": "side",
      "options": ["side", "top"]
    }
  }
}
```

### Current Tab List Structure
- **Settings Field**: `tabList` in `application/Espo/Resources/metadata/entityDefs/Settings.json:245`
- **Preferences Field**: `tabList` in `application/Espo/Resources/metadata/entityDefs/Preferences.json:171`
- **Field View**: `client/src/views/settings/fields/tab-list.js`
- **Helper Class**: `client/src/helpers/site/tabs.js`

### Existing Preference Fields (CRITICAL)
The following existing fields must be considered in resolution logic:
- `useCustomTabList` (bool) - User has custom tab list enabled
- `addCustomTabs` (bool) - User's tabs are additive to system tabs
- `tabList` (array) - User's custom tab list

**See**: `client/src/helpers/site/tabs.js:68-77` for existing logic

### Tab List Item Types
1. **Scope**: String entity name (e.g., `"Accounts"`, `"Contacts"`)
2. **Group**: Object with `type: "group"`, `text`, `iconClass`, `color`, `itemList`
3. **URL**: Object with `type: "url"`, `text`, `url`, `iconClass`, `color`, `aclScope`, `onlyAdmin`
4. **Divider**: Object with `type: "divider"`, `text`
5. **Delimiter**: String `"_delimiter_"` or `"_delimiter-ext_"` for more menu split

### Key Files Analyzed

| File | Purpose |
|------|---------|
| `client/src/views/site/navbar.js` | Main navbar view, handles tab rendering and logic |
| `client/res/templates/site/navbar.tpl` | Navbar HTML template |
| `client/src/helpers/site/tabs.js` | Tab list helper, `getTabList()` method |
| `client/src/views/settings/fields/tab-list.js` | Tab list field editor |
| `client/src/views/settings/fields/theme.js` | Theme field with navbar selector |
| `application/Espo/Resources/layouts/Settings/userInterface.json` | Admin UI layout |
| `frontend/less/espo/elements/navbar.less` | Navbar CSS styles |

---

## Data Model Design

### New Navbar Configuration Object
```json
{
  "id": "navbar-config-123",
  "name": "Business",
  "iconClass": "fas fa-briefcase",
  "color": "#4A90D9",
  "tabList": [
    "Home",
    "Accounts",
    "Contacts",
    {"type": "group", "text": "Sales", "itemList": ["Opportunities", "Leads"]}
  ],
  "isDefault": false
}
```

### Settings Fields (System Level)
| Field | Type | Description |
|-------|------|-------------|
| `navbarConfigList` | `jsonArray` | Array of navbar configuration objects |
| `navbarConfigDisabled` | `bool` | Disable user customization |
| `navbarConfigSelectorDisabled` | `bool` | Hide selector dropdown in navbar |

### Preferences Fields (User Level)
| Field | Type | Description |
|-------|------|-------------|
| `navbarConfigList` | `jsonArray` | User's custom navbar configurations |
| `useCustomNavbarConfig` | `bool` | Use user configs instead of system |
| `activeNavbarConfigId` | `varchar` | ID of currently active configuration |

---

## Resolution Logic

> ⚠️ **AUDIT FINDING**: Resolution logic incomplete - must account for existing `useCustomTabList` and `addCustomTabs`

```
Active Navbar Config Resolution:
1. If navbarConfigDisabled → Use system default config
2. If useCustomNavbarConfig && user has configs → Use user's activeNavbarConfigId
3. If user has no active config → Use first user config or system default
4. Fallback → Use system default config or original tabList

// MISSING: Need to handle existing useCustomTabList/addCustomTabs
// If user has useCustomTabList=true but no navbarConfigList:
//   → Use existing tabList preference (backward compatibility)
```

---

## File Manifest

### ⚠️ Files to CREATE (CONTAINS PATH ERRORS - See v2.md for corrections)

#### Views - Admin UI
| File | Purpose |
|------|---------|
| `client/src/views/admin/navbar-config/index.js` | Main admin view for managing navbar configs |
| `client/src/views/admin/navbar-config/item-list.js` | List view showing all navbar configurations |
| `client/src/views/admin/navbar-config/record/edit.js` | Edit view for single navbar config |
| `client/src/res/templates/admin/navbar-config/index.tpl` | ❌ WRONG PATH - should be `client/res/templates/` |
| `client/src/res/templates/admin/navbar-config/item-list.tpl` | ❌ WRONG PATH - should be `client/res/templates/` |

#### Views - Field Views
| File | Purpose |
|------|---------|
| `client/src/views/settings/fields/navbar-config-list.js` | Field view for managing navbar configs |
| `client/src/views/settings/modals/edit-navbar-config.js` | Modal for editing a navbar config |
| `client/src/views/settings/modals/navbar-config-add.js` | Modal for adding new navbar config |
| `client/src/res/templates/settings/fields/navbar-config-list.tpl` | ❌ WRONG PATH - should be `client/res/templates/` |

#### Views - Preferences
| File | Purpose |
|------|---------|
| `client/src/views/preferences/fields/navbar-config-list.js` | User preferences field view |
| `client/src/views/preferences/fields/active-navbar-config.js` | Dropdown to select active config |

#### Views - Navbar UI
| File | Purpose |
|------|---------|
| `client/src/views/site/navbar-config-selector.js` | Dropdown selector component |
| `client/src/res/templates/site/navbar-config-selector.tpl` | ❌ WRONG PATH - should be `client/res/templates/` |
| `client/src/handlers/site/navbar-config.js` | ❌ WRONG PATH - should be flat `client/src/handlers/navbar-config.js` |

#### Styles
| File | Purpose |
|------|---------|
| `frontend/less/espo/components/navbar-config-selector.less` | ❌ WRONG PATH - no `components/` directory exists |

### Files to EDIT

| File | Changes |
|------|---------|
| `application/Espo/Resources/metadata/entityDefs/Settings.json` | Add `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled` fields |
| `application/Espo/Resources/metadata/entityDefs/Preferences.json` | Add `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId` fields |
| `client/src/views/site/navbar.js` | Modify `getTabList()`, add config selector initialization, add `switchNavbarConfig()` |
| `client/src/helpers/site/tabs.js` | Add `getNavbarConfigList()`, `getActiveNavbarConfig()`, modify `getTabList()` |
| `client/res/templates/site/navbar.tpl` | Add navbar config selector element |
| `frontend/less/espo/elements/navbar.less` | Add styles for config selector |
| `application/Espo/Resources/layouts/Settings/userInterface.json` | Add navbar config fields to layout |
| `application/Espo/Resources/i18n/en_US/Admin.json` | Add labels and descriptions |
| `application/Espo/Resources/i18n/en_US/Global.json` | Add navbar config translations |
| `application/Espo/Resources/i18n/en_US/Settings.json` | Add field labels |
| `application/Espo/Resources/i18n/en_US/Preferences.json` | Add preference labels |

---

## Implementation Details

### 1. TabsHelper Modifications (`client/src/helpers/site/tabs.js`)

```javascript
// New method to get navbar config list
getNavbarConfigList() {
  if (this.preferences.get('useCustomNavbarConfig') && !this.config.get('navbarConfigDisabled')) {
    return this.preferences.get('navbarConfigList') || [];
  }
  return this.config.get('navbarConfigList') || [];
}

// New method to get active navbar config
getActiveNavbarConfig() {
  const configList = this.getNavbarConfigList();
  const activeId = this.preferences.get('activeNavbarConfigId');
  
  if (activeId) {
    return configList.find(c => c.id === activeId) || configList[0];
  }
  
  // Find default or return first
  return configList.find(c => c.isDefault) || configList[0];
}

// Modified getTabList
getTabList() {
  const activeConfig = this.getActiveNavbarConfig();
  
  if (activeConfig && activeConfig.tabList) {
    return Espo.Utils.cloneDeep(activeConfig.tabList);
  }
  
  // Fallback to original logic
  // ... existing code
}
```

### 2. Navbar View Modifications (`client/src/views/site/navbar.js`)

Key changes:
- Add `setupNavbarConfigSelector()` method
- Add `renderNavbarConfigSelector()` method  
- Add `switchNavbarConfig(configId)` method
- Modify `getTabDefsList()` to use active navbar config
- Store selected config in preferences via AJAX

### 3. Navbar Config Selector Component

UI placement: Top of sidebar navbar, below logo
- Dropdown with list of available configs
- Shows current config name and icon
- On selection: Update preference, re-render tabs

### 4. Admin UI Layout Changes

File: `application/Espo/Resources/layouts/Settings/userInterface.json`

Add to Navbar tab section:
```json
{
  "rows": [
    [{"name": "navbarConfigList", "fullWidth": true}],
    [{"name": "navbarConfigDisabled"}, {"name": "navbarConfigSelectorDisabled"}]
  ]
}
```

---

## CSS Styling Notes

### Selector Position (Sidebar Mode)
```less
body[data-navbar="side"] {
  .navbar-config-selector {
    position: relative;
    padding: var(--8px) var(--12px);
    border-bottom: 1px solid var(--border-color);
    
    .dropdown-toggle {
      display: flex;
      align-items: center;
      width: 100%;
      padding: var(--4px) var(--8px);
      border-radius: var(--border-radius);
      
      &:hover {
        background-color: var(--navbar-inverse-link-hover-bg);
      }
    }
    
    .config-icon {
      margin-right: var(--8px);
    }
    
    .config-name {
      flex: 1;
      text-align: left;
    }
  }
}
```

---

## Translation Keys

### Admin.json additions
```json
{
  "labels": {
    "navbarConfigList": "Navbar Configurations",
    "addNavbarConfig": "Add Navbar Configuration",
    "editNavbarConfig": "Edit Navbar Configuration"
  },
  "descriptions": {
    "navbarConfigList": "Configure multiple navbar views users can switch between.",
    "navbarConfigDisabled": "Prevent users from customizing their navbar configurations.",
    "navbarConfigSelectorDisabled": "Hide the navbar configuration selector from the sidebar."
  }
}
```

### Global.json additions
```json
{
  "navbarConfig": {
    "switchView": "Switch View",
    "defaultConfig": "Default",
    "customConfig": "Custom"
  }
}
```

---

## Related Files (Reference Only)

No changes needed but useful for patterns:
- `client/src/views/settings/modals/edit-tab-group.js` - Modal pattern for editing configs
- `client/src/views/settings/modals/edit-tab-url.js` - URL tab pattern
- `client/src/views/settings/modals/edit-tab-divider.js` - Divider pattern
- `client/src/views/settings/fields/group-tab-list.js` - Nested tab list handling
- `client/src/theme-manager.js` - Theme parameter handling

---

## Questions/Decisions Needed

1. **Default Behavior**: Should there always be a "Default" navbar config that mirrors the existing `tabList`, or should we migrate existing `tabList` into the first navbar config?

2. **Selector Visibility**: Should the selector be hidden if there's only one navbar config?

3. **Admin Separate Page**: Should navbar configurations have a dedicated admin page (`#Admin/navbarConfigs`) or just be a field on the User Interface page?

4. **API Endpoint**: Need backend API endpoint for:
   - `GET /api/v1/Settings/navbarConfigList`
   - `PUT /api/v1/Settings/navbarConfigList`
   - Or handle via existing Settings save

5. **Portal Support**: Should this feature extend to Portal configurations as well? (Portal has its own `tabList` field)

---

## Implementation Order

1. Add entity definitions (Settings.json, Preferences.json)
2. Create field views for navbar config list
3. Create modal views for adding/editing configs
4. Modify TabsHelper to use navbar configs
5. Modify Navbar view to use configs and add selector
6. Add templates and styles
7. Add translations
8. Update layout files
9. Test and refine

---

## Audit Summary

| Category | Count |
|----------|-------|
| Critical Issues | 4 |
| Warnings | 5 |
| Suggestions | 4 |

**See `.scopes/multi-sidenav-sidebar.v1.audit.md` for full audit details.**
