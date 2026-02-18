# Multi-Sidenav Sidebar Mode - Implementation Plan v3

> **Version**: 3.0  
> **Based on**: `.scopes/multi-sidenav-sidebar.v2.md` and `.scopes/multi-sidenav-sidebar.v2.audit.md`  
> **Codebase Root**: `components/crm/source/`  
> **Status**: Audit corrections applied

## Overview

Feature request to implement a multi-sidenav sidebar mode allowing users to toggle between different navbar configurations using a dropdown selector in the sidebar.

### Requirements
1. **UI Pattern**: Dropdown/selector in the sidebar for switching views
2. **Configuration**: Each navbar config has its own complete `tabList`
3. **Levels**: Both system-level defaults and user-level overrides
4. **Quantity**: Unlimited configurable navbar views

---

## Audit Corrections Applied (v2 → v3)

| Issue | v2 Problem | v3 Correction |
|-------|-----------|---------------|
| AJAX Pattern | `this.ajaxPostRequest()` | `Espo.Ajax.postRequest()` (static method) |
| Helper Access | `this.getHelper().tabsHelper` | `this.tabsHelper` (instance property) |
| Backend Endpoint | Assumed `Preferences/action/update` exists | Create new action OR use `Espo.Ajax.putRequest()` |
| Preferences Layout | Missing from EDIT list | Added to EDIT list |
| CSS Variables | Unverified | Verified against `root-variables.less` |
| ID Generation | Inconsistent pattern | Use `Math.floor(Math.random() * 1000000 + 1).toString()` |

---

## Decisions Made

| Question | Decision | Rationale |
|----------|----------|-----------|
| Default Behavior | Keep existing `tabList` as fallback; first navbar config must be explicitly created | Backward compatible, no migration needed |
| Selector Visibility | Hidden when ≤1 navbar config exists | Cleaner UI when feature not actively used |
| Admin UI | Field on User Interface page (not separate page) | Simpler implementation, follows existing pattern |
| Portal Support | **Out of scope** for v3 | Portal has separate `tabList` system; can be added later |
| Storage Strategy | Server-side Preferences only | Syncs across devices, simpler implementation |
| Active Config Save | Use `Espo.Ajax.putRequest()` to update Preferences | No new backend action needed, uses existing REST API |

---

## Current System Architecture

### Existing Navbar Modes
- **Location**: `application/Espo/Resources/metadata/themes/Espo.json`
- Two modes supported: `side` (sidebar) and `top` (horizontal navbar)
- Configured via theme `params.navbar` enum field

### Current Tab List Structure
- **Settings Field**: `tabList` in `application/Espo/Resources/metadata/entityDefs/Settings.json:245`
- **Preferences Field**: `tabList` in `application/Espo/Resources/metadata/entityDefs/Preferences.json:171`
- **Field View**: `client/src/views/settings/fields/tab-list.js`
- **Helper Class**: `client/src/helpers/site/tabs.js`

### Existing Preference Fields (MUST ACCOUNT FOR)
| Field | Type | Location | Purpose |
|-------|------|----------|---------|
| `useCustomTabList` | bool | `Preferences.json:162` | User has custom tab list enabled |
| `addCustomTabs` | bool | `Preferences.json:166` | User's tabs are additive to system tabs |
| `tabList` | array | `Preferences.json:171` | User's custom tab list |

**Existing Resolution Logic** (`client/src/helpers/site/tabs.js:67-79`):
```javascript
getTabList() {
    let tabList = this.preferences.get('useCustomTabList') && !this.preferences.get('addCustomTabs') ?
        this.preferences.get('tabList') :
        this.config.get('tabList');

    if (this.preferences.get('useCustomTabList') && this.preferences.get('addCustomTabs')) {
        tabList = [
            ...tabList,
            ...(this.preferences.get('tabList') || []),
        ];
    }

    return Espo.Utils.cloneDeep(tabList) || [];
}
```

### Tab List Item Types
1. **Scope**: String entity name (e.g., `"Accounts"`, `"Contacts"`)
2. **Group**: Object with `type: "group"`, `text`, `iconClass`, `color`, `itemList`
3. **URL**: Object with `type: "url"`, `text`, `url`, `iconClass`, `color`, `aclScope`, `onlyAdmin`
4. **Divider**: Object with `type: "divider"`, `text`
5. **Delimiter**: String `"_delimiter_"` or `"_delimiter-ext_"` for more menu split

