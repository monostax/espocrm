# Audit Report: Virtual Folder Tab List Type v2

**Audit Date:** 2026-02-19  
**Scope Version:** v2.0  
**Auditor:** Review Agent  
**Risk Level:** Medium  

---

## Audit Summary

**Risk Level:** Medium  
**Files Reviewed:** 15 reference files  
**Findings:** Critical: 1 | Warnings: 4 | Suggestions: 3  

The v2 scope document demonstrates solid understanding of EspoCRM patterns and addresses prior audit concerns. However, one critical design issue remains around the view-to-template HTML injection strategy, and several warnings require attention for robust implementation.

---

## Readiness Assessment

**Verdict:** NEEDS REVISION

One critical design-level issue must be resolved before implementation: the view HTML injection strategy in `data()` is architecturally problematic. The remaining warnings are addressable during implementation but should be understood upfront.

---

## Circular Rework Detection

No circular rework detected. This is a v2 scope following a v1 audit. The changes from v1 to v2 (filter resolution strategy, navbar integration approach) represent forward progress, not flip-flopping.

---

## Critical Findings (MUST address before implementation)

### 1. View HTML Injection Strategy Is Architecturally Flawed

- **Location:** `client/custom/modules/global/src/views/site/navbar.js` (EDIT section)
- **Evidence:** The scope proposes:
  ```javascript
  data() {
      const baseData = super.data();
      baseData.tabDefsList1 = baseData.tabDefsList1.map(defs => {
          if (defs.isVirtualFolder) {
              const viewKey = 'virtualFolder-' + defs.virtualFolderId;
              const view = this.getView(viewKey);
              defs.html = view ? view.getHtml() : '';
          }
          return defs;
      });
      return baseData;
  }
  ```
- **Assumption:** Assumes `view.getHtml()` can be called synchronously in `data()` and returns rendered HTML
- **Risk:** 
  1. Views in EspoCRM render asynchronously - `getHtml()` may return empty string if view not yet rendered
  2. `data()` is called during render, but `createView()` is asynchronous - race condition
  3. The `setupTabDefsList()` creates views, but `data()` may be called before views finish rendering
  4. Existing navbar template at `navbar.tpl:48` uses `{{#if html}}{{{html}}}{{/if}}` inside the `<a>` tag, but the proposed virtual-folder.tpl wraps the entire folder in a `<li>` with full HTML structure

- **Remedy:** 
  **Option A (Recommended):** Don't inject HTML in `data()`. Instead:
  1. Let `prepareVirtualFolderDefs()` return minimal marker defs like `{isVirtualFolder: true, virtualFolderId: id}`
  2. In `afterRender()`, use DOM manipulation to insert rendered virtual folder views:
     ```javascript
     afterRender() {
         super.afterRender();
         this.tabDefsList.forEach((defs) => {
             if (defs.isVirtualFolder) {
                 const viewKey = 'virtualFolder-' + defs.virtualFolderId;
                 const view = this.getView(viewKey);
                 if (view && view.element) {
                     const placeholder = this.element.querySelector(`[data-virtual-folder-id="${defs.virtualFolderId}"]`);
                     if (placeholder) {
                         placeholder.replaceWith(view.element);
                     }
                 }
             }
         });
     }
     ```
  
  **Option B:** Make virtual folders render as placeholder `<li>` in template, then use `setElement()` to attach the view to that placeholder element after parent renders.

---

## Warnings (SHOULD address)

### 1. ACL Method Incorrect in Custom TabsHelper

- **Location:** `client/custom/modules/global/src/helpers/site/tabs.js` (CREATE)
- **Evidence:** The scope proposes:
  ```javascript
  checkTabAccess(item) {
      if (this.isTabVirtualFolder(item)) {
          return this.acl.check(item.entityType, 'read');
      }
      return super.checkTabAccess(item);
  }
  ```
