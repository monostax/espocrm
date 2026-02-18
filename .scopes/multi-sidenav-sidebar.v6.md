# Multi-Sidenav Sidebar Mode - Implementation Plan v6

> **Version**: 6.0  
> **Based on**: `.scopes/multi-sidenav-sidebar.v5.md` and `.scopes/multi-sidenav-sidebar.v5.audit.md`  
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
| `client/src/views/settings/fields/navbar-config-list.js` | Field view for managing navbar configs in Settings - extends ArrayFieldView pattern from tab-list.js (~370 lines). Must implement: `addItemModalView`, `generateItemId()`, `getGroupItemHtml()`, `editGroup()`, `fetchFromDom()`, `getGroupIndexById()`, `getGroupValueById()` |
| `client/src/views/preferences/fields/navbar-config-list.js` | Field view for user-level navbar configs - extends Settings navbar-config-list.js, filters options per preferences/tab-list.js pattern (~65 lines) |
| `client/src/views/preferences/fields/active-navbar-config.js` | Dropdown field to select active config - extends EnumFieldView with dynamic options based on navbarConfigList (~85 lines) - **COMPLETE CODE PROVIDED in v4 scope lines 682-766** |
| `client/src/views/settings/modals/edit-navbar-config.js` | Modal for editing a single navbar config - follows edit-tab-group.js pattern (~140 lines). Fields: name, iconClass, color, tabList, isDefault |
| `client/src/views/modals/navbar-config-field-add.js` | Add item modal for navbar-config-list field - follows tab-list-field-add.js pattern (~94 lines). Allows adding new navbar configs |
| `client/src/views/site/navbar-config-selector.js` | Dropdown selector component rendered in sidebar (~95 lines) - **COMPLETE CODE PROVIDED in v4 scope lines 528-625** |
| `client/res/templates/site/navbar-config-selector.tpl` | Template for navbar config selector dropdown (~45 lines) - **CRITICAL: Must use `#ifEqual` not `#ifEquals`** - **COMPLETE CODE PROVIDED in v4 scope lines 634-675** |
| `frontend/less/espo/elements/navbar-config-selector.less` | Selector component styles (~100 lines) - **COMPLETE CODE PROVIDED in v4 scope lines 886-990** |

---

### Files to EDIT

| File | Changes Required |
|------|-----------------|
| `application/Espo/Resources/metadata/entityDefs/Settings.json` | Add after line 255 (after tabList field): `navbarConfigList` (jsonArray type), `navbarConfigDisabled` (bool), `navbarConfigSelectorDisabled` (bool) field definitions |
| `application/Espo/Resources/metadata/entityDefs/Preferences.json` | Add after line 181 (after tabList field): `navbarConfigList` (jsonArray type), `useCustomNavbarConfig` (bool), `activeNavbarConfigId` (varchar) field definitions |
| `client/src/helpers/site/tabs.js` | **CRITICAL**: Add 4 new methods after line 80: `hasNavbarConfigSystem()`, `getNavbarConfigList()`, `getActiveNavbarConfig()`, `validateNavbarConfigList()`. **MODIFY `getTabList()` at lines 67-80 to check navbar config system FIRST** |
| `client/src/views/site/navbar.js` | Add after line 461: `setupNavbarConfigSelector()` method. Add `switchNavbarConfig()` async method with **race condition prevention** (`this._switchingConfig` flag). Update preferences listener (lines 466-478) to include: `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId` |
| `client/res/templates/site/navbar.tpl` | **CRITICAL**: Add `<div class="navbar-config-selector-container"></div>` element inside `.navbar-left-container` at line 15, BEFORE `<ul class="nav navbar-nav tabs">` |
| `frontend/less/espo/elements/navbar.less` | Add `@import 'navbar-config-selector.less';` at end of file (after line 387) |
| `application/Espo/Resources/layouts/Settings/userInterface.json` | Add to Navbar tab (after line 26): `navbarConfigList` (fullWidth), `navbarConfigDisabled`, `navbarConfigSelectorDisabled` rows |
| `application/Espo/Resources/layouts/Preferences/detail.json` | Add after User Interface tab rows (after line 121): `useCustomNavbarConfig`, `navbarConfigList` (fullWidth), `activeNavbarConfigId` rows |
| `application/Espo/Resources/i18n/en_US/Settings.json` | Add to "fields" section: `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled` labels. Add to "tooltips" section: corresponding tooltips |
| `application/Espo/Resources/i18n/en_US/Preferences.json` | Add to "fields" section: `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId` labels. Add to "tooltips" section: corresponding tooltips |
| `application/Espo/Resources/i18n/en_US/Global.json` | Add new `navbarConfig` section after `navbarTabs` (after line 994) with: `switchView`, `defaultConfig`, `customConfig`, `noConfigs`, `selectConfig`. Also add `errorSavingPreference` to "messages" section |
| `client/src/views/preferences/record/edit.js` | Add after line 146 (after userThemesDisabled check): Dynamic logic to hide navbar config fields when `navbarConfigDisabled` is true |

---

### Files to DELETE

None.

---

### Files to CONSIDER