### Existing Translation Section (MUST USE)
**Location**: `application/Espo/Resources/i18n/en_US/Global.json:988-994`
```json
"navbarTabs": {
    "Business": "Business",
    "Marketing": "Marketing",
    "Support": "Support",
    "CRM": "CRM",
    "Activities": "Activities"
}
```

### Verified CSS Variables
**Location**: `frontend/less/espo/root-variables.less`
- Spacing: `--8px`, `--12px`, `--4px`, etc.
- Layout: `--navbar-width` (232px), `--border-radius`
- Colors: `--navbar-inverse-link-hover-bg`, `--border-color`, `--dropdown-link-hover-bg`

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
| `navbarConfigDisabled` | `bool` | Disable user customization (default: false) |
| `navbarConfigSelectorDisabled` | `bool` | Hide selector dropdown (default: false) |

### Preferences Fields (User Level)
| Field | Type | Description |
|-------|------|-------------|
| `navbarConfigList` | `jsonArray` | User's custom navbar configurations |
| `useCustomNavbarConfig` | `bool` | Use user configs instead of system (default: false) |
| `activeNavbarConfigId` | `varchar` | ID of currently active configuration |

---

## Resolution Logic (Complete)

```javascript
/**
 * Resolution Priority Order:
 * 1. Navbar config system (new feature) - if any navbarConfigList exists
 * 2. Legacy tab customization (existing feature) - useCustomTabList/addCustomTabs
 * 3. System default tabList
 */

getTabList() {
    // Check if navbar config system is active
    if (this.hasNavbarConfigSystem()) {
        const activeConfig = this.getActiveNavbarConfig();
        
        if (activeConfig && activeConfig.tabList) {
            return Espo.Utils.cloneDeep(activeConfig.tabList);
        }
    }
    
    // Fallback to existing logic (legacy tab customization)
    let tabList = this.preferences.get('useCustomTabList') && 
                  !this.preferences.get('addCustomTabs') ?
        this.preferences.get('tabList') :
        this.config.get('tabList');

    if (this.preferences.get('useCustomTabList') && this.preferences.get('addCustomTabs')) {
        tabList = [
            ...tabList,
            ...(this.preferences.get('tabList') || []),
        ];
    }

    return Espo.Utils.cloneDeep(tabList) || [];
}

hasNavbarConfigSystem() {
    const configList = this.getNavbarConfigList();
    return configList && configList.length > 0;
}

getNavbarConfigList() {
    // If customization disabled, always use system configs
    if (this.config.get('navbarConfigDisabled')) {
        return this.config.get('navbarConfigList') || [];
    }
    
    // If user has custom configs enabled, use theirs
    if (this.preferences.get('useCustomNavbarConfig')) {
        return this.preferences.get('navbarConfigList') || [];
    }
    
    // Default: use system configs
    return this.config.get('navbarConfigList') || [];
}

getActiveNavbarConfig() {
    const configList = this.getNavbarConfigList();
    
    if (!configList || configList.length === 0) {
        return null;
    }
    
    const activeId = this.preferences.get('activeNavbarConfigId');
    
    // Try to find by active ID
    if (activeId) {
        const found = configList.find(c => c.id === activeId);
        if (found) return found;
        // ID not found - fall through to default/first
    }
    
    // Find default config
    const defaultConfig = configList.find(c => c.isDefault);
    if (defaultConfig) return defaultConfig;
    
    // Fall back to first config
    return configList[0];
}

validateNavbarConfigList(configList) {
    if (!configList || configList.length === 0) return true;
    
    const ids = configList.map(c => c.id).filter(Boolean);
    const uniqueIds = new Set(ids);
    
    if (ids.length !== uniqueIds.size) {
        throw new Error('Duplicate navbar config IDs detected');
    }
    
    return true;
}
```

---

## File Manifest

### Files to CREATE

