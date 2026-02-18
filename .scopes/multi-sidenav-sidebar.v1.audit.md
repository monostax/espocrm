# Multi-Sidenav Sidebar Mode - Scope Audit Report

**Scope File:** `.scopes/multi-sidenav-sidebar.v1.md`
**Audit Date:** 2026-02-18
**Risk Level:** High
**Files Reviewed:** 20+ files verified
**Findings:** Critical: 4 | Warnings: 5 | Suggestions: 4

---

## Executive Summary

The scope document provides good architectural analysis but contains several incorrect file paths, missing implementation details, and unstated assumptions that could cause implementation failures.

---

## Critical Findings (MUST address before implementation)

### 1. Incorrect Template Path Structure
- **Location:** Scope section "Files to CREATE" - Templates
- **Evidence:** 
  - Scope proposes: `client/src/res/templates/admin/navbar-config/*.tpl`
  - Actual structure: Templates are at `client/res/templates/` (not `client/src/res/templates/`)
  - No `admin/` subdirectory exists under templates - verified via glob pattern returned 0 results
- **Assumption:** The scope assumes templates follow the same path pattern as JS source files
- **Risk:** Templates will not be found by the loader, causing 404 errors or fallback failures
- **Remedy:** Correct template paths to `client/res/templates/admin/navbar-config/*.tpl` and ensure the loader can resolve admin-specific templates

### 2. Incorrect CSS Component Directory
- **Location:** Scope section "Files to CREATE" - Styles
- **Evidence:**
  - Scope proposes: `frontend/less/espo/components/navbar-config-selector.less`
  - Actual structure: No `components/` directory exists under `frontend/less/espo/`
  - Existing styles are in `elements/`, `misc/`, `bootstrap/`, etc.
- **Assumption:** A `components/` directory exists or will be created
- **Risk:** CSS file won't be compiled/included, causing broken selector UI
- **Remedy:** Either create the `components/` directory and ensure it's included in the build, or place styles in `frontend/less/espo/elements/navbar.less` alongside existing navbar styles

### 3. Missing Handler Directory Structure
- **Location:** Scope proposes `client/src/handlers/site/navbar-config.js`
- **Evidence:**
  - Existing handlers are flat: `client/src/handlers/navbar-menu.js`, `client/src/handlers/login.js`
  - No `handlers/site/` subdirectory exists
- **Assumption:** Handlers can be organized into subdirectories
- **Remedy:** Either create the `handlers/site/` directory structure or place the handler flat at `client/src/handlers/navbar-config.js`

### 4. Incomplete Resolution Logic - Missing Existing Preference Fields
- **Location:** Scope section "Resolution Logic" and `TabsHelper` modifications
- **Evidence:**
  - `client/src/helpers/site/tabs.js:68-77` shows existing logic uses `useCustomTabList` and `addCustomTabs`
  - `client/src/views/site/navbar.js:471-478` listens for these fields
  - `application/Espo/Resources/metadata/entityDefs/Preferences.json:162-170` defines these fields
- **Assumption:** The new `navbarConfigList` system operates independently of existing tab customization
- **Risk:** Conflict with existing user preferences; users who already have `useCustomTabList` enabled may see unexpected behavior
- **Remedy:** Extend resolution logic to account for:
  ```
  1. If useCustomTabList is true and navbarConfigList is empty → use existing tabList preference
  2. If navbarConfigList exists → use new system (ignore old preferences)
  3. Migration path for existing custom tabList users
  ```

---

## Warnings (SHOULD address)

### 1. Missing Preferences AJAX Save Pattern
- **Location:** Scope section "Implementation Details - Navbar View Modifications"
- **Evidence:** No grep results for `ajax.*request.*Preferences` or `Preferences.*ajax.*save`
- **Concern:** Scope mentions "Store selected config in preferences via AJAX" but doesn't show the API pattern
- **Suggestion:** Document the actual API call pattern:
  ```javascript
  this.ajaxPostRequest('Preferences/action/update', {
      activeNavbarConfigId: configId
  }).then(() => {
      this.getPreferences().set('activeNavbarConfigId', configId);
  });
  ```

### 2. Portal Support Not Addressed
- **Location:** `application/Espo/Resources/metadata/entityDefs/Portal.json:35-46`
- **Evidence:** Portal has its own `tabList` field with different view (`views/portal/fields/tab-list`)
- **Concern:** Scope Question #5 asks about Portal support but provides no implementation path
- **Suggestion:** Either explicitly exclude Portal from scope or add:
  - `Portal.json` entityDefs modifications
  - Portal-specific field views
  - Portal navbar view modifications

### 3. i18n Coverage Not Specified
- **Location:** Scope section "Translation Keys"
- **Evidence:** Grep found 75+ language files with `useCustomTabList` translations
- **Concern:** Scope only shows `en_US` translations; missing translations for all other locales will show untranslated keys
- **Suggestion:** Document that translations need to be added to all language files, or add fallback to English

### 4. Admin Template Structure Unclear
- **Location:** Scope proposes admin views at `client/src/views/admin/navbar-config/`
- **Evidence:** 
  - Existing admin views like `user-interface.js` are simple extensions of `SettingsEditRecordView`
  - No `admin/` templates exist in `client/res/templates/`
