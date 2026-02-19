# Audit Report: Virtual Folder Tab List Type v4

**Audit Date:** 2026-02-19  
**Scope Version:** v4.0  
**Auditor:** Review Agent  
**Risk Level:** Low  

---

## Audit Summary

**Risk Level:** Low  
**Files Reviewed:** 20+ reference files  
**Findings:** Critical: 0 | Warnings: 1 | Suggestions: 2  

The v4 scope document successfully addresses all critical and warning findings from the v3 audit. The config data flow is now explicit, the editGroup() implementation is provided, the template renders as `<li>` to maintain navbar structure, SidenavConfig compatibility is verified, and the default virtual folder object is specified. The scope is well-designed and ready for implementation.

---

## Readiness Assessment

**Verdict:** READY TO IMPLEMENT

The design is sound. All v3 audit findings have been addressed. The remaining warning and suggestions are minor and can be addressed during implementation. The coding agent should be aware of the implementation-time watchpoints listed below.

---

## Circular Rework Detection

No circular rework detected. This is a v4 scope following v1, v2, and v3 audits. The changes represent forward progress:

| Version | Key Change | Status |
|---------|-----------|--------|
| v1 → v2 | Filter resolution: raw Ajax → collection factory | ✅ Stable |
| v2 → v3 | View injection: data() override → afterRender() DOM injection | ✅ Stable |
| v3 → v4 | Config data flow: missing → explicit `config: tab` | ✅ New fix |

All decisions from prior versions remain consistent. The v4 fixes directly address v3 audit findings without reverting any established decisions.

---

## Critical Findings (MUST address before implementation)

**None.** All critical findings from the v3 audit have been resolved:

| v3 Finding | v4 Resolution |
|------------|---------------|
| Config data flow incomplete | ✅ `prepareVirtualFolderDefs()` now passes `config: tab` |
| Missing editGroup() details | ✅ Explicit implementation with virtualFolder routing |
| Template structure conflict | ✅ Now renders as `<li class="virtual-folder">` |
| SidenavConfig integration unverified | ✅ Verified as `jsonArray` type |
| Default object not specified | ✅ Explicit default with all fields |
| ViewAll action missing | ✅ `actionViewAll()` implementation provided |
| Error handling missing | ✅ `hasError` state with template support |

---

## Warnings (SHOULD address)

### 1. Custom TabsHelper Instantiation Timing

- **Location:** `client/custom/modules/global/src/views/site/navbar.js` (EDIT section), `setup()` method
- **Evidence:** The scope proposes replacing `this.tabsHelper` after `super.setup()`:
  ```javascript
  setup() {
      super.setup();
      this.tabsHelper = new TabsHelper(...); // Replaces core TabsHelper
  }
  ```
  The core navbar instantiates `this.tabsHelper` at line 429-436 within its `setup()` method.
- **Concern:** The custom navbar's existing `setup()` (line 174-186) already calls `super.setup()` which creates the core TabsHelper. Replacing it with the custom TabsHelper after `super.setup()` is correct, but the scope should note that the core TabsHelper is instantiated first and then replaced.
- **Suggestion:** This is the correct approach. The implementation should proceed as specified. Just ensure the custom TabsHelper import is added at the top of the file.

---

## Suggestions (CONSIDER addressing)

### 1. Consider Adding `getRouter()` to Virtual Folder View

- **Context:** The `actionViewAll()` method uses `this.getRouter().navigate()`.
- **Observation:** `getRouter()` is a standard method available in EspoCRM views (defined in `client/src/view.js`).
- **Enhancement:** No change needed - this is already correct. Just confirming the method is available.

### 2. Consider Adding ARIA Labels for Accessibility

- **Context:** The virtual folder template includes interactive elements (toggle, dropdown menu, action buttons).
- **Observation:** The template could benefit from ARIA attributes for better screen reader support.
- **Enhancement:** Consider adding:
  - `aria-expanded` attribute on toggle button
  - `aria-label` on action buttons
  - `role="menu"` on dropdown menu
  
  This is optional and can be added in a future iteration.

---

## Validated Items

The following aspects of the plan are well-supported by codebase evidence:

1. **Config data flow** - ✅ `prepareVirtualFolderDefs()` now explicitly returns `config: tab` with the full tab configuration

2. **afterRender() DOM injection strategy** - ✅ Confirmed pattern: custom navbar already has `afterRender()` that injects styles and modifies DOM

3. **Collection factory + primaryFilter pattern** - ✅ Confirmed in `client/src/views/record/panels/relationship.js:574-581`

4. **getCollectionFactory() method availability** - ✅ Confirmed in `client/src/view.js:217` and used in 33+ locations

5. **acl.checkScope() for scope-level ACL** - ✅ Confirmed in `client/src/views/settings/fields/tab-list.js:65-68`

6. **RecordModal.showCreate() with after:save listener** - ✅ Confirmed in `client/src/helpers/record-modal.js:310-361`

7. **presetFilters access from Preferences** - ✅ Confirmed in `client/src/views/record/search.js:303`