#### Views - Field Views
| File | Purpose |
|------|---------|
| `client/src/views/settings/fields/navbar-config-list.js` | Field view for managing navbar configs in Settings (extends ArrayFieldView pattern from tab-list.js) |
| `client/src/views/preferences/fields/navbar-config-list.js` | Field view for user-level navbar configs (extends Settings version like preferences/tab-list.js pattern) |
| `client/src/views/preferences/fields/active-navbar-config.js` | Dropdown field to select active config from available options |

#### Views - Modals
| File | Purpose |
|------|---------|
| `client/src/views/settings/modals/edit-navbar-config.js` | Modal for editing a single navbar config (follows edit-tab-group.js pattern) |

#### Views - Navbar UI
| File | Purpose |
|------|---------|
| `client/src/views/site/navbar-config-selector.js` | Dropdown selector component rendered in sidebar |
| `client/res/templates/site/navbar-config-selector.tpl` | Template for navbar config selector dropdown |

#### Handlers
| File | Purpose |
|------|---------|
| `client/src/handlers/navbar-config.js` | Handler for config switching actions (flat structure per existing handlers) |

#### Styles
| File | Purpose |
|------|---------|
| `frontend/less/espo/elements/navbar-config-selector.less` | Selector component styles (or add to navbar.less) |

---

### Files to EDIT

| File | Changes Required |
|------|-----------------|
| `application/Espo/Resources/metadata/entityDefs/Settings.json` | Add `navbarConfigList` (jsonArray), `navbarConfigDisabled` (bool), `navbarConfigSelectorDisabled` (bool) field definitions |
| `application/Espo/Resources/metadata/entityDefs/Preferences.json` | Add `navbarConfigList` (jsonArray), `useCustomNavbarConfig` (bool), `activeNavbarConfigId` (varchar) field definitions |
| `client/src/helpers/site/tabs.js` | Add `getNavbarConfigList()`, `getActiveNavbarConfig()`, `validateNavbarConfigList()`, `hasNavbarConfigSystem()` methods; modify `getTabList()` to check navbar config system first |
| `client/src/views/site/navbar.js` | Add `setupNavbarConfigSelector()` method; add `switchNavbarConfig()` method; add `activeNavbarConfigId` to preferences listener (line 466-478); use `this.tabsHelper` directly (NOT `this.getHelper().tabsHelper`) |
| `client/res/templates/site/navbar.tpl` | Add navbar config selector container element in sidebar section |
| `frontend/less/espo/elements/navbar.less` | Import or include navbar-config-selector styles; add selector positioning for sidebar mode |
| `application/Espo/Resources/layouts/Settings/userInterface.json` | Add navbar config fields to layout (after tabList row) |
| `application/Espo/Resources/layouts/Preferences/detail.json` | Add navbar config fields under User Interface tab |
| `application/Espo/Resources/i18n/en_US/Settings.json` | Add field labels and tooltips for navbarConfigList, navbarConfigDisabled, navbarConfigSelectorDisabled |
| `application/Espo/Resources/i18n/en_US/Preferences.json` | Add field labels for navbarConfigList, useCustomNavbarConfig, activeNavbarConfigId |
| `application/Espo/Resources/i18n/en_US/Global.json` | Extend existing `navbarTabs` section or add `navbarConfig` section for selector UI labels |
| `application/Espo/Resources/i18n/en_US/Admin.json` | Add labels for navbar config management (optional, if admin page created) |

---

### Files to CONSIDER

| File | Reason |
|------|--------|
| `client/src/views/portal/navbar.js` | If Portal support added later (explicitly out of scope for v3) |
| `application/Espo/Resources/metadata/entityDefs/Portal.json` | Portal has separate tabList system |
| `application/Espo/Controllers/Preferences.php` | May need backend action for quick config switching if using action endpoint approach |

---

### Related Files (for reference only, no changes needed)

| File | Pattern Reference |
|------|-------------------|
| `client/src/views/settings/modals/edit-tab-group.js` | Modal pattern for editing configs - use as template |
| `client/src/views/settings/modals/edit-tab-url.js` | URL tab pattern |
| `client/src/views/settings/modals/edit-tab-divider.js` | Divider pattern |
| `client/src/views/settings/fields/tab-list.js` | ID generation pattern (`generateItemId()`), array field with modal editing |
| `client/src/views/settings/fields/dashboard-layout.js` | Complex field with modal editing, nested tabs |
| `client/src/views/preferences/fields/tab-list.js` | Preferences field extending Settings field |
| `client/src/views/preferences/record/edit.js` | Preferences save pattern, `Espo.Ajax.postRequest()` for actions |
| `client/src/theme-manager.js` | Theme parameter handling |
| `frontend/less/espo/root-variables.less` | CSS variable definitions (`--8px`, `--navbar-width`, etc.) |
| `frontend/less/espo/variables.less` | LESS variable patterns |
| `application/Espo/Resources/i18n/en_US/Global.json` (lines 988-994) | Existing `navbarTabs` translation section |