- **Concern:** The proposed admin views with custom templates may not fit the existing pattern
- **Suggestion:** Consider if navbar config should:
  - Be a field on the existing User Interface page (simpler)
  - Have a dedicated admin page (more complex, needs routing)

### 5. Storage vs Preference Conflict
- **Location:** `client/src/views/site/navbar.js:362,366,959`
- **Evidence:** Current navbar uses `getStorage().set('state', 'siteLayoutState', ...)` for layout state (local storage)
- **Concern:** Scope proposes `activeNavbarConfigId` in Preferences (server-side) but no mention of whether this should also be cached locally
- **Suggestion:** Decide on persistence strategy:
  - Server-side only (Preferences) = syncs across devices
  - Local storage only = per-device preference
  - Hybrid = local cache with server sync

---

## Suggestions (CONSIDER addressing)

### 1. Missing Field View Template
- **Context:** Scope proposes `client/src/res/templates/settings/fields/navbar-config-list.tpl`
- **Observation:** Grep for `client/res/templates/settings/fields/*.tpl` found only `currency-rates` and `dashboard-layout` templates
- **Enhancement:** The `tab-list` field view (`client/src/views/settings/fields/tab-list.js`) doesn't use a custom template - it extends `ArrayFieldView`. Consider whether `navbar-config-list` actually needs a custom template or can extend existing array field views

### 2. No Data Migration Plan
- **Context:** Scope section "Questions/Decisions Needed" #1
- **Observation:** Question about migrating existing `tabList` to first navbar config is unanswered
- **Enhancement:** Provide explicit migration strategy:
  - Option A: Keep existing `tabList` as fallback, don't migrate
  - Option B: Auto-create "Default" navbar config from existing `tabList` on first access
  - Option C: Run one-time migration script

### 3. Missing Validation for Config ID Uniqueness
- **Context:** Data model shows `id` field in navbar config
- **Observation:** No validation mentioned for ensuring unique IDs across system and user configs
- **Enhancement:** Add validation logic:
  ```javascript
  // When saving navbarConfigList
  const ids = configList.map(c => c.id);
  if (new Set(ids).size !== ids.length) {
      // Error: duplicate IDs
  }
  ```

### 4. No Error Handling for Missing Config
- **Context:** Resolution logic references `activeNavbarConfigId`
- **Observation:** No handling for case where stored ID doesn't match any existing config (deleted by admin)
- **Enhancement:** Add fallback:
  ```javascript
  const activeConfig = configList.find(c => c.id === activeId) || 
      configList.find(c => c.isDefault) || 
      configList[0];
  ```

---

## Validated Items

The following aspects of the plan are well-supported by existing codebase patterns:

| Item | Evidence Location | Status |
|------|-------------------|--------|
| Theme navbar parameter structure | `application/Espo/Resources/metadata/themes/Espo.json:6-13` | ✅ Confirmed |
| tabList field in Settings | `application/Espo/Resources/metadata/entityDefs/Settings.json:245-255` | ✅ Confirmed |
| tabList field in Preferences | `application/Espo/Resources/metadata/entityDefs/Preferences.json:171-181` | ✅ Confirmed |
| TabsHelper class structure | `client/src/helpers/site/tabs.js` | ✅ Confirmed |
| Navbar view architecture | `client/src/views/site/navbar.js` | ✅ Confirmed |
| Modal patterns for tab editing | `client/src/views/settings/modals/edit-tab-group.js` | ✅ Confirmed |
| jsonArray field type | 84 matches across codebase | ✅ Confirmed valid |
| Handler pattern | `client/src/handlers/navbar-menu.js` | ✅ Confirmed |
| Admin view pattern | `client/src/views/admin/user-interface.js` | ✅ Confirmed |

---

## Recommended Next Steps

1. **Fix file paths**: Correct template path to `client/res/templates/` and CSS path to `frontend/less/espo/elements/` or create proper directories with build system updates

2. **Document Preferences API**: Add explicit API call pattern for saving `activeNavbarConfigId` to preferences

3. **Clarify resolution logic**: Update resolution logic to account for existing `useCustomTabList` and `addCustomTabs` fields, with migration path

4. **Answer scope questions**: Make decisions on Portal support, default behavior, and admin UI approach before implementation

5. **Add missing validation**: Include ID uniqueness validation and missing config fallback handling

---

## Corrected File Manifest

Based on audit findings, the corrected paths should be:

### Files to CREATE

#### Views - Admin UI
| File | Purpose |
|------|---------|
| `client/src/views/admin/navbar-config/index.js` | Main admin view for managing navbar configs |
| `client/src/views/admin/navbar-config/item-list.js` | List view showing all navbar configurations |
| `client/src/views/admin/navbar-config/record/edit.js` | Edit view for single navbar config |
| `client/res/templates/admin/navbar-config/index.tpl` | Template for navbar config management page |
| `client/res/templates/admin/navbar-config/item-list.tpl` | Template for navbar config list |

#### Views - Field Views
| File | Purpose |
|------|---------|
| `client/src/views/settings/fields/navbar-config-list.js` | Field view for managing navbar configs |
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
| `client/src/handlers/navbar-config.js` | Handler for config switching (flat, not in site/) |

#### Styles
| File | Purpose |
|------|---------|
| `frontend/less/espo/elements/navbar-config-selector.less` | Selector component styles (or add to navbar.less) |

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

*Audit completed by Scope Mapper agent*
