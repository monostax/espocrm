# Multi-Sidenav Sidebar Mode v3 - Audit Report

> **Audited File**: `.scopes/multi-sidenav-sidebar.v3.md`  
> **Audit Date**: 2026-02-18  
> **Risk Level**: Medium  
> **Files Reviewed**: 25+ files referenced in scope  
> **Findings**: Critical: 2 | Warnings: 5 | Suggestions: 4

---

## Executive Summary

The scope document v3 successfully addresses the critical issues from v2 audit (AJAX pattern, helper access, Preferences layout). However, several new issues remain including undefined CSS variables and missing implementation details for key components.

---

## Critical Findings (MUST address before implementation)

### 1. CSS Variable `--border-color` Does Not Exist

- **Location:** Scope document line 649, CSS section
- **Evidence:** Searched `root-variables.less` - no `--border-color` variable exists. Only `--border-radius`, `--border-radius-small`, and specific border variables like `--default-border-color` are defined (line 447-448).
- **Assumption:** Scope assumes `--border-color` is a standard CSS variable.
- **Risk:** CSS will fail to apply border styling; selector will look broken.
- **Remedy:** Replace `var(--border-color)` with `var(--default-border-color)` or `var(--navbar-inverse-border)` (line 388) depending on the context:

    ```less
    // WRONG
    border-bottom: 1px solid var(--border-color);
    
    // CORRECT - for sidebar context
    border-bottom: 1px solid var(--navbar-inverse-border);
    // OR
    border-bottom: 1px solid var(--default-border-color);
    ```

### 2. Missing Selector Container in navbar.tpl Template

- **Location:** `client/res/templates/site/navbar.tpl` (to be edited)
- **Evidence:** The existing `navbar.tpl` template (lines 1-219) has no container element for a navbar config selector. The scope references `this.options.el + ' .navbar-config-selector-container'` at line 450-451 but provides no template modification.
- **Assumption:** The selector container will be added to the template implicitly.
- **Risk:** The selector view will fail to render because the target DOM element won't exist.
- **Remedy:** The scope must explicitly show where to add the container in `navbar.tpl`. Based on the template structure, it should be added in the `.navbar-left-container` section, likely before the `<ul class="nav navbar-nav tabs">` element:

    ```html
    <!-- Add in navbar.tpl after line 15, before line 16 -->
    <div class="navbar-config-selector-container"></div>
    ```

---

## Warnings (SHOULD address)

### 1. Field Type Inconsistency: `jsonArray` vs `array`

- **Location:** Scope document line 133, 140; compares to existing `tabList` at Settings.json:245
- **Evidence:** Existing `tabList` uses `"type": "array"` with custom view. The scope proposes `"type": "jsonArray"` for `navbarConfigList`. The `dashboardLayout` field (Settings.json:615) uses `jsonArray` for complex nested objects.
- **Concern:** While `jsonArray` is appropriate for complex objects, the scope should clarify that this differs from `tabList`'s `array` type and explain why.
- **Suggestion:** Document the rationale: `jsonArray` is needed because each config contains nested `tabList` with objects (groups, URLs, dividers), not just string scope names.

### 2. Missing Implementation for `active-navbar-config.js` Field View

- **Location:** Scope document line 251 - lists file to create but no implementation code
- **Evidence:** The scope lists `client/src/views/preferences/fields/active-navbar-config.js` as a file to CREATE but provides no implementation details.
- **Concern:** This field needs to dynamically show available configs based on system/user settings, similar to how other dropdown fields work.
- **Suggestion:** Provide implementation guidance:

    ```javascript
    // Should extend enum or base field view
    // Options should be dynamically fetched from navbarConfigList
    // Must handle both system and user configs
    ```

### 3. Sidebar-Specific CSS Position May Conflict with Responsive Design

