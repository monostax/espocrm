# Multi-Sidenav Sidebar Mode v2 - Audit Report

> **Audited File**: `.scopes/multi-sidenav-sidebar.v2.md`  
> **Audit Date**: 2026-02-18  
> **Risk Level**: High  
> **Files Reviewed**: 20+ files referenced in scope  
> **Findings**: Critical: 4 | Warnings: 6 | Suggestions: 4

---

## Executive Summary

The scope document v2 shows significant improvement over v1 but contains several critical technical inaccuracies and missing considerations that would cause implementation failures.

---

## Critical Findings (MUST address before implementation)

### 1. Incorrect AJAX Pattern - `ajaxPostRequest` Method Does Not Exist

- **Location:** Scope document line 402
- **Evidence:** Searched entire `client/src/views` directory - no `ajaxPostRequest` method found on views. Codebase uses `Espo.Ajax.postRequest()` static method.
- **Assumption:** Scope assumes views have an `ajaxPostRequest` method like `this.ajaxPostRequest()`.
- **Risk:** Runtime error - code will fail immediately when switching navbar config.
- **Remedy:** Replace:

    ```javascript
    // WRONG (scope document)
    await this.ajaxPostRequest('Preferences/action/update', {...});

    // CORRECT (codebase pattern)
    await Espo.Ajax.postRequest('Preferences/action/update', {...});
    ```

### 2. Incorrect Helper Access Pattern

- **Location:** Scope document line 384
- **Evidence:** In `navbar.js:429`, `tabsHelper` is instantiated as `this.tabsHelper = new TabsHelper(...)`, not stored on the helper object.
- **Assumption:** Scope assumes `this.getHelper().tabsHelper` exists.
- **Risk:** Runtime error - `tabsHelper` is undefined.
- **Remedy:** Use `this.tabsHelper` directly in navbar.js modifications:

    ```javascript
    // WRONG (scope document)
    const configList = this.getHelper().tabsHelper.getNavbarConfigList();

    // CORRECT
    const configList = this.tabsHelper.getNavbarConfigList();
    ```

### 3. Missing Backend API Endpoint

- **Location:** Scope document proposes `Preferences/action/update` endpoint
- **Evidence:** Searched codebase - no `Preferences/action/update` action exists. Preferences are typically saved via model save or `Espo.Ajax.putRequest('Preferences/' + userId, data)`.
- **Assumption:** An action endpoint exists for updating a single preference field.
- **Risk:** 404 error when attempting to switch navbar config.
- **Remedy:** Either:
    1. Create a new backend action `Preferences/action/update` in PHP
    2. Use existing pattern: `Espo.Ajax.putRequest('Preferences/' + userId, {activeNavbarConfigId: ...})`

### 4. Template Path Non-Existent for navbar-side.tpl

- **Location:** Scope document line 287
- **Evidence:** Searched for `**/site/navbar-side.tpl` - file does not exist.
- **Assumption:** A separate sidebar template exists for side navbar mode.
- **Risk:** Confusion during implementation; the existing `navbar.tpl` handles both modes via conditional logic.
- **Remedy:** The scope correctly identifies this as "Files to CONSIDER" - remove or clarify that modifications should be to `navbar.tpl` only.

---

## Warnings (SHOULD address)

### 1. Existing `navbarTabs` Translation Section Overlooked

- **Location:** `application/Espo/Resources/i18n/en_US/Global.json:988-994`
- **Evidence:** Already contains preset tab names:
    ```json
    "navbarTabs": {
        "Business": "Business",
        "Marketing": "Marketing",
        "Support": "Support",
        "CRM": "CRM",
        "Activities": "Activities"
    }
    ```
- **Concern:** This suggests pre-existing planning or partial implementation. Scope does not mention using or extending this.
- **Suggestion:** Investigate if this is meant to be used, or if `navbarConfigList` should use different translation keys.

### 2. CSS Variable Names Unverified

- **Location:** Scope document CSS section (lines 474-555)
- **Evidence:** CSS uses variables like `--navbar-inverse-link-hover-bg`, `--navbar-width`, `--8px`, etc.
- **Concern:** These variable names need verification against actual LESS variable definitions.
- **Suggestion:** Verify CSS variables exist in `frontend/less/espo/variables.less` or similar before implementation.

### 3. ID Generation Method Conflict

- **Location:** Scope document line 54 (random ID) vs `tab-list.js:54-56`
- **Evidence:** `tab-list.js` generates IDs with `Math.floor(Math.random() * 1000000 + 1).toString()` but scope proposes different format.
- **Concern:** Inconsistent ID generation could cause issues with uniqueness validation.
- **Suggestion:** Use consistent ID generation approach:
    ```javascript
    // Existing pattern in tab-list.js
    generateItemId() {
        return Math.floor(Math.random() * 1000000 + 1).toString();
    }
    ```

### 4. Missing Preferences Layout File

- **Location:** Scope document proposes Preferences fields but no layout modifications
- **Evidence:** `application/Espo/Resources/layouts/Preferences/` only has `detail.json` and `detailPortal.json`.
- **Concern:** User preferences UI needs layout updates for new fields (`navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId`).
- **Suggestion:** Add `application/Espo/Resources/layouts/Preferences/detail.json` to EDIT list.

### 5. Backend Validation Not Addressed

- **Location:** Scope document has frontend validation only
- **Evidence:** `validateNavbarConfigList()` only runs on frontend.
- **Concern:** Backend should also validate ID uniqueness and data integrity.
- **Suggestion:** Add backend validator in PHP or document that validation is frontend-only.

---

## Suggestions (CONSIDER addressing)

### 1. Consider Using Existing Translation Infrastructure

