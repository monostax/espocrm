# Audit Report: Virtual Folder Tab List Type - v1

**Audit Date:** 2026-02-19  
**Scope Version:** v1.0  
**Auditor:** Review Agent  

---

## Audit Summary

**Risk Level:** Medium  
**Files Reviewed:** 15+ reference files  
**Findings:** Critical: 2 | Warnings: 5 | Suggestions: 3

The scope document is well-structured and follows established patterns in the codebase. However, there are two critical gaps around filter data resolution and integration approach with the existing custom navbar that must be resolved before implementation. The template rendering approach has an unstated assumption about how virtual folder content integrates with the navbar template structure.

---

## Readiness Assessment

**Verdict:** NEEDS REVISION

Two critical issues require resolution before implementation:
1. Filter data resolution strategy is unspecified
2. Integration approach with existing custom navbar.js is ambiguous

---

## Critical Findings (MUST address before implementation)

### 1. Filter Data Resolution Strategy Not Specified

- **Location:** `virtual-folder.js` view (to be created)
- **Evidence:** The scope states "Fetches records via `Espo.Ajax.getRequest(entityType, {where: filterData, maxItems})`" but does not specify how to resolve `filterData` from `filterName`.
- **Assumption:** Assumes preset filter names can be directly converted to where clauses
- **Risk:** Preset filters in EspoCRM are stored as filter definitions that need resolution. The codebase shows filters are typically applied via `primaryFilter` parameter in collection data, not as `where` clauses directly.
- **Remedy:** Specify the filter resolution strategy:
  - Option A: Use `collection.data.primaryFilter = filterName` and let EspoCRM handle resolution
  - Option B: Fetch filter definition from `clientDefs.{entityType}.filterList` or user preferences, then convert to where clause
  - Reference `client/src/views/record/panels/relationship.js` lines 574-581 for `setFilter()` pattern

### 2. Custom Navbar Integration Approach is Ambiguous

- **Location:** `client/custom/modules/global/src/views/site/navbar.js`
- **Evidence:** The existing custom navbar (lines 74-96) already overrides `getTabList()` with team-scoped SidenavConfig logic. The scope says to "Add virtual folder handling in `prepareTabItemDefs()` and rendering" but doesn't clarify:
  - Whether virtual folders should come from SidenavConfig.tabList (current navbar source)
  - Whether to create a separate `prepareTabItemDefs()` override
  - How virtual folder views integrate with the existing template rendering
- **Assumption:** Assumes virtual folder items will flow through existing tab processing
- **Risk:** The current navbar uses `getActiveNavbarConfig().tabList` as the source. Virtual folders stored in tabList would need to be handled by the tabsHelper. The core `prepareTabItemDefs()` method (navbar.js:1266-1356) doesn't have a hook for custom types - it only handles divider/url/group/scope.
- **Remedy:** Clarify:
  1. Virtual folders will be stored in SidenavConfig.tabList alongside groups/dividers
  2. Add `isTabVirtualFolder()` to a new helper file at `client/custom/modules/global/src/helpers/site/tabs.js` that wraps/delegates to core TabsHelper
  3. Override `prepareTabItemDefs()` in custom navbar to handle `type: 'virtualFolder'`
  4. Use a view-based rendering approach where the virtual folder view is created and its HTML injected

---

## Warnings (SHOULD address)

### 1. RecordModal Import Path

- **Location:** `virtual-folder.js` (to be created)
- **Evidence:** The scope references "Quick create uses existing `RecordModal` helper" but doesn't show the import. The pattern in `client/src/views/site/navbar/quick-create.js` line 30 shows: `import RecordModal from 'helpers/record-modal';`
- **Concern:** Missing import specification could lead to incorrect import path
- **Suggestion:** Add explicit import in scope: `import RecordModal from 'helpers/record-modal';`

### 2. Entity Type ACL Check Not Specified

- **Location:** `virtual-folder-entity.js` field (to be created)
- **Evidence:** The scope says `setupOptions(): Load scopes with tab: true and ACL read access` but doesn't specify the ACL check pattern. The existing pattern in `client/src/views/settings/fields/tab-list.js` lines 65-68 uses `this.getAcl().checkScope(scope)`.
- **Concern:** Incorrect ACL check could show entities user cannot access
- **Suggestion:** Specify: `this.getAcl().checkScope(scope, 'read')` for proper read-level ACL

### 3. Filter Field Dynamic Options Missing Trigger Pattern

- **Location:** `virtual-folder-filter.js` field (to be created)
- **Evidence:** The scope says "On change: triggers event to reload filterName options" but doesn't specify the event mechanism. Dynamic dependent fields in EspoCRM typically use `this.listenTo()` on model changes.
- **Concern:** Implementation may not correctly trigger filter reload when entity type changes
- **Suggestion:** Specify pattern:
  ```javascript
  // In edit-tab-virtual-folder.js modal
  this.listenTo(model, 'change:entityType', () => {
      const filterField = this.getView('record').getFieldView('filterName');
      if (filterField) {
          filterField.reRender();
      }
  });
  ```

### 4. Template Integration Pattern Not Clear

- **Location:** `client/res/templates/site/navbar.tpl` and navbar rendering
- **Evidence:** The core navbar template (lines 17-90) iterates `tabDefsList1` with conditional rendering for `isDivider` and `isGroup`. Virtual folder rendering would need either:
  - A new `isVirtualFolder` conditional block in a custom template override
  - View injection where virtual folder HTML is rendered separately