- **Location:** Scope document lines 714-723
- **Evidence:** The CSS uses `position: fixed` for the dropdown menu in sidebar mode. The existing `navbar.less` has extensive mobile handling (`@media screen and (max-width: @screen-xs-max)`) that's not addressed.
- **Concern:** On mobile/XS screens, fixed positioning may cause layout issues or the selector may need to be hidden entirely.
- **Suggestion:** Add responsive handling:

    ```less
    @media screen and (max-width: @screen-xs-max) {
        #navbar .navbar-config-selector {
            display: none; // Or adjust for mobile
        }
    }
    ```

### 4. `navbar-config-selector.tpl` Template Not Defined

- **Location:** Scope document line 262 - file to create
- **Evidence:** The scope mentions creating `client/res/templates/site/navbar-config-selector.tpl` but provides no template content.
- **Concern:** Without the template, the selector view cannot render.
- **Suggestion:** Provide template content based on the CSS selectors defined in lines 648-723:

    ```html
    <div class="navbar-config-selector">
        <button class="dropdown-toggle" data-toggle="dropdown">
            <span class="config-icon {{activeConfig.iconClass}}"></span>
            <span class="config-name">{{activeConfig.name}}</span>
            <span class="caret"></span>
        </button>
        <ul class="dropdown-menu">
            {{#each configList}}
            <li><a data-action="selectConfig" data-id="{{id}}">
                <span class="config-icon {{iconClass}}"></span>
                <span class="config-name">{{name}}</span>
            </a></li>
            {{/each}}
        </ul>
    </div>
    ```

### 5. Error Handling for AJAX Failure Not Implemented

- **Location:** Scope document lines 459-470 (`switchNavbarConfig` method)
- **Evidence:** The method uses `await Espo.Ajax.putRequest()` but has no try/catch or error handling.
- **Concern:** Network failures or permission errors will leave the UI in an inconsistent state.
- **Suggestion:** Add error handling:

    ```javascript
    async switchNavbarConfig(configId) {
        try {
            await Espo.Ajax.putRequest('Preferences/' + this.getUser().id, {
                activeNavbarConfigId: configId
            });
            // ... success handling
        } catch (error) {
            Espo.Ui.error(this.translate('Error saving preference'));
            console.error('Failed to switch navbar config:', error);
        }
    }
    ```

---

## Suggestions (CONSIDER addressing)

### 1. Missing Dynamic Logic for Field Visibility

- **Context:** The scope adds `navbarConfigList` to both Settings and Preferences
- **Observation:** Similar to how `preferences/record/edit.js` controls visibility of fields based on config (e.g., lines 144-146 hide theme field if `userThemesDisabled`), there should be logic to hide `navbarConfigList` fields if `navbarConfigDisabled` is true.
- **Enhancement:** Add dynamic logic in preferences edit view:

    ```javascript
    if (this.getConfig().get('navbarConfigDisabled')) {
        this.hideField('navbarConfigList');
        this.hideField('useCustomNavbarConfig');
    }
    ```

### 2. Cache Invalidation Not Addressed

- **Context:** Tabs are cached in various places
- **Observation:** The `tabsHelper` is instantiated once in `navbar.js:429`. After switching configs, the cached tab list needs to be refreshed.
- **Enhancement:** Ensure `getTabList()` always calls `this.preferences.get()` rather than caching, or explicitly clear cache after config switch.

### 3. Consider Keyboard Navigation

- **Context:** Dropdown menus need keyboard accessibility
- **Observation:** Existing dropdown patterns in the codebase use `tabindex="0"` and keyboard handlers
- **Enhancement:** Add keyboard navigation support (arrow keys, Enter, Escape) to the selector component

### 4. Add Loading State During Config Switch

- **Context:** Network request for switching config
- **Observation:** Other AJAX operations use `Espo.Ui.notifyWait()` to show loading state
- **Enhancement:** Add loading notification during config switch:

    ```javascript
    async switchNavbarConfig(configId) {
        Espo.Ui.notifyWait();
        // ... rest of implementation
        Espo.Ui.notify(false);
    }
    ```

