# Multi-Sidenav Sidebar Mode - Implementation Plan v10

> **Version**: 10.0  
> **Based on**: `.scopes/multi-sidenav-sidebar.v9.md` and `.scopes/multi-sidenav-sidebar.v9.audit.md`  
> **Codebase Root**: `components/crm/source/`  
> **Status**: File Manifest - SCOPE MAPPED

## Overview

Feature request to implement a multi-sidenav sidebar mode allowing users to toggle between different navbar configurations using a dropdown selector in the sidebar.

### Requirements
1. **UI Pattern**: Dropdown/selector in the sidebar for switching views
2. **Configuration**: Each navbar config has its own complete `tabList`
3. **Levels**: Both system-level defaults and user-level overrides
4. **Quantity**: Unlimited configurable navbar views

---

## Audit Corrections Applied (v9 → v10)

### Warning Items Addressed

| Warning | v10 Correction |
|---------|----------------|
| Config Change Event Pattern | **CLARIFIED**: The existing `this.listenTo(this.getHelper().settings, 'sync', () => update())` at navbar.js:463 already handles ALL config changes including `navbarConfigList`. No additional listener needed. Only need to add new preference fields to the existing preferences listener at lines 471-474. |
| Missing Test Files | **NOTED**: Testing will be manual/ad-hoc. No test infrastructure changes required. |

### Suggestions (Not Blocking - Listed for Consideration)

| Suggestion | Status |
|------------|--------|
| Tooltip for resolution priority order | Optional - add to `navbarConfigList` tooltip in Settings.json |
| Server-side validation for ID uniqueness | Optional - can be added later if needed |
| Loading state during initial config load | Optional - UX enhancement |

---

## Decisions Made

| Question | Decision | Rationale |
|----------|----------|-----------|
| Default Behavior | Keep existing `tabList` as fallback; first navbar config must be explicitly created | Backward compatible, no migration needed |
| Selector Visibility | Hidden when ≤1 navbar config exists | Cleaner UI when feature not actively used |
| Admin UI | Field on User Interface page (not separate page) | Simpler implementation, follows existing pattern |
| Portal Support | **Out of scope** for v10 | Portal has separate `tabList` system; can be added later |
| Storage Strategy | Server-side Preferences only | Syncs across devices, simpler implementation |
| Active Config Save | Use `Espo.Ajax.putRequest()` to update Preferences | No new backend action needed, uses existing REST API |
| Config Change Event | Use existing `settings.sync` listener | Already handles all config changes at navbar.js:463 |
| Testing | Manual/ad-hoc | No test file infrastructure changes |

---

## Current System Architecture

### Existing Navbar Modes
- **Location**: `application/Espo/Resources/metadata/themes/Espo.json`
- Two modes supported: `side` (sidebar) and `top` (horizontal navbar)
- Configured via theme `params.navbar` enum field

### Current Tab List Structure
- **Settings Field**: `tabList` in `application/Espo/Resources/metadata/entityDefs/Settings.json:245-255`
- **Preferences Field**: `tabList` in `application/Espo/Resources/metadata/entityDefs/Preferences.json:171-181`
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
- Spacing: `--8px`, `--12px`, `--4px`, etc. (lines 10, 14, 6)
- Layout: `--navbar-width` (232px, line 108), `--border-radius` (line 440)
- Colors: `--navbar-inverse-link-hover-bg` (line 392), `--navbar-inverse-border` (line 388), `--dropdown-link-hover-bg` (line 490), `--dropdown-link-hover-color` (line 489)

### Verified Bootstrap Variables
**Location**: `frontend/less/espo/bootstrap/variables.less:37`
- `@screen-xs-max: (@screen-sm-min - 1px);`

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

**Rationale for `jsonArray` type**: Unlike `tabList` which uses `array` type, `navbarConfigList` uses `jsonArray` because each config contains nested `tabList` with complex objects (groups, URLs, dividers), not just string scope names. This follows the pattern of `dashboardLayout` field.

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
    // NEW: Check navbar config system first
    if (this.hasNavbarConfigSystem()) {
        const activeConfig = this.getActiveNavbarConfig();
        
        if (activeConfig && activeConfig.tabList) {
            return Espo.Utils.cloneDeep(activeConfig.tabList);
        }
    }
    
    // Existing logic remains as fallback
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
    if (this.config.get('navbarConfigDisabled')) {
        return this.config.get('navbarConfigList') || [];
    }
    
    if (this.preferences.get('useCustomNavbarConfig')) {
        return this.preferences.get('navbarConfigList') || [];
    }
    
    return this.config.get('navbarConfigList') || [];
}

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

