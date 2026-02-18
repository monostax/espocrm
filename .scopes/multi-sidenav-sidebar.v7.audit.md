# Multi-Sidenav Sidebar Mode - Audit Report v7

> **Audited File**: `.scopes/multi-sidenav-sidebar.v7.md`
> **Audit Date**: 2026-02-18
> **Risk Level**: High
> **Files Reviewed**: 18
> **Findings**: Critical: 4 | Warnings: 4 | Suggestions: 3

---

## Audit Summary

The scope document has several critical issues that would cause runtime failures if implemented as written. The most severe is the use of `getModelFactory().create('NavbarConfig')` for an entity that doesn't exist, and modal implementations that don't follow the existing codebase patterns.

---

## Critical Findings (MUST address before implementation)

### 1. Non-existent NavbarConfig Entity Reference
- **Location:** `edit-navbar-config.js:568`, `navbar-config-field-add.js:682`
- **Evidence:** Searched for `**/NavbarConfig*` - returned "No files found". The code uses `this.getModelFactory().create('NavbarConfig', model => {...})` but there is no NavbarConfig entity defined anywhere.
- **Assumption:** The plan assumes `getModelFactory().create()` can create arbitrary model types.
- **Risk:** Runtime error - `getModelFactory().create('NavbarConfig')` will fail because there's no NavbarConfig entity metadata.
- **Remedy:** Follow the pattern from `edit-tab-group.js:94-117`:
  ```javascript
  // WRONG (current plan):
  this.getModelFactory().create('NavbarConfig', model => {...});
  
  // CORRECT (reference pattern):
  import Model from 'model';
  const model = this.model = new Model();
  model.name = 'NavbarConfig';  // Just a name, not an entity
  model.set(this.options.configData);
  model.setDefs({
      fields: {
          name: { type: 'varchar' },
          // ... field definitions
      },
  });
  ```

### 2. Template File Creation Mismatch
- **Location:** Files to CREATE section - modal templates
- **Evidence:** Searched for `**/modals/edit-tab*.tpl` - returned "No files found". The reference implementation `edit-tab-group.js:36` uses `templateContent = \`<div class="record no-side-margin">{{{record}}}</div>\`` (inline template), NOT a separate `.tpl` file.
- **Assumption:** The plan assumes modal views use separate template files.
- **Risk:** Creating `modals/edit-navbar-config.tpl` and `modals/navbar-config-field-add.tpl` is unnecessary if using inline templates, OR missing `templateContent` property will cause render failure.
- **Remedy:** Either:
  1. Use inline `templateContent` like `edit-tab-group.js` (recommended for consistency)
  2. If using `.tpl` files, ensure they exist and the `template` property path is correct

### 3. Incorrect navbar.js Line Reference for Method Insertion
- **Location:** `navbar.js` File to EDIT section
- **Evidence:** The plan says "Add as new class methods after line 1000 (after adjustAfterRender() method)". However:
  - `adjustAfterRender()` starts at line 1005
  - `adjustAfterRender()` ends at line 1045
  - `selectTab()` starts at line 1050
- **Assumption:** Line 1000 is after `adjustAfterRender()`.
- **Risk:** Methods inserted at line 1000 would be inside `afterRender()` method, breaking the code structure.
- **Remedy:** Insert new methods after line 1045 (after `adjustAfterRender()` closes, before `selectTab()`).

### 4. generateItemId() Pattern Doesn't Match Reference
- **Location:** `navbar-config-list.js:300-303` (in the plan)
- **Evidence:** `tab-list.js:54-55` shows:
  ```javascript
  generateItemId() {
      return Math.floor(Math.random() * 1000000 + 1).toString();
  }
  ```
  But the plan shows:
  ```javascript
  generateItemId() {
      return 'navbar-config-' + Date.now().toString(36) + '-' + 
             Math.floor(Math.random() * 1000000 + 1).toString();
  }
  ```
- **Assumption:** The plan claims this follows the tab-list.js pattern but it's actually a new pattern.
- **Risk:** Inconsistent ID format with existing code; potential confusion for maintainers.
- **Remedy:** Either use the exact existing pattern OR clearly document that this is an intentional deviation for uniqueness guarantees.

---

## Warnings (SHOULD address)

### 1. LESS Import Placement Unusual
- **Location:** `navbar.less` edit section
- **Evidence:** Plan says to add `@import 'navbar-config-selector.less';` "at end of file" (line 387). LESS `@import` statements typically go at the TOP of files.
- **Concern:** While LESS supports imports anywhere, placing them at the end is non-standard and could cause specificity issues or confusion.
- **Suggestion:** Add the import at the TOP of `navbar.less`, not at the end.

### 2. Missing Model Import in Modal Views
- **Location:** `edit-navbar-config.js`, `navbar-config-field-add.js`
- **Evidence:** `edit-tab-group.js:30` shows `import Model from 'model';` is required when using `new Model()` directly.
- **Concern:** If following the correct ad-hoc model pattern, `import Model from 'model';` is needed but not shown in the plan.
- **Suggestion:** Add `import Model from 'model';` to both modal view files.

### 3. navbar-config-field-add.js Extends Wrong Base Class
- **Location:** `navbar-config-field-add.js` (in the plan)
- **Evidence:** `tab-list-field-add.js:31` extends `ArrayFieldAddModalView` from `views/modals/array-field-add`. The plan's `navbar-config-field-add.js` extends `ModalView` directly.
- **Concern:** Extending `ModalView` instead of `ArrayFieldAddModalView` loses the built-in array field add functionality.
- **Suggestion:** Consider if extending `ArrayFieldAddModalView` is more appropriate, or document why direct `ModalView` extension is intentional.

