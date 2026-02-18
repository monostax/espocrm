# Multi-Sidenav Sidebar Mode - Implementation Plan v5

> **Version**: 5.0  
> **Based on**: `.scopes/multi-sidenav-sidebar.v4.md` and `.scopes/multi-sidenav-sidebar.v4.audit.md`  
> **Codebase Root**: `components/crm/source/`  
> **Status**: File Manifest - READY FOR IMPLEMENTATION

## Overview

Feature request to implement a multi-sidenav sidebar mode allowing users to toggle between different navbar configurations using a dropdown selector in the sidebar.

### Requirements
1. **UI Pattern**: Dropdown/selector in the sidebar for switching views
2. **Configuration**: Each navbar config has its own complete `tabList`
3. **Levels**: Both system-level defaults and user-level overrides
4. **Quantity**: Unlimited configurable navbar views

---

## File Manifest

### Files to CREATE

| File | Reason |
|------|--------|
| `client/src/views/settings/fields/navbar-config-list.js` | Field view for managing navbar configs in Settings (extends ArrayFieldView pattern from tab-list.js) |
| `client/src/views/preferences/fields/navbar-config-list.js` | Field view for user-level navbar configs (extends Settings version following preferences/tab-list.js pattern) |
| `client/src/views/preferences/fields/active-navbar-config.js` | Dropdown field to select active config from available options (extends EnumFieldView) - **COMPLETE CODE PROVIDED IN v4 SCOPE** |
| `client/src/views/settings/modals/edit-navbar-config.js` | Modal for editing a single navbar config (follows edit-tab-group.js pattern) |
| `client/src/views/site/navbar-config-selector.js` | Dropdown selector component rendered in sidebar - **COMPLETE CODE PROVIDED IN v4 SCOPE** |
| `client/res/templates/site/navbar-config-selector.tpl` | Template for navbar config selector dropdown - **CRITICAL: Use `#ifEqual` not `#ifEquals` per audit finding** |
| `client/src/handlers/navbar-config.js` | Handler for config switching actions (flat structure per navbar-menu.js pattern) |
| `frontend/less/espo/elements/navbar-config-selector.less` | Selector component styles - **COMPLETE CODE PROVIDED IN v4 SCOPE** |

---

### Files to EDIT

| File | Changes Required |
|------|-----------------|
| `application/Espo/Resources/metadata/entityDefs/Settings.json` | Add `navbarConfigList` (jsonArray), `navbarConfigDisabled` (bool), `navbarConfigSelectorDisabled` (bool) field definitions after line 255 (after tabList field) |
| `application/Espo/Resources/metadata/entityDefs/Preferences.json` | Add `navbarConfigList` (jsonArray), `useCustomNavbarConfig` (bool), `activeNavbarConfigId` (varchar) field definitions after line 181 (after tabList field) |
| `client/src/helpers/site/tabs.js` | Add `hasNavbarConfigSystem()`, `getNavbarConfigList()`, `getActiveNavbarConfig()`, `validateNavbarConfigList()` methods; **CRITICAL: Modify `getTabList()` to check navbar config system FIRST** (lines 67-80) |
| `client/src/views/site/navbar.js` | Add `setupNavbarConfigSelector()` method after line 461; Add `switchNavbarConfig()` async method; Update preferences listener (lines 466-478) to include new fields: `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId` |
| `client/res/templates/site/navbar.tpl` | **CRITICAL**: Add `<div class="navbar-config-selector-container"></div>` element inside `.navbar-left-container` (after line 15, before `<ul class="nav navbar-nav tabs">`) |
| `frontend/less/espo/elements/navbar.less` | Add `@import 'navbar-config-selector.less';` at the end of the file |
| `application/Espo/Resources/layouts/Settings/userInterface.json` | Add navbar config fields to the Navbar tab section (after line 26): `navbarConfigList` (fullWidth), `navbarConfigDisabled`, `navbarConfigSelectorDisabled` |
| `application/Espo/Resources/layouts/Preferences/detail.json` | Add navbar config fields under User Interface tab section (after line 121): `useCustomNavbarConfig`, `navbarConfigList` (fullWidth), `activeNavbarConfigId` |
| `application/Espo/Resources/i18n/en_US/Settings.json` | Add field labels and tooltips for navbarConfigList, navbarConfigDisabled, navbarConfigSelectorDisabled |
| `application/Espo/Resources/i18n/en_US/Preferences.json` | Add field labels and tooltips for navbarConfigList, useCustomNavbarConfig, activeNavbarConfigId |
| `application/Espo/Resources/i18n/en_US/Global.json` | Add `navbarConfig` section with: switchView, defaultConfig, customConfig, noConfigs, selectConfig |
| `client/src/views/preferences/record/edit.js` | Add dynamic logic to hide navbar config fields when `navbarConfigDisabled` is true (add after line 146) |