---

## Implementation Details

### 1. TabsHelper Modifications (`client/src/helpers/site/tabs.js`)

Add the following methods:

```javascript
/**
 * Check if navbar config system is active.
 * @return {boolean}
 */
hasNavbarConfigSystem() {
    const configList = this.getNavbarConfigList();
    return configList && configList.length > 0;
}

/**
 * Get navbar config list based on user/system settings.
 * @return {Object[]}
 */
getNavbarConfigList() {
    if (this.config.get('navbarConfigDisabled')) {
        return this.config.get('navbarConfigList') || [];
    }
    
    if (this.preferences.get('useCustomNavbarConfig')) {
        return this.preferences.get('navbarConfigList') || [];
    }
    
    return this.config.get('navbarConfigList') || [];
}

/**
 * Get the active navbar configuration.
 * @return {Object|null}
 */
getActiveNavbarConfig() {
    const configList = this.getNavbarConfigList();
    
    if (!configList || configList.length === 0) {
        return null;
    }
    
    const activeId = this.preferences.get('activeNavbarConfigId');
    
    if (activeId) {
        const found = configList.find(c => c.id === activeId);
        if (found) return found;
        
        // ID not found - clear invalid preference
        console.warn('Active navbar config ID not found, falling back to default');
    }
    
    return configList.find(c => c.isDefault) || configList[0];
}

/**
 * Validate navbar config list for ID uniqueness.
 * @param {Object[]} configList
 * @return {boolean}
 * @throws {Error} If duplicate IDs found
 */
validateNavbarConfigList(configList) {
    if (!configList || configList.length === 0) return true;
    
    const ids = configList.map(c => c.id).filter(Boolean);
    
    if (new Set(ids).size !== ids.length) {
        throw new Error('Duplicate navbar config IDs detected');
    }
    
    return true;
}
```

**CRITICAL**: Modify `getTabList()` to check navbar config system FIRST:

```javascript
getTabList() {
    // NEW: Check navbar config system first
    if (this.hasNavbarConfigSystem()) {
        const activeConfig = this.getActiveNavbarConfig();
        
        if (activeConfig && activeConfig.tabList) {
            return Espo.Utils.cloneDeep(activeConfig.tabList);
        }
    }
    
    // Existing logic remains as fallback
    let tabList = this.preferences.get('useCustomTabList') && !this.preferences.get('addCustomTabs') ?
        this.preferences.get('tabList') :
        this.config.get('tabList');

    if (this.preferences.get('useCustomTabList') && this.preferences.get('addCustomTabs')) {
        tabList = [
            ...tabList,
            ...(this.preferences.get('tabList') || []),
        ];
    }

    return Espo.Utils.cloneDeep(tabList) || [];
}
```

---

### 2. Navbar View Modifications (`client/src/views/site/navbar.js`)

**CRITICAL CORRECTION**: Use `this.tabsHelper` directly, NOT `this.getHelper().tabsHelper`

```javascript
// Add to setup() after line 461:

setupNavbarConfigSelector() {
    if (this.config.get('navbarConfigSelectorDisabled')) {
        return;
    }
    
    // CORRECTED: Use this.tabsHelper directly (instantiated at line 429)
    const configList = this.tabsHelper.getNavbarConfigList();
    
    // Hide selector if only 0-1 configs
    if (!configList || configList.length <= 1) {
        return;
    }
    
    this.createView('navbarConfigSelector', 'views/site/navbar-config-selector', {
        el: this.options.el + ' .navbar-config-selector-container',
        configList: configList,
        activeConfigId: this.preferences.get('activeNavbarConfigId'),
    }, view => {
        this.listenTo(view, 'select', id => this.switchNavbarConfig(id));
    });
}

// CORRECTED: Use Espo.Ajax static method
async switchNavbarConfig(configId) {
    // Use putRequest to update Preferences (no custom action needed)
    await Espo.Ajax.putRequest('Preferences/' + this.getUser().id, {
        activeNavbarConfigId: configId
    });
    
    this.getPreferences().set('activeNavbarConfigId', configId);
    this.getPreferences().trigger('update', ['activeNavbarConfigId']);
    
    // Trigger re-render of tabs
    this.trigger('navbar-config-changed');
}
```

