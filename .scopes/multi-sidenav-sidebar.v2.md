# Multi-Sidenav Sidebar Mode - Implementation Plan v2

> **Version**: 2.0 (Audited & Corrected)  
> **Based on**: `.scopes/multi-sidenav-sidebar.v1.audit.md`  
> **Codebase Root**: `components/crm/source/`

## Overview

Feature request to implement a multi-sidenav sidebar mode allowing users to toggle between different navbar configurations using a dropdown selector in the sidebar.

### Requirements
1. **UI Pattern**: Dropdown/selector in the sidebar for switching views
2. **Configuration**: Each navbar config has its own complete `tabList`
3. **Levels**: Both system-level defaults and user-level overrides
4. **Quantity**: Unlimited configurable navbar views

---

## Decisions Made

Based on audit findings, the following decisions have been made:

| Question | Decision | Rationale |
|----------|----------|-----------|
| Default Behavior | Keep existing `tabList` as fallback; first navbar config must be explicitly created | Backward compatible, no migration needed |
| Selector Visibility | Hidden when ≤1 navbar config exists | Cleaner UI when feature not actively used |
| Admin UI | Field on User Interface page (not separate page) | Simpler implementation, follows existing pattern |
| Portal Support | **Out of scope** for v1 | Portal has separate `tabList` system; can be added later |
| Storage Strategy | Server-side Preferences only | Syncs across devices, simpler implementation |

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