- **Concern:** `acl.check()` is for checking record-level access on a model. For scope-level access, use `acl.checkScope()`. Looking at core `TabsHelper.checkTabAccess()` at lines 174-215, it uses `this.acl.check(item)` for scopes where `defs.acl` is true.
- **Suggestion:** Change to:
  ```javascript
  checkTabAccess(item) {
      if (this.isTabVirtualFolder(item)) {
          return this.acl.checkScope(item.entityType, 'read');
      }
      return super.checkTabAccess(item);
  }
  ```

### 2. setupTabDefsList Virtual Folder Filtering Not Handled

- **Location:** `client/custom/modules/global/src/views/site/navbar.js` (EDIT)
- **Evidence:** Core `setupTabDefsList()` (lines 1073-1241) has complex filtering logic for different tab types. The custom navbar's `getTabList()` returns virtual folders, but `setupTabDefsList()` needs to handle them during the filtering phase, not just in `prepareTabItemDefs()`.
- **Concern:** If virtual folders aren't handled in the filtering loop (lines 1079-1146), they may be incorrectly filtered out or treated as groups.
- **Suggestion:** Override `setupTabDefsList()` to add virtual folder handling in the filtering phase:
  ```javascript
  setupTabDefsList() {
      // ... existing filter logic needs to include:
      if (this.tabsHelper.isTabVirtualFolder(item)) {
          return this.tabsHelper.checkTabAccess(item);
      }
  }
  ```
  Or ensure `prepareTabItemDefs()` is the ONLY place handling virtual folders and the filtering logic passes them through.

### 3. Missing Directory Structure for Helpers

- **Location:** `client/custom/modules/global/src/helpers/site/tabs.js`
- **Evidence:** `glob` search shows no existing files in `client/custom/modules/global/src/helpers/`
- **Concern:** The `helpers/` directory doesn't exist. This isn't blocking but implementation must create the directory structure.
- **Suggestion:** Note in implementation order that directories must be created: `mkdir -p client/custom/modules/global/src/helpers/site`

### 4. Template Structure Mismatch with Existing Navbar Pattern

- **Location:** `client/custom/modules/global/res/templates/site/navbar/virtual-folder.tpl`
- **Evidence:** The proposed template wraps content in `<li class="tab tab-virtual-folder">` but the navbar template (`navbar.tpl:17-91`) expects tab items to be `<li>` elements with specific structure. The `{{#if html}}{{{html}}}{{/if}}` at line 48 is inside the `<a>` tag, not at `<li>` level.
- **Concern:** The virtual folder needs to be a self-contained `<li>` that replaces the normal tab `<li>`, not HTML injected inside an `<a>` tag.
- **Suggestion:** Either:
  1. Use a completely separate rendering path (afterRender DOM injection) as suggested in Critical Finding #1
  2. Or modify the navbar template to support a `fullHtml` property that replaces the entire `<li>` content

---

## Suggestions (CONSIDER addressing)

### 1. Add Record Refresh After Quick Create

- **Context:** When a user quick-creates a record from the virtual folder
- **Observation:** The scope doesn't specify whether the virtual folder should auto-refresh to show the newly created record
- **Enhancement:** After `actionQuickCreate()`, listen for the modal's `afterSave` event and call `fetchRecords()`:
  ```javascript
  async actionQuickCreate() {
      const helper = new RecordModal();
      const modal = await helper.showCreate(this, { entityType: this.entityType });
      this.listenToOnce(modal, 'after:save', () => this.fetchRecords());
  }
  ```

### 2. Handle Filter Field Re-render Race Condition

- **Context:** When entityType changes, filterName field needs to reload options
- **Observation:** The scope uses `listenTo(model, 'change:entityType', ...)` to trigger filter field re-render
- **Enhancement:** The filter field's `setupOptions()` runs during field setup. Consider using `this.model.on('change:entityType', () => this.controlVisibility())` pattern combined with `reRender()` for more reliable updates.

### 3. Add Accessible Collapse/Expand State