- **Context:** `Global.json` already has `navbarTabs` section
- **Observation:** Could use `$navbarTabs.TranslationKey` pattern like `$label:General` used in layouts
- **Enhancement:** Use `text: "$Business"` pattern in navbar config names to enable translation

### 2. Add WebSocket Sync for Multi-Device

- **Context:** Changing navbar config on one device doesn't sync to others
- **Observation:** System has WebSocket support (`useWebSocket` setting)
- **Enhancement:** Consider broadcasting `navbarConfigId` changes via WebSocket for real-time sync

### 3. Add Audit Log for Config Changes

- **Context:** Admin changes to navbar configurations affect all users
- **Observation:** System has action history logging
- **Enhancement:** Log navbar config changes in action history for accountability

### 4. Consider Mobile/Responsive Behavior

- **Context:** CSS in scope only addresses desktop layout
- **Observation:** Navbar has mobile-specific code (`@media screen and (max-width: @screen-xs-max)`)
- **Enhancement:** Add responsive CSS for navbar config selector on mobile devices

---

## Validated Items

The following aspects of the plan are well-supported by the codebase:

| Item                                         | Status  | Evidence                                                                |
| -------------------------------------------- | ------- | ----------------------------------------------------------------------- |
| Settings.json `tabList` field location       | ✓ Valid | Line 245 confirmed                                                      |
| Preferences.json `useCustomTabList` location | ✓ Valid | Line 162 confirmed                                                      |
| Preferences.json `addCustomTabs` location    | ✓ Valid | Line 166 confirmed                                                      |
| Preferences.json `tabList` location          | ✓ Valid | Line 171 confirmed                                                      |
| Resolution logic in tabs.js                  | ✓ Valid | Lines 67-79 match scope description                                     |
| navbar.js preferences listener               | ✓ Valid | Lines 466-478 exist and follow described pattern                        |
| Modal pattern reference                      | ✓ Valid | `views/settings/modals/edit-tab-group.js` exists                        |
| handlers directory structure                 | ✓ Valid | `client/src/handlers/` exists with flat structure                       |
| Admin.json structure                         | ✓ Valid | Existing labels can be extended                                         |
| CSS path                                     | ✓ Valid | `frontend/less/espo/elements/navbar.less` exists                        |
| Template path for navbar.tpl                 | ✓ Valid | `client/res/templates/site/navbar.tpl` exists                           |
| Layout file path                             | ✓ Valid | `application/Espo/Resources/layouts/Settings/userInterface.json` exists |
| Tab-list field view                          | ✓ Valid | `client/src/views/settings/fields/tab-list.js` exists                   |
| Dashboard-layout pattern                     | ✓ Valid | Complex field with modal editing exists                                 |

---

## Recommended Next Steps

1. **Fix AJAX pattern**: Change `this.ajaxPostRequest()` to `Espo.Ajax.postRequest()` in all code examples
2. **Fix helper access**: Change `this.getHelper().tabsHelper` to `this.tabsHelper` in navbar.js modifications
3. **Create backend endpoint**: Add PHP action handler for `Preferences/action/update` OR document alternative save pattern
4. **Add Preferences layout**: Include `application/Espo/Resources/layouts/Preferences/detail.json` modification in file manifest
5. **Investigate navbarTabs**: Clarify relationship with existing `Global.navbarTabs` translation section
6. **Add test plan**: Create unit test files for TabsHelper modifications

---

## Corrected Code Snippets

### Corrected `switchNavbarConfig` Method

```javascript
// client/src/views/site/navbar.js - corrected implementation
async switchNavbarConfig(configId) {
    await Espo.Ajax.postRequest('Preferences/action/update', {
        activeNavbarConfigId: configId
    });

    this.getPreferences().set('activeNavbarConfigId', configId);

    // Trigger re-render of tabs
    this.trigger('navbar-config-changed');
}
```

### Corrected `setupNavbarConfigSelector` Method

```javascript
// client/src/views/site/navbar.js - corrected implementation
setupNavbarConfigSelector() {
    if (this.config.get('navbarConfigSelectorDisabled')) {
        return;
    }

    // Use this.tabsHelper directly, not this.getHelper().tabsHelper
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
```

---

## Files Referenced During Audit

| File                                                              | Purpose                       |
| ----------------------------------------------------------------- | ----------------------------- |
| `client/src/helpers/site/tabs.js`                                 | Current tab resolution logic  |
| `client/src/views/site/navbar.js`                                 | Main navbar view              |
| `application/Espo/Resources/metadata/entityDefs/Settings.json`    | Settings field definitions    |
| `application/Espo/Resources/metadata/entityDefs/Preferences.json` | Preferences field definitions |
| `client/res/templates/site/navbar.tpl`                            | Navbar template               |
| `frontend/less/espo/elements/navbar.less`                         | Navbar styles                 |
| `application/Espo/Resources/layouts/Settings/userInterface.json`  | Admin UI layout               |
| `client/src/views/settings/fields/tab-list.js`                    | Tab list field view           |
| `client/src/views/settings/modals/edit-tab-group.js`              | Modal pattern reference       |
| `client/src/views/settings/fields/dashboard-layout.js`            | Complex field pattern         |
| `application/Espo/Resources/i18n/en_US/Settings.json`             | Settings translations         |
| `application/Espo/Resources/i18n/en_US/Preferences.json`          | Preferences translations      |
| `application/Espo/Resources/i18n/en_US/Admin.json`                | Admin translations            |
| `application/Espo/Resources/i18n/en_US/Global.json`               | Global translations           |
| `client/src/views/preferences/fields/tab-list.js`                 | Preferences tab list field    |
| `client/src/view.js`                                              | Base view class               |
| `client/src/theme-manager.js`                                     | Theme management              |

---

_Audit report generated for multi-sidenav-sidebar.v2.md scope document_