---

## Validated Items

The following aspects of the plan are well-supported by the codebase:

| Item | Status | Evidence |
|------|--------|----------|
| `tabs.js` `getTabList()` location and pattern | ✓ Valid | Lines 67-79 match scope |
| `this.tabsHelper` instantiation | ✓ Valid | navbar.js:429 confirmed |
| `Espo.Ajax.putRequest()` pattern | ✓ Valid | Found 13 usages in codebase |
| `edit-tab-group.js` modal pattern | ✓ Valid | Lines 32-139 exist |
| `tab-list.js` `generateItemId()` pattern | ✓ Valid | Lines 54-56 match scope |
| `navbarTabs` translation section | ✓ Valid | Global.json:988-994 exists |
| `--navbar-width` CSS variable | ✓ Valid | root-variables.less:108 |
| `--navbar-inverse-link-hover-bg` CSS variable | ✓ Valid | root-variables.less:392 |
| `--dropdown-link-hover-bg` CSS variable | ✓ Valid | root-variables.less:490 |
| Preferences layout file location | ✓ Valid | detail.json exists |
| Settings layout file location | ✓ Valid | userInterface.json exists |
| CSS `--8px`, `--12px`, `--border-radius` variables | ✓ Valid | root-variables.less lines 10, 14, 440-441 |
| Handler flat structure pattern | ✓ Valid | navbar-menu.js uses ActionHandler import |
| Preferences save pattern | ✓ Valid | edit.js lines 79-86 show trigger pattern |

---

## Recommended Next Steps

1. **Fix CSS variable**: Replace `var(--border-color)` with `var(--navbar-inverse-border)` in the CSS section
2. **Add template modifications**: Explicitly show where to add `.navbar-config-selector-container` in navbar.tpl
3. **Provide missing implementations**: Add template content for `navbar-config-selector.tpl` and implementation for `active-navbar-config.js`
4. **Add error handling**: Wrap AJAX calls in try/catch blocks
5. **Add responsive CSS**: Include mobile breakpoints for the selector component
6. **Document field type choice**: Explain why `jsonArray` is used vs `array`

---

## Files Referenced During Audit

| File | Purpose |
|------|---------|
| `client/src/helpers/site/tabs.js` | Current tab resolution logic |
| `client/src/views/site/navbar.js` | Main navbar view |
| `application/Espo/Resources/metadata/entityDefs/Settings.json` | Settings field definitions |
| `application/Espo/Resources/metadata/entityDefs/Preferences.json` | Preferences field definitions |
| `client/res/templates/site/navbar.tpl` | Navbar template |
| `frontend/less/espo/elements/navbar.less` | Navbar styles |
| `frontend/less/espo/root-variables.less` | CSS variable definitions |
| `application/Espo/Resources/layouts/Settings/userInterface.json` | Admin UI layout |
| `application/Espo/Resources/layouts/Preferences/detail.json` | Preferences layout |
| `client/src/views/settings/fields/tab-list.js` | Tab list field view |
| `client/src/views/settings/modals/edit-tab-group.js` | Modal pattern reference |
| `client/src/views/settings/modals/edit-tab-url.js` | URL tab modal pattern |
| `client/src/views/settings/fields/dashboard-layout.js` | Complex field pattern |
| `client/src/views/preferences/fields/tab-list.js` | Preferences tab list field |
| `client/src/views/preferences/record/edit.js` | Preferences edit view |
| `application/Espo/Resources/i18n/en_US/Settings.json` | Settings translations |
| `application/Espo/Resources/i18n/en_US/Preferences.json` | Preferences translations |
| `application/Espo/Resources/i18n/en_US/Global.json` | Global translations |
| `client/src/handlers/navbar-menu.js` | Handler pattern reference |
| `client/src/theme-manager.js` | Theme management |

---

_Audit report generated for multi-sidenav-sidebar.v3.md scope document_