### 4. tabsHelper Access Pattern Correctly Noted
- **Location:** `navbar.js` modifications
- **Evidence:** The plan correctly uses `this.tabsHelper` (instantiated at `navbar.js:429`), not `this.getHelper().tabsHelper`. This is a FIX from earlier audits and is now correct.
- **Concern:** None - this is a positive validation.
- **Observation:** The v6→v7 corrections correctly addressed this issue from previous audit rounds.

---

## Suggestions (CONSIDER addressing)

### 1. Active Config Field Type Consideration
- **Context:** `activeNavbarConfigId` is defined as `varchar`
- **Observation:** The `active-navbar-config.js` field view extends `EnumFieldView` but dynamically generates options. A `varchar` works, but consider if there should be a default empty option for when no configs exist.
- **Enhancement:** The field already handles empty options (`noConfigs` message at line 494) - good design.

### 2. Validation for activeNavbarConfigId
- **Context:** Plan mentions adding "ValidatorClassName or backend validation" but doesn't specify where.
- **Observation:** The resolution logic (`getActiveNavbarConfig()`) gracefully handles invalid IDs by falling back to default, but there's no server-side validation.
- **Enhancement:** Consider adding backend validation in Preferences entity to ensure `activeNavbarConfigId` references a valid config ID in the list.

### 3. Template Naming Consistency
- **Context:** `navbar-config-selector.tpl` uses `.edit-container` in selector but template shows `<div class="navbar-config-selector-container"></div>`
- **Observation:** The template creates a container, but the view uses selector `.edit-container` in `createView()`. This appears to be a mismatch.
- **Enhancement:** Verify the selector in `createView()` matches the actual DOM element created.

---

## Validated Items

The following aspects of the plan are well-supported by evidence:

| Item | Evidence Reference |
|------|-------------------|
| `ifEqual` Handlebars helper | `view-helper.js:386` ✓ |
| `navbarTabs` translation section | `Global.json:988-994` ✓ |
| `@screen-xs-max` Bootstrap variable | `variables.less:37` ✓ |
| `--navbar-width` CSS variable | `root-variables.less:108` ✓ |
| `tabList` field in Settings.json | Lines 245-255 ✓ |
| `tabList` field in Preferences.json | Lines 171-181 ✓ |
| Preferences listener pattern | `navbar.js:466-478` ✓ |
| `edit-tab-group.js` reference length | 140 lines ✓ |
| `tab-list-field-add.js` reference length | 94 lines ✓ |
| `userThemesDisabled` pattern | `preferences/record/edit.js:144-146` ✓ |
| `tabsHelper` instantiation | `navbar.js:429` ✓ |

---

## Evidence Sources

Files examined during audit:

| File | Purpose |
|------|---------|
| `application/Espo/Resources/metadata/entityDefs/Settings.json` | Verify tabList field structure |
| `application/Espo/Resources/metadata/entityDefs/Preferences.json` | Verify tabList field structure |
| `client/src/helpers/site/tabs.js` | Verify getTabList() method and structure |
| `client/res/templates/site/navbar.tpl` | Verify navbar-left-container structure |
| `application/Espo/Resources/i18n/en_US/Global.json` | Verify navbarTabs and messages sections |
| `client/src/views/site/navbar.js` | Verify tabsHelper, preferences listener, adjustAfterRender() |
| `application/Espo/Resources/layouts/Settings/userInterface.json` | Verify layout structure |
| `application/Espo/Resources/layouts/Preferences/detail.json` | Verify layout structure |
| `client/src/views/settings/fields/tab-list.js` | Verify generateItemId() pattern |
| `client/src/views/settings/modals/edit-tab-group.js` | Modal pattern reference |
| `client/src/views/settings/modals/tab-list-field-add.js` | Add modal pattern reference |
| `client/src/views/preferences/record/edit.js` | Verify hideField() pattern |
| `frontend/less/espo/root-variables.less` | CSS variable verification |
| `frontend/less/espo/elements/navbar.less` | Navbar styles structure |
| `frontend/less/espo/bootstrap/variables.less` | Bootstrap variables |
| `client/src/view-helper.js` | ifEqual helper verification |
| `client/src/model.js` | Model class reference |
| `client/src/views/modals/array-field-add.js` | Base modal class |

---

## Recommended Next Steps

1. **CRITICAL**: Fix the modal model creation - replace `getModelFactory().create('NavbarConfig')` with `new Model()` pattern following `edit-tab-group.js`

2. **CRITICAL**: Correct the `navbar.js` line reference - methods should be inserted after line 1045, not line 1000

3. **CRITICAL**: Decide on template approach - either use inline `templateContent` OR ensure `.tpl` files are correctly referenced

4. **HIGH**: Add `import Model from 'model';` to modal view files

5. **MEDIUM**: Consider moving the LESS import to the top of `navbar.less`

---

## Audit Corrections Table for v7 → v8

| Issue | v7 Problem | v8 Correction |
|-------|-----------|---------------|
| NavbarConfig entity | `getModelFactory().create('NavbarConfig')` - entity doesn't exist | Use `new Model()` with `model.name = 'NavbarConfig'` and `model.setDefs()` |
| Modal templates | Plans to create `.tpl` files | Use inline `templateContent` like `edit-tab-group.js` |
| navbar.js line reference | "after line 1000" - inside afterRender() | Insert after line 1045, before `selectTab()` |
| generateItemId() pattern | New format inconsistent with reference | Use exact `tab-list.js` pattern or document deviation |
| Model import | Missing `import Model from 'model'` | Add to both modal files |
| LESS import placement | At end of file | Move to top of `navbar.less` |

---

*Audit report v7 generated - ACTION REQUIRED before implementation*