**Update preferences listener (around line 466-478)**:

```javascript
this.listenTo(this.getHelper().preferences, 'update', (/** string[] */attributeList) => {
    if (!attributeList) {
        return;
    }

    if (
        attributeList.includes('tabList') ||
        attributeList.includes('addCustomTabs') ||
        attributeList.includes('useCustomTabList') ||
        attributeList.includes('navbarConfigList') ||      // NEW
        attributeList.includes('useCustomNavbarConfig') || // NEW
        attributeList.includes('activeNavbarConfigId')     // NEW
    ) {
        update();
    }
});
```

---

### 3. Navbar Config Selector Component

```javascript
// client/src/views/site/navbar-config-selector.js
import View from 'view';

class NavbarConfigSelectorView extends View {

    template = 'site/navbar-config-selector'
    
    data() {
        return {
            configList: this.options.configList,
            activeConfigId: this.options.activeConfigId,
        };
    }
    
    setup() {
        this.addActionHandler('selectConfig', (e, target) => {
            const id = target.dataset.id;
            this.trigger('select', id);
        });
    }
}

export default NavbarConfigSelectorView;
```

---

### 4. ID Generation (Use Consistent Pattern)

**Pattern from `tab-list.js:54-55`**:

```javascript
generateItemId() {
    return Math.floor(Math.random() * 1000000 + 1).toString();
}
```

Use this exact pattern in `navbar-config-list.js` field view.

---

### 5. Admin UI Layout Changes

File: `application/Espo/Resources/layouts/Settings/userInterface.json`

```json
[
    {
        "rows": [
            [{"name": "companyLogo"}, {"name": "applicationName"}]
        ],
        "tabBreak": true,
        "tabLabel": "$label:General"
    },
    {
        "rows": [
            [{"name": "theme"}, {"name": "userThemesDisabled"}],
            [false, {"name": "avatarsDisabled"}]
        ]
    },
    {
        "rows": [
            [{"name": "recordsPerPage"}, {"name": "recordsPerPageSelect"}],
            [{"name": "recordsPerPageSmall"}, {"name": "recordsPerPageKanban"}],
            [{"name": "displayListViewRecordCount"}, false]
        ]
    },
    {
        "rows": [
            [{"name": "tabList"}, {"name": "quickCreateList"}],
            [{"name": "scopeColorsDisabled"}, {"name": "tabColorsDisabled"}],
            [{"name": "tabIconsDisabled"}, false],
            [{"name": "navbarConfigList", "fullWidth": true}],
            [{"name": "navbarConfigDisabled"}, {"name": "navbarConfigSelectorDisabled"}]
        ],
        "tabBreak": true,
        "tabLabel": "$label:Navbar"
    },
    {
        "rows": [
            [{"name": "dashboardLayout", "fullWidth": true}]
        ],
        "tabBreak": true,
        "tabLabel": "$label:Dashboard"
    }
]
```

---

### 6. Preferences Layout Changes

File: `application/Espo/Resources/layouts/Preferences/detail.json`

Add under User Interface tab (around line 101-122):

```json
{
    "tabBreak": true,
    "tabLabel": "$label:User Interface",
    "rows": [
        [
            {"name": "theme"},
            {"name": "pageContentWidth"}
        ]
    ]
},
{
    "rows": [
        [
            {"name": "useCustomTabList"},
            {"name": "addCustomTabs"}
        ],
        [
            {"name": "tabList"},
            false
        ]
    ]
},
{
    "rows": [
        [
            {"name": "useCustomNavbarConfig"},
            false
        ],
        [
            {"name": "navbarConfigList"},
            false
        ],
        [
            {"name": "activeNavbarConfigId"},
            false
        ]
    ]
},
```