- **Context:** Virtual folder collapse/expand uses localStorage and visual state
- **Observation:** No ARIA attributes specified for accessibility
- **Enhancement:** Add to template:
  ```handlebars
  <div class="virtual-folder-header" 
       role="button" 
       aria-expanded="{{#if isCollapsed}}false{{else}}true{{/if}}"
       aria-controls="vf-items-{{id}}">
  ```
  And add `id="vf-items-{{id}}"` to the items `<ul>`.

---

## Validated Items

The following aspects of the plan are well-supported by codebase evidence:

1. **Filter resolution with `collection.data.primaryFilter`** - Confirmed in `client/src/views/record/panels/relationship.js:574-581`
2. **RecordModal.showCreate() signature** - Confirmed in `client/src/helpers/record-modal.js:310-361`
3. **presetFilters access pattern** - Confirmed in `client/src/views/record/search.js:303` and `641-644`
4. **Core TabsHelper patterns** - `isTabDivider()`, `isTabUrl()`, `checkTabAccess()` confirmed in `client/src/helpers/site/tabs.js`
5. **Modal pattern for tab editing** - Confirmed in `client/src/views/settings/modals/edit-tab-group.js` and `edit-tab-url.js`
6. **Tab list field extension pattern** - Confirmed in `client/src/views/settings/fields/tab-list.js`
7. **Entity icon retrieval pattern** - Confirmed in `client/custom/modules/global/src/views/activities/fields/name-with-icon.js:52-55`
8. **Core navbar prepareTabItemDefs signature** - Confirmed in `client/src/views/site/navbar.js:1266-1356`
9. **Core navbar setupTabDefsList structure** - Confirmed in `client/src/views/site/navbar.js:1073-1241`
10. **Custom navbar exists and extends core** - Confirmed at `client/custom/modules/global/src/views/site/navbar.js`
11. **Tab-list-field-add modal extension pattern** - Confirmed in `client/src/views/settings/modals/tab-list-field-add.js`
12. **Existing CSS injection pattern** - Confirmed in custom navbar's `injectMobileDrawerStyles()` and `injectNavbarConfigSelectorStyles()`

---

## Recommended Next Steps

1. **CRITICAL:** Revise the view HTML injection strategy. Move from `data()` override to `afterRender()` DOM injection, or use placeholder elements with post-render view attachment.

2. **HIGH:** Fix the `acl.check()` → `acl.checkScope()` method call in custom TabsHelper.

3. **HIGH:** Ensure virtual folders are properly handled in `setupTabDefsList()` filtering logic.

4. **MEDIUM:** Create the `helpers/site/` directory structure during implementation.

5. **OPTIONAL:** Implement the suggestions for better UX (auto-refresh after create, accessibility).

---

## File References Verified

| File | Exists | Pattern Validated |
|------|--------|-------------------|
| `client/custom/modules/global/src/views/site/navbar.js` | ✅ | Custom navbar extends core |
| `client/src/views/record/panels/relationship.js` | ✅ | primaryFilter pattern at lines 574-581 |
| `client/src/helpers/site/tabs.js` | ✅ | Tab detection patterns |
| `client/src/helpers/record-modal.js` | ✅ | showCreate() method |
| `client/src/views/settings/modals/edit-tab-group.js` | ✅ | Modal pattern |
| `client/src/views/settings/fields/tab-list.js` | ✅ | Field extension pattern |
| `client/src/views/settings/modals/tab-list-field-add.js` | ✅ | Add modal extension |
| `client/src/views/site/navbar.js` | ✅ | prepareTabItemDefs, setupTabDefsList |
| `client/res/templates/site/navbar.tpl` | ✅ | Template structure with html property |
| `client/custom/modules/global/src/views/activities/fields/name-with-icon.js` | ✅ | Icon retrieval pattern |
| `client/src/views/record/search.js` | ✅ | presetFilters access pattern |
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json` | ✅ | Translation file exists |

---

*Audit complete. The scope is fundamentally sound but requires revision of the view injection strategy before implementation can proceed confidently.*