validateNavbarConfigList(configList) {
    if (!configList || configList.length === 0) return true;
    
    const ids = configList.map(c => c.id).filter(Boolean);
    
    if (new Set(ids).size !== ids.length) {
        throw new Error('Duplicate navbar config IDs detected');
    }
    
    return true;
}
```

---

## File Manifest

### Files to CREATE

#### JavaScript Field Views

| File Path | Reason |
|-----------|--------|
| `client/src/views/settings/fields/navbar-config-list.js` | New field view for managing navbar configs in Settings. **CRITICAL**: Must use modern DOM API (`document.createElement()`) pattern matching `tab-list.js:138-235` instead of legacy string concatenation. |
| `client/src/views/preferences/fields/navbar-config-list.js` | User-level navbar configs field view extending Settings version |
| `client/src/views/preferences/fields/active-navbar-config.js` | Dropdown field to select active config. |

#### JavaScript Modal Views

| File Path | Reason |
|-----------|--------|
| `client/src/views/settings/modals/edit-navbar-config.js` | Modal for editing a single navbar config. Uses `new Model()` pattern with `model.name = 'NavbarConfig'` and `model.setDefs()` following `edit-tab-group.js:94-117`. Uses inline `templateContent`. |
| `client/src/views/modals/navbar-config-field-add.js` | Add item modal for navbar-config-list field. Uses `new Model()` pattern. Uses inline `templateContent`. |

#### JavaScript Sidebar Selector Component

| File Path | Reason |
|-----------|--------|
| `client/src/views/site/navbar-config-selector.js` | Dropdown selector component rendered in sidebar |

#### Templates

| File Path | Reason |
|-----------|--------|
| `client/res/templates/site/navbar-config-selector.tpl` | Template for navbar config selector dropdown. Uses `#ifEqual` (verified at view-helper.js:386) |

#### Styles

| File Path | Reason |
|-----------|--------|
| `frontend/less/espo/elements/navbar-config-selector.less` | Selector component styles using verified CSS variables from `root-variables.less` |

---

### Files to EDIT

#### Entity Definitions (Metadata)

| File Path | Reason | Critical Notes |
|-----------|--------|----------------|
| `application/Espo/Resources/metadata/entityDefs/Settings.json` | Add `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled` fields | Insert AFTER line 255 (`tabList` closing brace). **CRITICAL**: Ensure proper comma after `tabList` closing brace and after the new `navbarConfigSelectorDisabled` field |
| `application/Espo/Resources/metadata/entityDefs/Preferences.json` | Add `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId` fields | Insert AFTER line 181 (`tabList` closing brace). **CRITICAL**: Ensure proper comma handling |

#### Layout Files

| File Path | Reason | Critical Notes |
|-----------|--------|----------------|
| `application/Espo/Resources/layouts/Settings/userInterface.json` | Add navbar config fields to Navbar tab section | Modify existing rows array (lines 22-30) to include new fields |
| `application/Espo/Resources/layouts/Preferences/detail.json` | Add navbar config fields to User Interface section | **CRITICAL**: Append rows to the existing section at lines 111-122, NOT create a new wrapped object with `{ "rows": [...] }`. The structure is an array of objects, not nested. |

#### Helper Logic

| File Path | Reason |
|-----------|--------|
| `client/src/helpers/site/tabs.js` | Modify `getTabList()` to check navbar config system FIRST, add new methods: `hasNavbarConfigSystem()`, `getNavbarConfigList()`, `getActiveNavbarConfig()`, `validateNavbarConfigList()` |

#### Navbar View

| File Path | Reason | Location Details |
|-----------|--------|------------------|
| `client/src/views/site/navbar.js` | Multiple edits: (A) Call `setupNavbarConfigSelector()` after line 461, (B) Update preferences listener at lines 471-474 to include `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId`, (C) Add new methods `setupNavbarConfigSelector()` and `switchNavbarConfig()` AFTER `adjustAfterRender()` method | **CRITICAL v10 FINDING**: The existing `this.listenTo(this.getHelper().settings, 'sync', () => update())` at line 463 already handles ALL config changes. NO additional listener needed for `navbarConfigList`. Only add new preference fields to the existing listener at lines 471-474. |

#### Navbar Template

| File Path | Reason | Location Details |
|-----------|--------|------------------|
| `client/res/templates/site/navbar.tpl` | Add `<div class="navbar-config-selector-container"></div>` inside `.navbar-left-container` | Insert AFTER line 15, BEFORE line 16 (before `<ul class="nav navbar-nav tabs">`) |

#### Styles

| File Path | Reason | Location Details |
|-----------|--------|------------------|
| `frontend/less/espo/elements/navbar.less` | Add import for navbar-config-selector.less | **CRITICAL**: Add at TOP of file (line 1), before any existing content |

#### Internationalization Files