- **Concern:** The scope mentions "Update navbar template if needed" but the custom navbar doesn't have its own template - it uses the core template
- **Suggestion:** Create `client/custom/modules/global/res/templates/site/navbar.tpl` with virtual folder conditional block, OR use the `itemDataList` pattern (lines 188-189) to inject virtual folder views as navbar items

### 5. presetFilters Access Pattern Not Documented

- **Location:** `virtual-folder-filter.js` field
- **Evidence:** The scope says to merge "clientDefs filterList + user presetFilters for that entity". The preset filters access pattern from `client/src/views/record/search.js` lines 303, 641-644 shows:
  ```javascript
  const presetFilters = this.getPreferences().get('presetFilters') || {};
  const userFilters = presetFilters[this.scope] || [];
  ```
- **Concern:** Implementation may not correctly merge system filters with user-created filters
- **Suggestion:** Add explicit pattern for filter source resolution:
  ```javascript
  setupOptions() {
      const entityType = this.model.get('entityType');
      // System preset filters from clientDefs
      const systemFilters = this.getMetadata().get(['clientDefs', entityType, 'filterList']) || [];
      // User preset filters
      const presetFilters = this.getPreferences().get('presetFilters') || {};
      const userFilters = presetFilters[entityType] || [];
      // Merge and dedupe
  }
  ```

---

## Suggestions (CONSIDER addressing)

### 1. Consider Using Collection Factory Pattern

- **Context:** Record fetching in virtual folder view
- **Observation:** The scope uses raw `Espo.Ajax.getRequest()`. EspoCRM has a collection factory that handles pagination, sorting, and filter resolution automatically.
- **Enhancement:** Consider using:
  ```javascript
  const collection = this.getCollectionFactory().create(entityType);
  collection.maxSize = maxItems;
  collection.data.primaryFilter = filterName;
  await collection.fetch();
  ```
  This would also enable future pagination if needed.

### 2. Add Loading State Indicator

- **Context:** Virtual folder during record fetch
- **Observation:** The scope doesn't mention loading states. Users might see empty folders briefly.
- **Enhancement:** Add `isLoading` state with spinner in template:
  ```handlebars
  {{#if isLoading}}
      <li class="virtual-folder-loading"><span class="fas fa-spinner fa-spin"></span></li>
  {{/if}}
  ```

### 3. Consider Record Caching Strategy

- **Context:** Virtual folder records are fetched at navbar render time
- **Observation:** Multiple virtual folders could cause multiple API calls on each page load. If the same virtual folder appears in multiple navbar configs, records are fetched repeatedly.
- **Enhancement:** Consider adding a simple TTL-based cache or re-using cached collection data when switching navbar configs.

---

## Validated Items

The following aspects of the plan are well-supported by codebase evidence:

- **Modal pattern matches codebase** - `edit-tab-group.js`, `edit-tab-divider.js`, `edit-tab-url.js` all follow the same structure with `templateContent`, `setup()`, `detailLayout`, and `actionApply()` - scope correctly mirrors this pattern
- **Quick create pattern validated** - `RecordModal` helper with `showCreate()` method exists and is used consistently across codebase (quick-create.js line 117-121)
- **Entity icon retrieval pattern** - `name-with-icon.js` (lines 52-55) shows correct pattern: `this.getMetadata().get(['clientDefs', entityType, 'iconClass'])`
- **Translation file structure** - Existing `Global.json` and `Settings.json` files follow the expected structure for adding new labels
- **TabsHelper pattern** - Core `tabs.js` has well-established pattern for tab type detection that can be extended
- **localStorage for UI state** - Consistent with codebase patterns (e.g., navbar minimizer state stored in storage)

---

## Recommended Next Steps

1. **Resolve filter data resolution** - Specify whether to use `primaryFilter` parameter or manual where-clause construction. Recommend `primaryFilter` for consistency with EspoCRM patterns.

2. **Clarify navbar integration approach** - Decide between:
   - Template override approach (create custom navbar.tpl with virtual folder block)
   - View injection approach (add virtual folders to itemDataList pattern)
   Recommend view injection for cleaner separation.

3. **Add filter field event handling** - Specify the exact mechanism for triggering filter options reload when entityType changes.

4. **Document presetFilters merge logic** - Provide explicit code for merging system filters with user preset filters.

---

## Files Verified to Exist

| File | Status |
|------|--------|
| `client/src/views/settings/modals/tab-list-field-add.js` | ✅ Exists, pattern matches |
| `client/src/views/settings/modals/edit-tab-group.js` | ✅ Exists, pattern matches |
| `client/src/views/settings/modals/edit-tab-divider.js` | ✅ Exists, pattern matches |
| `client/src/views/settings/modals/edit-tab-url.js` | ✅ Exists, pattern matches |
| `client/src/views/settings/fields/tab-list.js` | ✅ Exists, pattern matches |
| `client/src/views/site/navbar.js` | ✅ Exists, complex rendering logic |
| `client/src/helpers/site/tabs.js` | ✅ Exists, tab type detection methods |
| `client/src/helpers/record-modal.js` | ✅ Exists, showCreate() available |
| `client/src/views/site/navbar/quick-create.js` | ✅ Exists, RecordModal pattern |
| `client/custom/modules/global/src/views/site/navbar.js` | ✅ Exists, extends core navbar |
| `client/res/templates/site/navbar.tpl` | ✅ Exists, tab rendering template |
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json` | ✅ Exists |
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json` | ✅ Exists |

---

*Audit complete. Scope requires revision of critical items before implementation can proceed safely.*