---

### Files to DELETE

None.

---

### Files to CONSIDER

| File | Reason |
|------|--------|
| `client/src/views/portal/navbar.js` | If Portal support added later (explicitly out of scope for v4 per scope document) |
| `application/Espo/Resources/metadata/entityDefs/Portal.json` | Portal has separate tabList system; may need updates for future Portal support |
| `application/Espo/Controllers/Preferences.php` | May need backend action for quick config switching if using action endpoint approach (currently using `Espo.Ajax.putRequest()` to Preferences REST API) |
| `frontend/less/espo/bootstrap/variables.less` | Verify `@screen-xs-max` is defined (confirmed at line 37: `@screen-xs-max: (@screen-sm-min - 1px);`) |

---

### Related Files (for reference only, no changes needed)

| File | Pattern Reference |
|------|-------------------|
| `client/src/views/settings/modals/edit-tab-group.js` | Modal pattern for editing configs - use as template for edit-navbar-config.js |
| `client/src/views/settings/modals/edit-tab-url.js` | URL tab pattern - reference for modal structure |
| `client/src/views/settings/modals/edit-tab-divider.js` | Divider pattern - reference for modal structure |
| `client/src/views/settings/fields/tab-list.js` | ID generation pattern (`generateItemId()` at lines 54-55), array field with modal editing - **PRIMARY PATTERN** |
| `client/src/views/settings/fields/dashboard-layout.js` | Complex field with modal editing, nested tabs - confirms `jsonArray` type pattern |
| `client/src/views/preferences/fields/tab-list.js` | Preferences field extending Settings field - pattern for preferences/navbar-config-list.js |
| `client/src/handlers/navbar-menu.js` | Handler pattern (flat structure with ActionHandler import) - pattern for navbar-config.js |
| `frontend/less/espo/root-variables.less` | CSS variable definitions - use `--navbar-inverse-border` (line 388), `--navbar-inverse-link-hover-bg` (line 392), `--dropdown-link-hover-bg` (line 490), `--dropdown-link-hover-color` (line 489), `--border-radius` (line 440) |
| `frontend/less/espo/elements/navbar.less` | Responsive patterns for mobile - existing mobile handling at lines 14, 112, 127, 263, 353, 373 using `@media screen and (max-width: @screen-xs-max)` |
| `application/Espo/Resources/i18n/en_US/Global.json` (lines 988-994) | Existing `navbarTabs` translation section pattern |
| `client/src/views/fields/enum.js` | Base class for active-navbar-config.js field view |
| `client/src/view-helper.js` (lines 448-457) | `translate` helper uses `scope` parameter - verify custom scope handling |

---

## Audit Findings Summary (MUST ADDRESS)

### Critical Issues
1. **Template Error**: Change `#ifEquals` to `#ifEqual` in `navbar-config-selector.tpl` template - **WILL CAUSE RUNTIME FAILURE**

### High Priority
2. **CSS Variable**: `@screen-xs-max` is **VERIFIED** at `frontend/less/espo/bootstrap/variables.less:37`

### Medium Priority  
3. **Missing Implementations**: The scope lists `navbar-config-list.js` field views for both Settings and Preferences but does NOT provide complete code - these require significant custom development following the `tab-list.js` pattern

### Low Priority
4. **ID Generation**: Consider improving `generateItemId()` for better collision resistance (current: `Math.floor(Math.random() * 1000000 + 1).toString()` only generates ~1 million unique IDs)

---

## Implementation Order (Recommended)

1. **Phase 1: Data Model** - Add entity definitions (Settings.json, Preferences.json) and translations
2. **Phase 2: Helper Logic** - Modify TabsHelper with new methods
3. **Phase 3: Admin UI** - Create field views and modals, update layout files
4. **Phase 4: Navbar UI** - Create selector component, handler, modify navbar view/template
5. **Phase 5: Styling** - Add CSS, import in navbar.less
6. **Phase 6: Testing** - Test backward compatibility, validation, error handling, keyboard navigation, responsive behavior

---

*File Manifest v5 generated from v4 scope and audit - READY FOR IMPLEMENTATION*