| File | Reason |
|------|--------|
| `client/src/handlers/navbar-config.js` | **AUDIT FINDING**: May NOT be needed. The selector component triggers `select` event which is handled directly by `navbar.js` via `this.listenTo(view, 'select', ...)`. If no handler actions needed, **REMOVE from manifest**. If needed, should follow `navbar-menu.js` pattern (~49 lines) |
| `application/Espo/Resources/metadata/entityDefs/Portal.json` | Portal has separate tabList system - explicitly **OUT OF SCOPE** for v6, may need updates for future Portal support |
| `client/src/views/portal/navbar.js` | Portal navbar - explicitly **OUT OF SCOPE** for v6 |
| `application/Espo/Controllers/Preferences.php` | May need backend action for quick config switching if using action endpoint approach - currently using `Espo.Ajax.putRequest()` to Preferences REST API (no backend changes needed) |

---

### Related Files (for reference only, no changes needed)

| File | Pattern Reference |
|------|-------------------|
| `client/src/views/settings/modals/edit-tab-group.js` | Modal pattern for edit-navbar-config.js - 140 lines, detailLayout, Model setup, field definitions |
| `client/src/views/settings/modals/edit-tab-url.js` | Additional modal pattern reference - 173 lines |
| `client/src/views/settings/modals/tab-list-field-add.js` | Add item modal pattern for navbar-config-field-add.js - 94 lines |
| `client/src/views/settings/fields/tab-list.js` | **PRIMARY PATTERN** for navbar-config-list.js - 370 lines, ArrayFieldView extension, `generateItemId()`, `getGroupItemHtml()`, `editGroup()` |
| `client/src/views/settings/fields/dashboard-layout.js` | Complex jsonArray field pattern - 581 lines |
| `client/src/views/preferences/fields/tab-list.js` | Preferences field extending Settings field - 65 lines, pattern for preferences/navbar-config-list.js |
| `client/src/views/fields/group-tab-list.js` | Simple extension pattern - 36 lines |
| `client/src/handlers/navbar-menu.js` | Handler pattern - 49 lines, flat structure with ActionHandler import (if handler is needed) |
| `client/src/views/fields/enum.js` | Base class for active-navbar-config.js - EnumFieldView |
| `frontend/less/espo/root-variables.less` | CSS variable definitions: `--navbar-inverse-border` (line 388), `--navbar-inverse-link-hover-bg` (line 392), `--dropdown-link-hover-bg` (line 490), `--dropdown-link-hover-color` (line 489), `--border-radius` (line 440) |
| `frontend/less/espo/bootstrap/variables.less` | `@screen-xs-max` definition at line 37 |
| `frontend/less/espo/elements/navbar.less` | Responsive patterns for mobile at lines 14, 112, 127, 263, 353, 373 |
| `client/src/view-helper.js` | `ifEqual` Handlebars helper at line 386 - **VERIFIED** |
| `application/Espo/Resources/i18n/en_US/Global.json` (lines 988-994) | Existing `navbarTabs` translation section pattern |

---

## Audit Findings Addressed in v6

| Finding | Status | Resolution |
|---------|--------|------------|
| **CRITICAL**: Template helper name mismatch (`#ifEquals` → `#ifEqual`) | ✅ FIXED | Template will use `#ifEqual` which exists at view-helper.js:386 |
| Missing complete code for navbar-config-list.js | ✅ NOTED | Full implementation required following tab-list.js pattern |
| Missing complete code for edit-navbar-config.js | ✅ NOTED | Full implementation required following edit-tab-group.js pattern |
| navbar-config.js handler unclear purpose | ⚠️ CLARIFY | May be removed from manifest if not needed; selector triggers events directly |
| Race condition in AJAX config switch | ✅ ADDRESSED | Add `this._switchingConfig` flag to prevent concurrent requests |
| Missing field validation for activeNavbarConfigId | ✅ ADDRESSED | `getActiveNavbarConfig()` handles orphaned IDs with fallback logic |
| ID collision risk with generateItemId() | 💡 SUGGESTION | Consider timestamp-based IDs in implementation |
| Missing translation key for error message | ✅ ADDRESSED | Add `errorSavingPreference` to Global.json messages |
| Config deletion confirmation | 💡 SUGGESTION | Consider adding confirmation in navbar-config-list.js implementation |

---

## Implementation Order (Recommended)

1. **Phase 1: Data Model** - Add entity definitions (Settings.json, Preferences.json) and translations (i18n files)
2. **Phase 2: Helper Logic** - Modify TabsHelper with new methods, add validation
3. **Phase 3: Admin UI** - Create field views (navbar-config-list.js), modals (edit-navbar-config.js, navbar-config-field-add.js), update layout files
4. **Phase 4: Navbar UI** - Create selector component (navbar-config-selector.js, template), modify navbar view/template
5. **Phase 5: Styling** - Add CSS (navbar-config-selector.less), import in navbar.less
6. **Phase 6: Testing** - Backward compatibility, validation, error handling, keyboard navigation, responsive behavior

---

*File Manifest v6 generated from v5 scope, v5 audit findings, and codebase analysis - READY FOR IMPLEMENTATION*