8. **Icon retrieval from metadata** - ✅ Confirmed in `client/custom/modules/global/src/views/activities/fields/name-with-icon.js:52-55`

9. **Core TabsHelper extension pattern** - ✅ Confirmed in `client/src/helpers/site/tabs.js` with all required methods

10. **Modal pattern for tab editing** - ✅ Confirmed in `edit-tab-group.js`, `edit-tab-divider.js`, `edit-tab-url.js`

11. **CSS injection pattern** - ✅ Confirmed in custom navbar's `injectMobileDrawerStyles()` and `injectNavbarConfigSelectorStyles()`

12. **SidenavConfig tabList field type** - ✅ Confirmed as `jsonArray` in `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json:23-26`

13. **Navbar template structure** - ✅ Confirmed `<ul class="nav navbar-nav tabs">` with `<li>` children and `{{{html}}}` injection point at line 48

14. **editGroup() view routing pattern** - ✅ Confirmed at `client/src/views/settings/fields/tab-list.js:317-320`

15. **Tab list field add modal pattern** - ✅ Confirmed at `client/src/views/settings/modals/tab-list-field-add.js`

16. **Global.json translation file** - ✅ Exists and can be extended

17. **helpers/site/ directory** - ✅ Exists (empty, ready for new tabs.js file)

18. **Core navbar tabsHelper instantiation** - ✅ Confirmed at `client/src/views/site/navbar.js:429-436`

---

## Implementation-Time Watchpoints

The coding agent should be aware of these during implementation:

1. **Import order matters** - The custom TabsHelper import (`import TabsHelper from 'global:helpers/site/tabs';`) must be added before it's used in `setup()`

2. **Template file path** - Ensure the template is created at `client/custom/modules/global/res/templates/site/navbar/virtual-folder.tpl` (note: `res/templates/` not `src/templates/`)

3. **CSS file path** - Ensure CSS is created at `client/custom/modules/global/css/virtual-folder.css`

4. **View naming convention** - The view should be created at `client/custom/modules/global/src/views/site/navbar/virtual-folder.js` and referenced as `global:views/site/navbar/virtual-folder`

5. **Modal view naming** - The edit modal should be at `client/custom/modules/global/src/views/settings/modals/edit-tab-virtual-folder.js` and referenced as `global:views/settings/modals/edit-tab-virtual-folder`

6. **Translation keys** - Add all new translation keys to `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json` under `labels` and `fields` sections

7. **Settings.json creation** - If `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json` doesn't exist, create it with the Virtual Folder label

---

## Recommended Next Steps

1. **Proceed with implementation** - The scope is ready for implementation following the documented phase order.

2. **During Phase 2** - When creating the Settings UI components, follow the exact patterns from the core tab-list.js and edit-tab-*.js modals.

3. **During Phase 3** - When editing the navbar.js, ensure all imports are added at the top and the method overrides follow the documented order.

4. **During Phase 4** - Test all documented scenarios including ACL restrictions, error handling, and mobile responsiveness.

---

## File References Verified

| File | Exists | Pattern Validated |
|------|--------|-------------------|
| `client/custom/modules/global/src/views/site/navbar.js` | ✅ | Custom navbar extends core, has setup() override |
| `client/src/helpers/site/tabs.js` | ✅ | Tab detection patterns, checkTabAccess() |
| `client/src/views/record/panels/relationship.js` | ✅ | primaryFilter pattern |
| `client/src/helpers/record-modal.js` | ✅ | showCreate() method with after:save |
| `client/src/views/settings/modals/edit-tab-group.js` | ✅ | Modal pattern |
| `client/src/views/settings/modals/edit-tab-url.js` | ✅ | Modal pattern |
| `client/src/views/settings/modals/edit-tab-divider.js` | ✅ | Modal pattern |
| `client/src/views/settings/fields/tab-list.js` | ✅ | editGroup() routing, getGroupItemHtml() |
| `client/src/views/settings/modals/tab-list-field-add.js` | ✅ | Add modal extension pattern |
| `client/src/views/site/navbar.js` | ✅ | setupTabDefsList(), prepareTabItemDefs(), setup() |
| `client/res/templates/site/navbar.tpl` | ✅ | Template structure with `<li>` elements |
| `client/src/views/record/search.js` | ✅ | presetFilters access |
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json` | ✅ | Translation file exists |
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json` | ✅ | jsonArray type for tabList |
| `client/custom/modules/global/css/` | ✅ | CSS directory exists |
| `client/custom/modules/global/res/templates/` | ✅ | Template directory exists |
| `client/custom/modules/global/src/helpers/site/` | ✅ | Directory exists (empty, ready for new file) |
| `client/src/view.js` | ✅ | getCollectionFactory(), getRouter() methods |
| `client/custom/modules/global/src/views/activities/fields/name-with-icon.js` | ✅ | Icon retrieval pattern |

---

*Audit complete. The v4 scope is ready for implementation. All design-level concerns have been addressed. Remaining items are implementation details that will be caught by linting, type-checking, and testing.*