**Existing Resolution Logic** (`client/src/helpers/site/tabs.js:68-77`):
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
    const navbarConfigList = this.getNavbarConfigList();
    
    if (navbarConfigList && navbarConfigList.length > 0) {
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
```

### Validation for Config ID Uniqueness
```javascript
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

#### Views - Admin UI
| File | Purpose |
|------|---------|
| `client/src/views/admin/navbar-config/index.js` | Main admin view for managing navbar configs |
| `client/src/views/admin/navbar-config/item-list.js` | List view showing all navbar configurations |
| `client/src/views/admin/navbar-config/record/edit.js` | Edit view for single navbar config |

#### Templates - Admin UI
| File | Purpose |
|------|---------|
| `client/res/templates/admin/navbar-config/index.tpl` | Template for navbar config management page |
| `client/res/templates/admin/navbar-config/item-list.tpl` | Template for navbar config list |

#### Views - Field Views
| File | Purpose |
|------|---------|
| `client/src/views/settings/fields/navbar-config-list.js` | Field view for managing navbar configs (extends ArrayFieldView, no template needed) |
| `client/src/views/settings/modals/edit-navbar-config.js` | Modal for editing a navbar config |
| `client/src/views/settings/modals/navbar-config-add.js` | Modal for adding new navbar config |

#### Views - Preferences
| File | Purpose |
|------|---------|
| `client/src/views/preferences/fields/navbar-config-list.js` | User preferences field view |
| `client/src/views/preferences/fields/active-navbar-config.js` | Dropdown to select active config |

#### Views - Navbar UI
| File | Purpose |
|------|---------|
| `client/src/views/site/navbar-config-selector.js` | Dropdown selector component |
| `client/res/templates/site/navbar-config-selector.tpl` | Selector template |
| `client/src/handlers/navbar-config.js` | Handler for config switching (flat structure) |

#### Styles
| File | Purpose |
|------|---------|
| `frontend/less/espo/elements/navbar-config-selector.less` | Selector component styles (or add to navbar.less) |

### Files to EDIT

| File | Changes |
|------|---------|
| `application/Espo/Resources/metadata/entityDefs/Settings.json` | Add `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled` fields |
| `application/Espo/Resources/metadata/entityDefs/Preferences.json` | Add `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId` fields; listen for changes in `tabList` |
| `client/src/views/site/navbar.js` | Modify `getTabList()`, add config selector initialization, add `switchNavbarConfig()`, listen for `activeNavbarConfigId` changes |
| `client/src/helpers/site/tabs.js` | Add `getNavbarConfigList()`, `getActiveNavbarConfig()`, `validateNavbarConfigList()`, modify `getTabList()` |
| `client/res/templates/site/navbar.tpl` | Add navbar config selector element |
| `frontend/less/espo/elements/navbar.less` | Import or include navbar-config-selector styles |
| `application/Espo/Resources/layouts/Settings/userInterface.json` | Add navbar config fields to layout |
| `application/Espo/Resources/i18n/en_US/Admin.json` | Add labels and descriptions |
| `application/Espo/Resources/i18n/en_US/Global.json` | Add navbar config translations |
| `application/Espo/Resources/i18n/en_US/Settings.json` | Add field labels |
| `application/Espo/Resources/i18n/en_US/Preferences.json` | Add preference labels |

### Files to CONSIDER

| File | Reason |
|------|--------|
| `client/src/views/portal/navbar.js` | If Portal support added later |
| `application/Espo/Resources/metadata/entityDefs/Portal.json` | Portal has its own `tabList` field |
| `client/res/templates/site/navbar-side.tpl` | May need separate sidebar template modifications |

### Related Files (Reference Only)

| File | Pattern Reference |
|------|-------------------|
| `client/src/views/settings/modals/edit-tab-group.js` | Modal pattern for editing configs |
| `client/src/views/settings/modals/edit-tab-url.js` | URL tab pattern |
| `client/src/views/settings/modals/edit-tab-divider.js` | Divider pattern |
| `client/src/views/settings/fields/group-tab-list.js` | Nested tab list handling |
| `client/src/views/settings/fields/dashboard-layout.js` | Complex field with modal editing pattern |
| `client/res/templates/settings/fields/dashboard-layout.tpl` | Template example for complex field |
| `client/src/theme-manager.js` | Theme parameter handling |

---

## Implementation Details

### 1. TabsHelper Modifications (`client/src/helpers/site/tabs.js`)

Add the following methods:

```javascript
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

/**
 * Check if navbar config system is active.
 * @return {boolean}
 */
hasNavbarConfigSystem() {
    const configList = this.getNavbarConfigList();
    return configList && configList.length > 0;
}
```

### 2. Navbar View Modifications (`client/src/views/site/navbar.js`)

```javascript
// Add to setup():
setupNavbarConfigSelector() {
    if (this.config.get('navbarConfigSelectorDisabled')) {
        return;
    }
    
    const configList = this.getHelper().tabsHelper.getNavbarConfigList();
    
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

// Switch active config
async switchNavbarConfig(configId) {
    await this.ajaxPostRequest('Preferences/action/update', {
        activeNavbarConfigId: configId
    });
    
    this.getPreferences().set('activeNavbarConfigId', configId);
    
    // Trigger re-render of tabs
    this.trigger('navbar-config-changed');
}

// Add to preferences listener (around line 466):
this.listenTo(this.getHelper().preferences, 'update', (attributeList) => {
    if (!attributeList) return;
    
    if (
        attributeList.includes('tabList') ||
        attributeList.includes('addCustomTabs') ||
        attributeList.includes('useCustomTabList') ||
        attributeList.includes('navbarConfigList') ||
        attributeList.includes('useCustomNavbarConfig') ||
        attributeList.includes('activeNavbarConfigId')
    ) {
        update();
    }
});
```

### 3. Navbar Config Selector Component

```javascript
// client/src/views/site/navbar-config-selector.js
define('views/site/navbar-config-selector', ['view'], function (Dep) {

    return Dep.extend({
        template: 'site/navbar-config-selector',
        
        data: function () {
            return {
                configList: this.options.configList,
                activeConfigId: this.options.activeConfigId,
            };
        },
        
        events: {
            'click [data-action="selectConfig"]': function (e) {
                const id = $(e.currentTarget).data('id');
                this.trigger('select', id);
            },
        },
    });
});
```

### 4. Admin UI Layout Changes

File: `application/Espo/Resources/layouts/Settings/userInterface.json`

```json
{
  "rows": [
    [{"name": "tabList", "fullWidth": true}],
    [{"name": "navbarConfigList", "fullWidth": true, "label": "Navbar Configurations"}],
    [{"name": "navbarConfigDisabled"}, {"name": "navbarConfigSelectorDisabled"}]
  ]
}
```

---

## CSS Styling

### Selector Component (Add to `frontend/less/espo/elements/navbar-config-selector.less`)

```less
// Navbar Config Selector Styles
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
  },
  "fields": {
    "navbarConfigList": "Navbar Configurations",
    "navbarConfigDisabled": "Disable User Customization",
    "navbarConfigSelectorDisabled": "Hide Selector"
  }
}
```

### Global.json additions
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

### i18n Coverage Note
> ⚠️ Translations shown are for `en_US` only. All other language files in `application/Espo/Resources/i18n/*/` need corresponding translations added. Consider using fallback to English or documenting that translations need to be added to all locales.

---

## Error Handling

### Missing Config Fallback
```javascript
// In getActiveNavbarConfig()
getActiveNavbarConfig() {
    const configList = this.getNavbarConfigList();
    
    if (!configList || configList.length === 0) {
        return null; // Will trigger fallback to legacy tabList
    }
    
    const activeId = this.preferences.get('activeNavbarConfigId');
    
    // Handle case where stored ID doesn't match any existing config
    // (e.g., deleted by admin)
    if (activeId) {
        const found = configList.find(c => c.id === activeId);
        if (found) return found;
        
        // ID not found - clear invalid preference
        console.warn('Active navbar config ID not found, falling back to default');
    }
    
    // Find default or use first
    return configList.find(c => c.isDefault) || configList[0];
}
```

---

## No Migration Strategy Required

Per the decision above:
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

## Summary of Changes from v1

| Issue | v1 Problem | v2 Solution |
|-------|-----------|-------------|
| Template paths | `client/src/res/templates/` | `client/res/templates/` |
| CSS path | `frontend/less/espo/components/` | `frontend/less/espo/elements/` |
| Handler path | `client/src/handlers/site/` | `client/src/handlers/` (flat) |
| Resolution logic | Incomplete | Accounts for `useCustomTabList`/`addCustomTabs` |
| AJAX save pattern | Not documented | Full pattern shown |
| Portal support | Question unanswered | Explicitly out of scope |
| Config ID validation | Missing | Added |
| Missing config handling | Missing | Added fallback |
| Selector visibility | Question unanswered | Hidden when ≤1 config |
| Admin UI approach | Question unanswered | Field on User Interface page |

---

*Scope document v2 generated from audit findings*