---

## CSS Styling

### Selector Component (Add to `frontend/less/espo/elements/navbar-config-selector.less`)

```less
// Navbar Config Selector Styles
// Uses verified CSS variables from root-variables.less

#navbar {
    .navbar-config-selector {
        padding: var(--8px) var(--12px);
        border-bottom: 1px solid var(--border-color);
        
        .dropdown-toggle {
            display: flex;
            align-items: center;
            width: 100%;
            padding: var(--8px);
            border-radius: var(--border-radius);
            background: transparent;
            border: none;
            color: inherit;
            cursor: pointer;
            
            &:hover {
                background-color: var(--navbar-inverse-link-hover-bg);
            }
            
            .config-icon {
                margin-right: var(--8px);
                width: 16px;
                text-align: center;
            }
            
            .config-name {
                flex: 1;
                text-align: left;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            
            .caret {
                margin-left: var(--8px);
            }
        }
        
        .dropdown-menu {
            width: 100%;
            max-height: 300px;
            overflow-y: auto;
            
            > li > a {
                display: flex;
                align-items: center;
                padding: var(--8px) var(--12px);
                
                .config-icon {
                    margin-right: var(--8px);
                    width: 16px;
                    text-align: center;
                }
                
                .config-name {
                    flex: 1;
                }
                
                .is-default-badge {
                    font-size: 0.75em;
                    opacity: 0.7;
                }
            }
        }
    }
}

// Sidebar-specific styles
body[data-navbar="side"] {
    #navbar .navbar-config-selector {
        .dropdown-menu {
            position: fixed;
            left: var(--navbar-width);
            top: auto;
        }
    }
}
```

---

## Translation Keys

### Settings.json additions
```json
{
    "fields": {
        "navbarConfigList": "Navbar Configurations",
        "navbarConfigDisabled": "Disable User Customization",
        "navbarConfigSelectorDisabled": "Hide Selector Dropdown"
    },
    "tooltips": {
        "navbarConfigList": "Create multiple navbar configurations that users can switch between.",
        "navbarConfigDisabled": "When enabled, users cannot create their own navbar configurations.",
        "navbarConfigSelectorDisabled": "Hide the dropdown selector from the navbar. Users will only see the default configuration."
    }
}
```

### Preferences.json additions
```json
{
    "fields": {
        "navbarConfigList": "My Navbar Configurations",
        "useCustomNavbarConfig": "Use Custom Configurations",
        "activeNavbarConfigId": "Active View"
    },
    "tooltips": {
        "useCustomNavbarConfig": "Use your own navbar configurations instead of system defaults."
    }
}
```

### Global.json additions (extend existing navbarTabs or add new section)
```json
{
    "navbarConfig": {
        "switchView": "Switch View",
        "defaultConfig": "Default",
        "customConfig": "Custom",
        "noConfigs": "No configurations available",
        "selectConfig": "Select a view..."
    }
}
```

---

## Error Handling

### Missing Config Fallback
The `getActiveNavbarConfig()` method handles:
- Empty config list → returns `null` → triggers legacy fallback
- Invalid `activeNavbarConfigId` → logs warning → falls back to default/first
- No default set → uses first config

---

## No Migration Strategy Required

- Existing `tabList` preferences continue to work unchanged
- Navbar config system activates only when `navbarConfigList` is populated
- No automatic migration of existing `tabList` to navbar config format
- Users who want navbar configs must explicitly create them

---

## Implementation Order

1. **Phase 1: Data Model**
   - Add entity definitions (Settings.json, Preferences.json)
   - Add translations

2. **Phase 2: Helper Logic**
   - Modify TabsHelper with new methods
   - Add validation logic

3. **Phase 3: Admin UI**
   - Create field views for navbar config list
   - Create modal views for adding/editing configs
   - Update layout files

4. **Phase 4: Navbar UI**
   - Create navbar config selector component
   - Create handler for config switching
   - Modify navbar view

5. **Phase 5: Styling**
   - Add CSS for selector component
   - Update navbar.less

6. **Phase 6: Testing**
   - Test backward compatibility with existing `useCustomTabList`
   - Test ID validation
   - Test missing config fallback
   - Test selector visibility logic

---

*Scope document v3 generated with audit corrections applied*