| File Path | Reason | Critical Notes |
|-----------|--------|----------------|
| `application/Espo/Resources/i18n/en_US/Settings.json` | Add field labels and tooltips for `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. Add modal header labels: `"Edit Navbar Configuration"`, `"Add Navbar Configuration"` | Add to "fields", "labels", and "tooltips" sections |
| `application/Espo/Resources/i18n/en_US/Preferences.json` | Add field labels and tooltips for `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId` | Add to "fields" and "tooltips" sections |
| `application/Espo/Resources/i18n/en_US/Global.json` | Add `navbarConfig` translation section with keys: `switchView`, `defaultConfig`, `customConfig`, `noConfigs`, `selectConfig`. Add `errorSavingPreference` to "messages" section | **CRITICAL**: Add `navbarConfig` section AFTER line 994 (after `navbarTabs` closing brace) with proper comma after `navbarTabs`. Verify messages section for `errorSavingPreference` insertion point |

#### Preferences Edit View

| File Path | Reason | Location Details |
|-----------|--------|------------------|
| `client/src/views/preferences/record/edit.js` | Hide navbar config fields if `navbarConfigDisabled` is true | Add after line 146 (after `userThemesDisabled` check) |

---

### Files to DELETE

None.

---

### Files to CONSIDER

| File Path | Reason |
|-----------|--------|
| `application/Espo/Resources/metadata/entityDefs/Portal.json` | Portal has separate tabList system - explicitly **OUT OF SCOPE** for v10 |
| `client/src/views/portal/navbar.js` | Portal navbar - explicitly **OUT OF SCOPE** for v10 |
| `client/src/views/fields/array.js` | Reference for `addItemModalView` pattern (line 112) - may need review for event handling patterns |

---

### Related Files (for reference only, no changes needed)

| File Path | Pattern Reference |
|-----------|-------------------|
| `client/src/views/settings/modals/edit-tab-group.js` | **CRITICAL REFERENCE** for modal model creation pattern (lines 94-117) - `new Model()` with `model.name` and `model.setDefs()` |
| `client/src/views/settings/modals/tab-list-field-add.js` | Add item modal pattern - extends `ArrayFieldAddModalView` |
| `client/src/views/settings/fields/tab-list.js` | **PRIMARY PATTERN** for `navbar-config-list.js` - `generateItemId()` at lines 54-55, `getGroupItemHtml()` uses modern DOM API at lines 138-235 |
| `client/src/views/preferences/fields/tab-list.js` | Preferences field extending Settings field pattern |
| `client/src/helpers/site/tabs.js` | Existing `getTabList()` method to modify (lines 67-80) |
| `client/src/views/site/navbar.js` | Existing navbar view with `tabsHelper` instantiation at line 429, **existing `settings.sync` listener at line 463** |
| `client/res/templates/site/navbar.tpl` | Navbar template with `navbar-left-container` at line 15 |
| `frontend/less/espo/root-variables.less` | CSS variable definitions: `--8px` (line 10), `--12px` (line 14), `--navbar-width` (line 108), `--border-radius` (line 440) |
| `frontend/less/espo/bootstrap/variables.less` | Bootstrap variable `@screen-xs-max` at line 37 |
| `client/src/view-helper.js` | `#ifEqual` Handlebars helper at line 386 |
| `application/Espo/Resources/i18n/en_US/Global.json` (lines 988-994) | Existing `navbarTabs` translation section pattern |
| `client/src/model.js` | Model class for `import Model from 'model'` |
| `client/src/view.js` | `escapeString` method at lines 126-128 |

---

## Error Handling

### Missing Config Fallback
The `getActiveNavbarConfig()` method handles:
- Empty config list → returns `null` → triggers legacy fallback
- Invalid `activeNavbarConfigId` → logs warning → falls back to default/first
- No default set → uses first config

### AJAX Error Handling
The `switchNavbarConfig()` method includes:
- Race condition prevention with `this._switchingConfig` flag
- Loading indicator with `Espo.Ui.notifyWait()`
- try/catch block for network failures
- User-facing error message with `Espo.Ui.error()`
- Console error logging for debugging
- Specific handling for server-side validation errors

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
   - Modify navbar view
   - Modify navbar template

5. **Phase 5: Styling**
   - Add CSS for selector component
   - Update navbar.less
   - Add responsive rules

6. **Phase 6: Testing (Manual)**
   - Test backward compatibility with existing `useCustomTabList`
   - Test ID validation
   - Test missing config fallback
   - Test selector visibility logic
   - Test error handling for AJAX failures
   - Test keyboard navigation
   - Test responsive behavior on mobile

---

## Summary of File Count

- **CREATE**: 8 files
- **EDIT**: 10 files
- **DELETE**: 0 files
- **CONSIDER**: 3 files
- **Reference**: 13 files

---

*Scope document v10 generated with all v9 audit corrections applied - SCOPE MAPPED*
