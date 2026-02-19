# Scope comparison: virtual-folder-tablist-type v2 to v3 without tests

**Session ID:** ses_38a7e33c1ffeQSdLQXX2FKWbSY
**Created:** 2/19/2026, 7:46:06 AM
**Updated:** 2/19/2026, 7:53:24 AM

---

## User

Given /home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v2.audit.md scope /home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v3.md

important decision: we dont need tests

---

## Assistant (Scope · glm-5-free · 15.0s)

_Thinking:_

The user wants me to map the scope for a task defined in `.scopes/virtual-folder-tablist-type.v3.md`. They've also referenced an audit file at `.scopes/virtual-folder-tablist-type.v2.audit.md` and mentioned an important decision: tests are not needed.

Let me start by reading both scope files to understand what needs to be implemented.

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v3.md"
}
```

**Error:**
```
Error: File not found: /home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v3.md
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v2.audit.md"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v2.audit.md</path>
<type>file</type>
<content>1: # Audit Report: Virtual Folder Tab List Type v2
2: 
3: **Audit Date:** 2026-02-19  
4: **Scope Version:** v2.0  
5: **Auditor:** Review Agent  
6: **Risk Level:** Medium  
7: 
8: ---
9: 
10: ## Audit Summary
11: 
12: **Risk Level:** Medium  
13: **Files Reviewed:** 15 reference files  
14: **Findings:** Critical: 1 | Warnings: 4 | Suggestions: 3  
15: 
16: The v2 scope document demonstrates solid understanding of EspoCRM patterns and addresses prior audit concerns. However, one critical design issue remains around the view-to-template HTML injection strategy, and several warnings require attention for robust implementation.
17: 
18: ---
19: 
20: ## Readiness Assessment
21: 
22: **Verdict:** NEEDS REVISION
23: 
24: One critical design-level issue must be resolved before implementation: the view HTML injection strategy in `data()` is architecturally problematic. The remaining warnings are addressable during implementation but should be understood upfront.
25: 
26: ---
27: 
28: ## Circular Rework Detection
29: 
30: No circular rework detected. This is a v2 scope following a v1 audit. The changes from v1 to v2 (filter resolution strategy, navbar integration approach) represent forward progress, not flip-flopping.
31: 
32: ---
33: 
34: ## Critical Findings (MUST address before implementation)
35: 
36: ### 1. View HTML Injection Strategy Is Architecturally Flawed
37: 
38: - **Location:** `client/custom/modules/global/src/views/site/navbar.js` (EDIT section)
39: - **Evidence:** The scope proposes:
40:   ```javascript
41:   data() {
42:       const baseData = super.data();
43:       baseData.tabDefsList1 = baseData.tabDefsList1.map(defs => {
44:           if (defs.isVirtualFolder) {
45:               const viewKey = 'virtualFolder-' + defs.virtualFolderId;
46:               const view = this.getView(viewKey);
47:               defs.html = view ? view.getHtml() : '';
48:           }
49:           return defs;
50:       });
51:       return baseData;
52:   }
53:   ```
54: - **Assumption:** Assumes `view.getHtml()` can be called synchronously in `data()` and returns rendered HTML
55: - **Risk:** 
56:   1. Views in EspoCRM render asynchronously - `getHtml()` may return empty string if view not yet rendered
57:   2. `data()` is called during render, but `createView()` is asynchronous - race condition
58:   3. The `setupTabDefsList()` creates views, but `data()` may be called before views finish rendering
59:   4. Existing navbar template at `navbar.tpl:48` uses `{{#if html}}{{{html}}}{{/if}}` inside the `<a>` tag, but the proposed virtual-folder.tpl wraps the entire folder in a `<li>` with full HTML structure
60: 
61: - **Remedy:** 
62:   **Option A (Recommended):** Don't inject HTML in `data()`. Instead:
63:   1. Let `prepareVirtualFolderDefs()` return minimal marker defs like `{isVirtualFolder: true, virtualFolderId: id}`
64:   2. In `afterRender()`, use DOM manipulation to insert rendered virtual folder views:
65:      ```javascript
66:      afterRender() {
67:          super.afterRender();
68:          this.tabDefsList.forEach((defs) => {
69:              if (defs.isVirtualFolder) {
70:                  const viewKey = 'virtualFolder-' + defs.virtualFolderId;
71:                  const view = this.getView(viewKey);
72:                  if (view && view.element) {
73:                      const placeholder = this.element.querySelector(`[data-virtual-folder-id="${defs.virtualFolderId}"]`);
74:                      if (placeholder) {
75:                          placeholder.replaceWith(view.element);
76:                      }
77:                  }
78:              }
79:          });
80:      }
81:      ```
82:   
83:   **Option B:** Make virtual folders render as placeholder `<li>` in template, then use `setElement()` to attach the view to that placeholder element after parent renders.
84: 
85: ---
86: 
87: ## Warnings (SHOULD address)
88: 
89: ### 1. ACL Method Incorrect in Custom TabsHelper
90: 
91: - **Location:** `client/custom/modules/global/src/helpers/site/tabs.js` (CREATE)
92: - **Evidence:** The scope proposes:
93:   ```javascript
94:   checkTabAccess(item) {
95:       if (this.isTabVirtualFolder(item)) {
96:           return this.acl.check(item.entityType, 'read');
97:       }
98:       return super.checkTabAccess(item);
99:   }
100:   ```
101: - **Concern:** `acl.check()` is for checking record-level access on a model. For scope-level access, use `acl.checkScope()`. Looking at core `TabsHelper.checkTabAccess()` at lines 174-215, it uses `this.acl.check(item)` for scopes where `defs.acl` is true.
102: - **Suggestion:** Change to:
103:   ```javascript
104:   checkTabAccess(item) {
105:       if (this.isTabVirtualFolder(item)) {
106:           return this.acl.checkScope(item.entityType, 'read');
107:       }
108:       return super.checkTabAccess(item);
109:   }
110:   ```
111: 
112: ### 2. setupTabDefsList Virtual Folder Filtering Not Handled
113: 
114: - **Location:** `client/custom/modules/global/src/views/site/navbar.js` (EDIT)
115: - **Evidence:** Core `setupTabDefsList()` (lines 1073-1241) has complex filtering logic for different tab types. The custom navbar's `getTabList()` returns virtual folders, but `setupTabDefsList()` needs to handle them during the filtering phase, not just in `prepareTabItemDefs()`.
116: - **Concern:** If virtual folders aren't handled in the filtering loop (lines 1079-1146), they may be incorrectly filtered out or treated as groups.
117: - **Suggestion:** Override `setupTabDefsList()` to add virtual folder handling in the filtering phase:
118:   ```javascript
119:   setupTabDefsList() {
120:       // ... existing filter logic needs to include:
121:       if (this.tabsHelper.isTabVirtualFolder(item)) {
122:           return this.tabsHelper.checkTabAccess(item);
123:       }
124:   }
125:   ```
126:   Or ensure `prepareTabItemDefs()` is the ONLY place handling virtual folders and the filtering logic passes them through.
127: 
128: ### 3. Missing Directory Structure for Helpers
129: 
130: - **Location:** `client/custom/modules/global/src/helpers/site/tabs.js`
131: - **Evidence:** `glob` search shows no existing files in `client/custom/modules/global/src/helpers/`
132: - **Concern:** The `helpers/` directory doesn't exist. This isn't blocking but implementation must create the directory structure.
133: - **Suggestion:** Note in implementation order that directories must be created: `mkdir -p client/custom/modules/global/src/helpers/site`
134: 
135: ### 4. Template Structure Mismatch with Existing Navbar Pattern
136: 
137: - **Location:** `client/custom/modules/global/res/templates/site/navbar/virtual-folder.tpl`
138: - **Evidence:** The proposed template wraps content in `<li class="tab tab-virtual-folder">` but the navbar template (`navbar.tpl:17-91`) expects tab items to be `<li>` elements with specific structure. The `{{#if html}}{{{html}}}{{/if}}` at line 48 is inside the `<a>` tag, not at `<li>` level.
139: - **Concern:** The virtual folder needs to be a self-contained `<li>` that replaces the normal tab `<li>`, not HTML injected inside an `<a>` tag.
140: - **Suggestion:** Either:
141:   1. Use a completely separate rendering path (afterRender DOM injection) as suggested in Critical Finding #1
142:   2. Or modify the navbar template to support a `fullHtml` property that replaces the entire `<li>` content
143: 
144: ---
145: 
146: ## Suggestions (CONSIDER addressing)
147: 
148: ### 1. Add Record Refresh After Quick Create
149: 
150: - **Context:** When a user quick-creates a record from the virtual folder
151: - **Observation:** The scope doesn't specify whether the virtual folder should auto-refresh to show the newly created record
152: - **Enhancement:** After `actionQuickCreate()`, listen for the modal's `afterSave` event and call `fetchRecords()`:
153:   ```javascript
154:   async actionQuickCreate() {
155:       const helper = new RecordModal();
156:       const modal = await helper.showCreate(this, { entityType: this.entityType });
157:       this.listenToOnce(modal, 'after:save', () => this.fetchRecords());
158:   }
159:   ```
160: 
161: ### 2. Handle Filter Field Re-render Race Condition
162: 
163: - **Context:** When entityType changes, filterName field needs to reload options
164: - **Observation:** The scope uses `listenTo(model, 'change:entityType', ...)` to trigger filter field re-render
165: - **Enhancement:** The filter field's `setupOptions()` runs during field setup. Consider using `this.model.on('change:entityType', () => this.controlVisibility())` pattern combined with `reRender()` for more reliable updates.
166: 
167: ### 3. Add Accessible Collapse/Expand State
168: 
169: - **Context:** Virtual folder collapse/expand uses localStorage and visual state
170: - **Observation:** No ARIA attributes specified for accessibility
171: - **Enhancement:** Add to template:
172:   ```handlebars
173:   <div class="virtual-folder-header" 
174:        role="button" 
175:        aria-expanded="{{#if isCollapsed}}false{{else}}true{{/if}}"
176:        aria-controls="vf-items-{{id}}">
177:   ```
178:   And add `id="vf-items-{{id}}"` to the items `<ul>`.
179: 
180: ---
181: 
182: ## Validated Items
183: 
184: The following aspects of the plan are well-supported by codebase evidence:
185: 
186: 1. **Filter resolution with `collection.data.primaryFilter`** - Confirmed in `client/src/views/record/panels/relationship.js:574-581`
187: 2. **RecordModal.showCreate() signature** - Confirmed in `client/src/helpers/record-modal.js:310-361`
188: 3. **presetFilters access pattern** - Confirmed in `client/src/views/record/search.js:303` and `641-644`
189: 4. **Core TabsHelper patterns** - `isTabDivider()`, `isTabUrl()`, `checkTabAccess()` confirmed in `client/src/helpers/site/tabs.js`
190: 5. **Modal pattern for tab editing** - Confirmed in `client/src/views/settings/modals/edit-tab-group.js` and `edit-tab-url.js`
191: 6. **Tab list field extension pattern** - Confirmed in `client/src/views/settings/fields/tab-list.js`
192: 7. **Entity icon retrieval pattern** - Confirmed in `client/custom/modules/global/src/views/activities/fields/name-with-icon.js:52-55`
193: 8. **Core navbar prepareTabItemDefs signature** - Confirmed in `client/src/views/site/navbar.js:1266-1356`
194: 9. **Core navbar setupTabDefsList structure** - Confirmed in `client/src/views/site/navbar.js:1073-1241`
195: 10. **Custom navbar exists and extends core** - Confirmed at `client/custom/modules/global/src/views/site/navbar.js`
196: 11. **Tab-list-field-add modal extension pattern** - Confirmed in `client/src/views/settings/modals/tab-list-field-add.js`
197: 12. **Existing CSS injection pattern** - Confirmed in custom navbar's `injectMobileDrawerStyles()` and `injectNavbarConfigSelectorStyles()`
198: 
199: ---
200: 
201: ## Recommended Next Steps
202: 
203: 1. **CRITICAL:** Revise the view HTML injection strategy. Move from `data()` override to `afterRender()` DOM injection, or use placeholder elements with post-render view attachment.
204: 
205: 2. **HIGH:** Fix the `acl.check()` → `acl.checkScope()` method call in custom TabsHelper.
206: 
207: 3. **HIGH:** Ensure virtual folders are properly handled in `setupTabDefsList()` filtering logic.
208: 
209: 4. **MEDIUM:** Create the `helpers/site/` directory structure during implementation.
210: 
211: 5. **OPTIONAL:** Implement the suggestions for better UX (auto-refresh after create, accessibility).
212: 
213: ---
214: 
215: ## File References Verified
216: 
217: | File | Exists | Pattern Validated |
218: |------|--------|-------------------|
219: | `client/custom/modules/global/src/views/site/navbar.js` | ✅ | Custom navbar extends core |
220: | `client/src/views/record/panels/relationship.js` | ✅ | primaryFilter pattern at lines 574-581 |
221: | `client/src/helpers/site/tabs.js` | ✅ | Tab detection patterns |
222: | `client/src/helpers/record-modal.js` | ✅ | showCreate() method |
223: | `client/src/views/settings/modals/edit-tab-group.js` | ✅ | Modal pattern |
224: | `client/src/views/settings/fields/tab-list.js` | ✅ | Field extension pattern |
225: | `client/src/views/settings/modals/tab-list-field-add.js` | ✅ | Add modal extension |
226: | `client/src/views/site/navbar.js` | ✅ | prepareTabItemDefs, setupTabDefsList |
227: | `client/res/templates/site/navbar.tpl` | ✅ | Template structure with html property |
228: | `client/custom/modules/global/src/views/activities/fields/name-with-icon.js` | ✅ | Icon retrieval pattern |
229: | `client/src/views/record/search.js` | ✅ | presetFilters access pattern |
230: | `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json` | ✅ | Translation file exists |
231: 
232: ---
233: 
234: *Audit complete. The scope is fundamentally sound but requires revision of the view injection strategy before implementation can proceed confidently.*
235: 

(End of file - total 235 lines)
</content>
```

---

## Assistant (Scope · glm-5-free · 16.2s)

_Thinking:_

The v3.md file doesn't exist yet. Let me search for what scope files exist to find the actual scope document I need to read.

Let me search for the scope file that exists:

**Tool: glob**

**Input:**
```json
{
  "pattern": ".scopes/*.md",
  "path": "/home/antl3x/repos/monostax/mono/components/crm/source"
}
```

**Output:**
```
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v2.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v2.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v1.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v1.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.walkthrough.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-ordering-and-disable.walkthrough.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-ordering-and-disable.v3.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-ordering-and-disable.v2.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-ordering-and-disable.v2.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-ordering-and-disable.v1.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-ordering-and-disable.v1.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v4.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v4.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v3.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v3.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v2.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v2.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v1.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar-scoped-by-team.v1.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v10.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v9.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v9.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v8.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v8.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v7.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v7.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v6.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v6.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v5.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v5.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v4.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v4.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v3.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v3.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v2.audit.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v2.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v1.md
/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/multi-sidenav-sidebar.v1.audit.md
```

---

## Assistant (Scope · glm-5-free · 102.5s)

_Thinking:_

I see there's a `virtual-folder-tablist-type.v2.md` file. The user mentioned v3.md which doesn't exist yet. They likely want me to use v2.md as the basis and apply the audit feedback to create a v3 scope. Let me read v2.md to understand the full scope.

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v2.md"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/.scopes/virtual-folder-tablist-type.v2.md</path>
<type>file</type>
<content>1: # Virtual Folder Tab List Type - v2 Scope Document
2: 
3: > **Version**: 2.0  
4: > **Codebase Root**: `components/crm/source/`  
5: > **Status**: SCOPE MAPPED  
6: > **Uses**: Global module (`custom/Espo/Modules/Global` and `client/custom/modules/global`)
7: 
8: ## Overview
9: 
10: This feature adds a new tab list item type called `virtualFolder` that acts as a sibling to `group`, `divider`, and `url` types. A virtual folder:
11: - Displays entity records dynamically fetched via a preset filter
12: - Shows a collapsible "divider-like" header with entity icon + custom label
13: - Lists record items as clickable links under the header
14: - Provides a quick-create button and more options menu on hover
15: 
16: ### Key Changes from v1
17: 
18: Based on audit findings, v2 addresses:
19: 1. **Filter resolution strategy** - Uses `primaryFilter` collection parameter (matches EspoCRM patterns)
20: 2. **Navbar integration approach** - View injection with `itemDataList` pattern for cleaner separation
21: 3. **Explicit filter merge logic** - Documented presetFilters access pattern
22: 4. **Event handling specification** - Dynamic field dependency pattern with listenTo
23: 
24: ---
25: 
26: ## Decisions
27: 
28: | # | Decision | Alternatives Considered | Rationale |
29: |---|----------|------------------------|-----------|
30: | 1 | Store virtual folder config as new tab type `virtualFolder` in tabList | Separate entity, JSON field | Reuses existing tabList infrastructure, follows group/divider/url pattern |
31: | 2 | Use `collection.data.primaryFilter = filterName` for filter resolution | Manual where-clause construction | Matches EspoCRM collection patterns; see `client/src/views/record/panels/relationship.js:574-581` |
32: | 3 | Use collection factory pattern instead of raw Ajax | Raw `Espo.Ajax.getRequest()` | Enables proper filter resolution, pagination support, ACL handling |
33: | 4 | **View injection via `itemDataList` pattern** | Custom navbar template override | Cleaner separation; no need to maintain template fork; follows `navbar.tpl` lines 188-189 pattern |
34: | 5 | Collapse state stored in browser localStorage | Server-side preference | Per-device preference, no server load, instant toggle |
35: | 6 | Use presetFilters from Preferences for saved filter selection | Create new SavedFilter entity link | Uses existing EspoCRM preset filter system |
36: | 7 | Limit visible items with `maxItems` property (default: 5) | Hard-coded limit, config setting | Per-folder customization, reasonable default |
37: | 8 | Quick create uses existing `RecordModal` helper with explicit import | Custom modal | Consistent UX, reuses proven code; import: `import RecordModal from 'helpers/record-modal';` |
38: | 9 | More options menu includes: Refresh, View all in list | Complex action set | MVP feature set, extensible later |
39: | 10 | Add `isLoading` state with spinner | No loading indicator | Better UX during record fetch |
40: | 11 | **Extend custom navbar's `prepareTabItemDefs()` override** | Hook into core navbar | Custom navbar already extends core; virtual folder handling belongs there |
41: 
42: ---
43: 
44: ## Data Model Design
45: 
46: ### Virtual Folder Item Structure (stored in tabList)
47: 
48: ```json
49: {
50:   "type": "virtualFolder",
51:   "id": "vf-123456",
52:   "label": "My Open Tasks",
53:   "entityType": "Task",
54:   "filterName": "myOpen",
55:   "maxItems": 5,
56:   "iconClass": null,
57:   "color": null,
58:   "orderBy": "createdAt",
59:   "order": "desc"
60: }
61: ```
62: 
63: ### Field Definitions
64: 
65: | Field | Type | Required | Description |
66: |-------|------|----------|-------------|
67: | `type` | string | Yes | Must be `"virtualFolder"` |
68: | `id` | string | Yes | Unique identifier for collapse state |
69: | `label` | string | No | Custom label (falls back to entity plural name) |
70: | `entityType` | string | Yes | Target entity scope (e.g., "Task", "Opportunity") |
71: | `filterName` | string | No | Preset filter name from clientDefs or user presetFilters |
72: | `maxItems` | int | No | Max items to display (default: 5, 0 = unlimited) |
73: | `iconClass` | string | No | Override entity icon (falls back to entity iconClass) |
74: | `color` | string | No | Border/accent color |
75: | `orderBy` | string | No | Field to order by (default: entity default) |
76: | `order` | string | No | "asc" or "desc" (default: entity default) |
77: 
78: ---
79: 
80: ## File Manifest
81: 
82: ### Files to CREATE (ordered by complexity/risk, highest first)
83: 
84: #### 1. Virtual Folder Navbar Item View (CRITICAL)
85: 
86: **Path:** `client/custom/modules/global/src/views/site/navbar/virtual-folder.js`
87: 
88: **Purpose:** Main view component for rendering a virtual folder in the sidenav. Handles record fetching, collapse/expand, quick create, and more options.
89: 
90: **Key Implementation Details:**
91: - Extends `View` class
92: - `template` property: `global:site/navbar/virtual-folder`
93: - **Uses collection factory pattern** for filter resolution:
94:   ```javascript
95:   async fetchRecords() {
96:       const collection = this.getCollectionFactory().create(this.entityType);
97:       collection.maxSize = this.maxItems || 5;
98:       if (this.filterName) {
99:           collection.data.primaryFilter = this.filterName;
100:       }
101:       if (this.orderBy) {
102:           collection.setOrder(this.orderBy, this.order || 'desc');
103:       }
104:       await collection.fetch();
105:       this.recordList = collection.models;
106:   }
107:   ```
108: - Manages collapse state via `localStorage` key `navbar-vf-{id}-collapsed`
109: - Creates quick create view using `RecordModal` helper:
110:   ```javascript
111:   import RecordModal from 'helpers/record-modal';
112:   // ...
113:   async actionQuickCreate() {
114:       const helper = new RecordModal();
115:       await helper.showCreate(this, { entityType: this.entityType });
116:   }
117:   ```
118: - Manages `isLoading` state with spinner in template
119: 
120: **Reference Patterns:**
121: - Collection factory: `client/src/views/record/panels/relationship.js` lines 574-581 for `setFilter()` / `primaryFilter` pattern
122: - Quick create: `client/src/views/site/navbar/quick-create.js` lines 117-121
123: - Icon retrieval: `client/custom/modules/global/src/views/activities/fields/name-with-icon.js` lines 52-55
124: 
125: ---
126: 
127: #### 2. Custom Navbar Extension (CRITICAL)
128: 
129: **Path:** `client/custom/modules/global/src/views/site/navbar.js` (EDIT existing file)
130: 
131: **Purpose:** Add virtual folder handling to the existing custom navbar.
132: 
133: **Changes Required:**
134: 
135: 1. **Add import for custom TabsHelper:**
136:    ```javascript
137:    import TabsHelper from 'global:helpers/site/tabs';
138:    ```
139: 
140: 2. **Override `prepareTabItemDefs()` to handle virtual folders:**
141:    Add after the existing methods, before the closing brace:
142:    ```javascript
143:    prepareTabItemDefs(params, tab, i, vars) {
144:        // Check for virtual folder FIRST (before calling super)
145:        if (this.tabsHelper.isTabVirtualFolder(tab)) {
146:            return this.prepareVirtualFolderDefs(tab, i, vars);
147:        }
148:        
149:        // Delegate to parent for all other types
150:        return super.prepareTabItemDefs(params, tab, i, vars);
151:    }
152:    
153:    prepareVirtualFolderDefs(tab, i, vars) {
154:        const iconClass = tab.iconClass || 
155:            this.getMetadata().get(['clientDefs', tab.entityType, 'iconClass']) || 
156:            'fas fa-folder';
157:        
158:        return {
159:            name: `vf-${tab.id}`,
160:            isInMore: vars.moreIsMet,
161:            isVirtualFolder: true,
162:            virtualFolderId: tab.id,
163:            entityType: tab.entityType,
164:            label: tab.label || this.getLanguage().translate(tab.entityType, 'scopeNamesPlural'),
165:            iconClass: iconClass,
166:            color: tab.color,
167:            aClassName: 'nav-link-virtual-folder',
168:            config: tab, // Pass full config to view
169:        };
170:    }
171:    ```
172: 
173: 3. **Override `setupTabDefsList()` to create virtual folder views:**
174:    After the super call completes, iterate and create views:
175:    ```javascript
176:    setupTabDefsList() {
177:        super.setupTabDefsList();
178:        
179:        // Create virtual folder views for items in tabDefsList
180:        this.tabDefsList.forEach((defs) => {
181:            if (defs.isVirtualFolder) {
182:                this.createVirtualFolderView(defs);
183:            }
184:        });
185:    }
186:    
187:    createVirtualFolderView(defs) {
188:        const key = 'virtualFolder-' + defs.virtualFolderId;
189:        this.createView(key, 'global:views/site/navbar/virtual-folder', {
190:            selector: `[data-virtual-folder-id="${defs.virtualFolderId}"]`,
191:            virtualFolderConfig: defs.config,
192:        });
193:    }
194:    ```
195: 
196: 4. **Override `data()` to inject virtual folder HTML:**
197:    ```javascript
198:    data() {
199:        const baseData = super.data();
200:        
201:        // Inject virtual folder HTML into tabDefsList items
202:        baseData.tabDefsList1 = baseData.tabDefsList1.map(defs => {
203:            if (defs.isVirtualFolder) {
204:                const viewKey = 'virtualFolder-' + defs.virtualFolderId;
205:                const view = this.getView(viewKey);
206:                defs.html = view ? view.getHtml() : '';
207:            }
208:            return defs;
209:        });
210:        
211:        return baseData;
212:    }
213:    ```
214: 
215: ---
216: 
217: #### 3. Custom Tabs Helper (HIGH)
218: 
219: **Path:** `client/custom/modules/global/src/helpers/site/tabs.js` (CREATE)
220: 
221: **Purpose:** Extend core TabsHelper to add virtual folder detection.
222: 
223: **Implementation:**
224: ```javascript
225: import CoreTabsHelper from 'helpers/site/tabs';
226: 
227: export default class TabsHelper extends CoreTabsHelper {
228:     /**
229:      * Is a tab a virtual folder.
230:      * @param {string|{type?: string}} item
231:      * @return {boolean}
232:      */
233:     isTabVirtualFolder(item) {
234:         return typeof item === 'object' && item.type === 'virtualFolder';
235:     }
236:     
237:     /**
238:      * Override checkTabAccess to handle virtual folders.
239:      * @param {Record|string} item
240:      * @return {boolean}
241:      */
242:     checkTabAccess(item) {
243:         if (this.isTabVirtualFolder(item)) {
244:             // Check ACL on entity type
245:             return this.acl.check(item.entityType, 'read');
246:         }
247:         
248:         return super.checkTabAccess(item);
249:     }
250: }
251: ```
252: 
253: **Update custom navbar to use this helper:**
254: In `client/custom/modules/global/src/views/site/navbar.js`, change:
255: ```javascript
256: // In setup(), replace:
257: this.tabsHelper = new TabsHelper(
258:     this.getConfig(),
259:     this.getPreferences(),
260:     this.getUser(),
261:     this.getAcl(),
262:     this.getMetadata(),
263:     this.getLanguage()
264: );
265: ```
266: 
267: ---
268: 
269: #### 4. Virtual Folder Template (HIGH)
270: 
271: **Path:** `client/custom/modules/global/res/templates/site/navbar/virtual-folder.tpl`
272: 
273: **Structure:**
274: ```handlebars
275: <li class="tab tab-virtual-folder{{#if isCollapsed}} collapsed{{/if}}" 
276:     data-name="vf-{{id}}"
277:     data-virtual-folder-id="{{id}}">
278:     <div class="virtual-folder-header" data-action="toggleVirtualFolder" data-id="{{id}}">
279:         <span class="virtual-folder-icon {{iconClass}}"{{#if color}} style="color: {{color}}"{{/if}}></span>
280:         <span class="virtual-folder-label">{{label}}</span>
281:         <span class="virtual-folder-caret fas fa-chevron-{{#if isCollapsed}}right{{else}}down{{/if}}"></span>
282:         <div class="virtual-folder-actions">
283:             <a class="action" data-action="quickCreate" title="{{translate 'Create' scope='Global'}}">
284:                 <span class="fas fa-plus"></span>
285:             </a>
286:             <a class="dropdown-toggle" data-toggle="dropdown">
287:                 <span class="fas fa-ellipsis-v"></span>
288:             </a>
289:             <ul class="dropdown-menu pull-right">
290:                 <li><a data-action="refresh">{{translate 'Refresh' scope='Global'}}</a></li>
291:                 <li><a data-action="viewAll">{{translate 'View All' scope='Global'}}</a></li>
292:             </ul>
293:         </div>
294:     </div>
295:     <ul class="virtual-folder-items{{#if isCollapsed}} hidden{{/if}}">
296:         {{#if isLoading}}
297:             <li class="virtual-folder-loading"><span class="fas fa-spinner fa-spin"></span></li>
298:         {{else}}
299:             {{#each recordList}}
300:                 <li class="virtual-folder-item">
301:                     <a href="#{{../entityType}}/view/{{id}}" class="nav-link">
302:                         {{name}}
303:                     </a>
304:                 </li>
305:             {{/each}}
306:             {{#unless recordList.length}}
307:                 <li class="virtual-folder-empty">{{translate 'No records found' scope='Global'}}</li>
308:             {{/unless}}
309:             {{#if hasMore}}
310:                 <li class="virtual-folder-more">
311:                     <a href="#{{entityType}}/list{{#if filterQuery}}?{{filterQuery}}{{/if}}">
312:                         {{translate 'View all' scope='Global'}} ({{totalCount}})
313:                     </a>
314:                 </li>
315:             {{/if}}
316:         {{/if}}
317:     </ul>
318: </li>
319: ```
320: 
321: ---
322: 
323: #### 5. Virtual Folder Styles (HIGH)
324: 
325: **Path:** `client/custom/modules/global/css/virtual-folder.css`
326: 
327: **Key Styles:**
328: ```css
329: .tab-virtual-folder {
330:     position: relative;
331: }
332: 
333: .virtual-folder-header {
334:     display: flex;
335:     align-items: center;
336:     padding: var(--8px) var(--12px);
337:     cursor: pointer;
338:     border-left: 3px solid transparent;
339: }
340: 
341: .virtual-folder-header:hover {
342:     background-color: var(--nav-tab-hover-bg);
343: }
344: 
345: .virtual-folder-icon {
346:     margin-right: var(--8px);
347:     font-size: var(--14px);
348: }
349: 
350: .virtual-folder-label {
351:     flex: 1;
352:     font-weight: 500;
353:     overflow: hidden;
354:     text-overflow: ellipsis;
355:     white-space: nowrap;
356: }
357: 
358: .virtual-folder-caret {
359:     font-size: var(--10px);
360:     color: var(--text-muted-color);
361:     transition: transform 0.2s ease;
362: }
363: 
364: .tab-virtual-folder.collapsed .virtual-folder-caret {
365:     transform: rotate(0deg);
366: }
367: 
368: .tab-virtual-folder:not(.collapsed) .virtual-folder-caret {
369:     transform: rotate(90deg);
370: }
371: 
372: .virtual-folder-actions {
373:     display: none;
374:     align-items: center;
375:     gap: var(--4px);
376: }
377: 
378: .virtual-folder-header:hover .virtual-folder-actions {
379:     display: flex;
380: }
381: 
382: .virtual-folder-actions a {
383:     padding: var(--4px);
384:     color: var(--text-muted-color);
385: }
386: 
387: .virtual-folder-actions a:hover {
388:     color: var(--text-color);
389: }
390: 
391: .virtual-folder-items {
392:     list-style: none;
393:     padding-left: var(--20px);
394:     margin: 0;
395:     max-height: 300px;
396:     overflow-y: auto;
397: }
398: 
399: .virtual-folder-items.hidden {
400:     display: none;
401: }
402: 
403: .virtual-folder-item {
404:     padding: var(--4px) var(--12px);
405: }
406: 
407: .virtual-folder-item a {
408:     display: block;
409:     color: var(--text-muted-color);
410:     font-size: var(--13px);
411:     overflow: hidden;
412:     text-overflow: ellipsis;
413:     white-space: nowrap;
414: }
415: 
416: .virtual-folder-item a:hover {
417:     color: var(--text-color);
418: }
419: 
420: .virtual-folder-empty {
421:     padding: var(--8px) var(--12px);
422:     color: var(--text-soft-color);
423:     font-style: italic;
424:     font-size: var(--12px);
425: }
426: 
427: .virtual-folder-loading {
428:     padding: var(--8px) var(--12px);
429:     text-align: center;
430:     color: var(--text-muted-color);
431: }
432: 
433: .virtual-folder-more {
434:     padding: var(--4px) var(--12px);
435:     border-top: 1px solid var(--border-color);
436: }
437: 
438: .virtual-folder-more a {
439:     font-size: var(--12px);
440:     color: var(--link-color);
441: }
442: ```
443: 
444: **Also update:**
445: `client/custom/modules/global/src/views/site/navbar.js` - Add CSS injection in `afterRender()`:
446: ```javascript
447: injectVirtualFolderStyles() {
448:     if (document.getElementById('virtual-folder-styles')) {
449:         return;
450:     }
451:     const link = document.createElement('link');
452:     link.id = 'virtual-folder-styles';
453:     link.rel = 'stylesheet';
454:     link.href = 'client/custom/modules/global/css/virtual-folder.css';
455:     document.head.appendChild(link);
456: }
457: ```
458: 
459: ---
460: 
461: #### 6. Edit Virtual Folder Modal (MEDIUM)
462: 
463: **Path:** `client/custom/modules/global/src/views/settings/modals/edit-tab-virtual-folder.js`
464: 
465: **Purpose:** Modal for configuring virtual folder properties.
466: 
467: **Structure:** Follows pattern from `client/src/views/settings/modals/edit-tab-group.js`
468: 
469: **Implementation:**
470: ```javascript
471: import Modal from 'views/modal';
472: import Model from 'model';
473: 
474: class EditTabVirtualFolderModalView extends Modal {
475: 
476:     className = 'dialog dialog-record'
477:     
478:     templateContent = `<div class="record no-side-margin">{{{record}}}</div>`
479:     
480:     setup() {
481:         super.setup();
482:         
483:         this.headerText = this.translate('Virtual Folder', 'labels', 'Settings');
484:         
485:         this.buttonList.push({
486:             name: 'apply',
487:             label: 'Apply',
488:             style: 'danger',
489:         });
490:         
491:         this.buttonList.push({
492:             name: 'cancel',
493:             label: 'Cancel',
494:         });
495:         
496:         this.shortcutKeys = {
497:             'Control+Enter': () => this.actionApply(),
498:         };
499:         
500:         const detailLayout = [
501:             {
502:                 rows: [
503:                     [
504:                         {
505:                             name: 'label',
506:                             labelText: this.translate('label', 'fields', 'Admin'),
507:                         },
508:                         {
509:                             name: 'entityType',
510:                             labelText: this.translate('entityType', 'fields', 'Global'),
511:                             view: 'global:views/settings/fields/virtual-folder-entity',
512:                         },
513:                     ],
514:                     [
515:                         {
516:                             name: 'filterName',
517:                             labelText: this.translate('filterName', 'fields', 'Global'),
518:                             view: 'global:views/settings/fields/virtual-folder-filter',
519:                         },
520:                         {
521:                             name: 'maxItems',
522:                             labelText: this.translate('maxItems', 'fields', 'Global'),
523:                         },
524:                     ],
525:                     [
526:                         {
527:                             name: 'iconClass',
528:                             labelText: this.translate('iconClass', 'fields', 'EntityManager'),
529:                             view: 'views/admin/entity-manager/fields/icon-class',
530:                         },
531:                         {
532:                             name: 'color',
533:                             labelText: this.translate('color', 'fields', 'EntityManager'),
534:                             view: 'views/fields/colorpicker',
535:                         },
536:                     ],
537:                     [
538:                         {
539:                             name: 'orderBy',
540:                             labelText: this.translate('orderBy', 'fields', 'Global'),
541:                         },
542:                         {
543:                             name: 'order',
544:                             labelText: this.translate('order', 'fields', 'Global'),
545:                         },
546:                     ],
547:                 ],
548:             },
549:         ];
550:         
551:         const model = this.model = new Model();
552:         
553:         model.name = 'VirtualFolder';
554:         model.set(this.options.itemData);
555:         
556:         model.setDefs({
557:             fields: {
558:                 label: { type: 'varchar' },
559:                 entityType: { 
560:                     type: 'enum',
561:                     required: true,
562:                 },
563:                 filterName: { type: 'enum' },
564:                 maxItems: { 
565:                     type: 'int',
566:                     default: 5,
567:                 },
568:                 iconClass: { type: 'base' },
569:                 color: { type: 'base' },
570:                 orderBy: { type: 'enum' },
571:                 order: { 
572:                     type: 'enum',
573:                     options: ['asc', 'desc'],
574:                     default: 'desc',
575:                 },
576:             },
577:         });
578:         
579:         this.createView('record', 'views/record/edit-for-modal', {
580:             detailLayout: detailLayout,
581:             model: model,
582:             selector: '.record',
583:         });
584:         
585:         // Listen for entityType changes to reload filter options
586:         this.listenTo(model, 'change:entityType', () => {
587:             const filterField = this.getView('record')?.getFieldView('filterName');
588:             if (filterField) {
589:                 filterField.reRender();
590:             }
591:         });
592:     }
593:     
594:     actionApply() {
595:         const recordView = this.getView('record');
596:         
597:         if (recordView.validate()) {
598:             return;
599:         }
600:         
601:         const data = recordView.fetch();
602:         this.trigger('apply', data);
603:     }
604: }
605: 
606: export default EditTabVirtualFolderModalView;
607: ```
608: 
609: **Reference:** `client/src/views/settings/modals/edit-tab-group.js`
610: 
611: ---
612: 
613: #### 7. Entity Type Field (MEDIUM)
614: 
615: **Path:** `client/custom/modules/global/src/views/settings/fields/virtual-folder-entity.js`
616: 
617: **Purpose:** Field for selecting entity type.
618: 
619: **Implementation:**
620: ```javascript
621: import EnumFieldView from 'views/fields/enum';
622: 
623: class VirtualFolderEntityFieldView extends EnumFieldView {
624:     
625:     setupOptions() {
626:         // Load scopes with tab: true and ACL read access
627:         const scopes = this.getMetadata().get('scopes') || {};
628:         
629:         this.params.options = Object.keys(scopes)
630:             .filter(scope => {
631:                 if (scopes[scope].disabled) {
632:                     return false;
633:                 }
634:                 
635:                 // ACL check with read access (not just check)
636:                 if (!this.getAcl().checkScope(scope, 'read')) {
637:                     return false;
638:                 }
639:                 
640:                 return scopes[scope].tab;
641:             })
642:             .sort((v1, v2) => {
643:                 return this.translate(v1, 'scopeNamesPlural')
644:                     .localeCompare(this.translate(v2, 'scopeNamesPlural'));
645:             });
646:         
647:         this.translatedOptions = {};
648:         this.params.options.forEach(scope => {
649:             this.translatedOptions[scope] = this.translate(scope, 'scopeNamesPlural');
650:         });
651:     }
652: }
653: 
654: export default VirtualFolderEntityFieldView;
655: ```
656: 
657: **Reference:** `client/src/views/settings/fields/tab-list.js` lines 65-68 for ACL pattern
658: 
659: ---
660: 
661: #### 8. Filter Name Field (MEDIUM)
662: 
663: **Path:** `client/custom/modules/global/src/views/settings/fields/virtual-folder-filter.js`
664: 
665: **Purpose:** Field for selecting preset filter based on selected entity.
666: 
667: **Implementation:**
668: ```javascript
669: import EnumFieldView from 'views/fields/enum';
670: 
671: class VirtualFolderFilterFieldView extends EnumFieldView {
672:     
673:     setupOptions() {
674:         const entityType = this.model.get('entityType');
675:         
676:         if (!entityType) {
677:             this.params.options = [''];
678:             this.translatedOptions = { '': '--' };
679:             return;
680:         }
681:         
682:         // System preset filters from clientDefs
683:         const systemFilters = this.getMetadata()
684:             .get(['clientDefs', entityType, 'filterList']) || [];
685:         
686:         // User preset filters from Preferences
687:         const presetFilters = this.getPreferences().get('presetFilters') || {};
688:         const userFilters = presetFilters[entityType] || [];
689:         
690:         // Merge and dedupe
691:         const allFilters = new Map();
692:         
693:         // Add "no filter" option
694:         allFilters.set('', this.translate('No Filter', 'labels', 'Global'));
695:         
696:         // Add system filters
697:         systemFilters.forEach(filter => {
698:             const name = typeof filter === 'string' ? filter : filter.name;
699:             const label = typeof filter === 'string' 
700:                 ? this.translate(name, 'presetFilters', entityType)
701:                 : (filter.label || this.translate(name, 'presetFilters', entityType));
702:             allFilters.set(name, label);
703:         });
704:         
705:         // Add user filters
706:         userFilters.forEach(filter => {
707:             if (!allFilters.has(filter.name)) {
708:                 allFilters.set(filter.name, filter.name);
709:             }
710:         });
711:         
712:         this.params.options = [''].concat([...allFilters.keys()].filter(k => k !== ''));
713:         this.translatedOptions = Object.fromEntries(allFilters);
714:     }
715: }
716: 
717: export default VirtualFolderFilterFieldView;
718: ```
719: 
720: **Reference:** `client/src/views/record/search.js` lines 303, 641-644 for presetFilters access pattern
721: 
722: ---
723: 
724: ### Files to EDIT
725: 
726: #### 1. Custom Navbar View (CRITICAL)
727: 
728: **Path:** `client/custom/modules/global/src/views/site/navbar.js`
729: 
730: **Changes:**
731: - Add import for custom TabsHelper: `import TabsHelper from 'global:helpers/site/tabs';`
732: - Update `setup()` to instantiate custom TabsHelper instead of core
733: - Override `prepareTabItemDefs()` to handle virtual folders
734: - Add `prepareVirtualFolderDefs()` method
735: - Override `setupTabDefsList()` to create virtual folder views
736: - Add `createVirtualFolderView()` method
737: - Add `injectVirtualFolderStyles()` method (call in `afterRender()`)
738: - Override `data()` to inject virtual folder HTML
739: 
740: **Pattern:** See detailed implementation in CREATE section above.
741: 
742: ---
743: 
744: #### 2. Tab List Field View Extension (MEDIUM)
745: 
746: **Path:** `client/custom/modules/global/src/views/settings/fields/tab-list.js` (CREATE if doesn't exist, or find existing)
747: 
748: **Purpose:** Extend core tab-list field to handle virtual folder type.
749: 
750: **Implementation:**
751: ```javascript
752: import CoreTabListFieldView from 'views/settings/fields/tab-list';
753: 
754: class TabListFieldView extends CoreTabListFieldView {
755:     
756:     getGroupItemHtml(item) {
757:         // Handle virtual folder type
758:         if (item.type === 'virtualFolder') {
759:             return this.getVirtualFolderItemHtml(item);
760:         }
761:         
762:         return super.getGroupItemHtml(item);
763:     }
764:     
765:     getVirtualFolderItemHtml(item) {
766:         const labelElement = document.createElement('span');
767:         labelElement.textContent = item.label || this.translate(item.entityType, 'scopeNamesPlural');
768:         
769:         const icon = document.createElement('span');
770:         icon.className = 'fas fa-folder text-muted';
771:         icon.style.marginRight = 'var(--4px)';
772:         
773:         const itemElement = document.createElement('span');
774:         itemElement.className = 'text';
775:         itemElement.append(icon, labelElement);
776:         
777:         const div = document.createElement('div');
778:         div.className = 'list-group-item';
779:         div.dataset.value = item.id;
780:         div.style.cursor = 'default';
781:         
782:         // Drag handle
783:         const dragHandle = document.createElement('span');
784:         dragHandle.className = 'drag-handle';
785:         dragHandle.innerHTML = '<span class="fas fa-grip fa-sm"></span>';
786:         
787:         // Edit button
788:         const editBtn = document.createElement('a');
789:         editBtn.role = 'button';
790:         editBtn.tabIndex = 0;
791:         editBtn.dataset.value = item.id;
792:         editBtn.dataset.action = 'editGroup';
793:         editBtn.innerHTML = '<span class="fas fa-pencil-alt fa-sm"></span>';
794:         
795:         const itemButton = document.createElement('span');
796:         itemButton.className = 'item-button';
797:         itemButton.append(editBtn);
798:         
799:         // Remove button
800:         const removeBtn = document.createElement('a');
801:         removeBtn.role = 'button';
802:         removeBtn.tabIndex = 0;
803:         removeBtn.className = 'pull-right';
804:         removeBtn.dataset.value = item.id;
805:         removeBtn.dataset.action = 'removeValue';
806:         removeBtn.innerHTML = '<span class="fas fa-times"></span>';
807:         
808:         div.append(dragHandle, itemButton, itemElement, removeBtn);
809:         
810:         return div.outerHTML;
811:     }
812:     
813:     editGroup(id) {
814:         const item = Espo.Utils.cloneDeep(this.getGroupValueById(id) || {});
815:         
816:         // Route virtual folder to its own modal
817:         const view = item.type === 'virtualFolder'
818:             ? 'global:views/settings/modals/edit-tab-virtual-folder'
819:             : {
820:                 divider: 'views/settings/modals/edit-tab-divider',
821:                 url: 'views/settings/modals/edit-tab-url'
822:             }[item.type] || 'views/settings/modals/edit-tab-group';
823:         
824:         // ... rest of method same as parent
825:         const index = this.getGroupIndexById(id);
826:         const tabList = Espo.Utils.cloneDeep(this.selected);
827:         
828:         this.createView('dialog', view, {
829:             itemData: item,
830:             parentType: this.model.entityType,
831:         }, view => {
832:             view.render();
833:             
834:             this.listenToOnce(view, 'apply', itemData => {
835:                 for (const a in itemData) {
836:                     tabList[index][a] = itemData[a];
837:                 }
838:                 
839:                 this.model.set(this.name, tabList);
840:                 view.close();
841:             });
842:         });
843:     }
844: }
845: 
846: export default TabListFieldView;
847: ```
848: 
849: ---
850: 
851: #### 3. Tab List Field Add Modal Extension (LOW)
852: 
853: **Path:** `client/custom/modules/global/src/views/settings/modals/tab-list-field-add.js` (CREATE)
854: 
855: **Purpose:** Extend core modal to add "Add Virtual Folder" button.
856: 
857: **Implementation:**
858: ```javascript
859: import CoreTabListFieldAddModalView from 'views/settings/modals/tab-list-field-add';
860: 
861: class TabListFieldAddModalView extends CoreTabListFieldAddModalView {
862:     
863:     setup() {
864:         super.setup();
865:         
866:         // Add virtual folder button after existing buttons
867:         this.addButton({
868:             name: 'addVirtualFolder',
869:             text: this.translate('Virtual Folder', 'labels', 'Settings'),
870:             onClick: () => this.actionAddVirtualFolder(),
871:             position: 'right',
872:             iconClass: 'fas fa-plus fa-sm',
873:         });
874:     }
875:     
876:     actionAddVirtualFolder() {
877:         this.trigger('add', {
878:             type: 'virtualFolder',
879:             id: Math.floor(Math.random() * 1000000 + 1).toString(),
880:             label: null,
881:             entityType: null,
882:             filterName: null,
883:             maxItems: 5,
884:             iconClass: null,
885:             color: null,
886:             orderBy: null,
887:             order: 'desc',
888:         });
889:     }
890: }
891: 
892: export default TabListFieldAddModalView;
893: ```
894: 
895: **Reference:** `client/src/views/settings/modals/tab-list-field-add.js`
896: 
897: ---
898: 
899: #### 4. Global Translations (LOW)
900: 
901: **Path:** `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json`
902: 
903: **Add to appropriate sections:**
904: ```json
905: {
906:     "labels": {
907:         "Virtual Folder": "Virtual Folder",
908:         "Add Virtual Folder": "Add Virtual Folder",
909:         "Edit Virtual Folder": "Edit Virtual Folder",
910:         "No Filter": "No Filter",
911:         "No records found": "No records found"
912:     },
913:     "fields": {
914:         "entityType": "Entity",
915:         "filterName": "Filter",
916:         "maxItems": "Max Items",
917:         "orderBy": "Order By",
918:         "order": "Order"
919:     },
920:     "messages": {
921:         "errorSavingPreference": "Error saving preference."
922:     }
923: }
924: ```
925: 
926: ---
927: 
928: #### 5. Settings Translations (LOW)
929: 
930: **Path:** `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json`
931: 
932: **Add:**
933: ```json
934: {
935:     "labels": {
936:         "Virtual Folder": "Virtual Folder",
937:         "Group Tab": "Group Tab"
938:     }
939: }
940: ```
941: 
942: ---
943: 
944: ### Files to CONSIDER
945: 
946: | File Path | Reason |
947: |-----------|--------|
948: | `application/Espo/Resources/metadata/entityDefs/SidenavConfig.json` | If SidenavConfig.tabList field needs custom view override to use global tab-list field |
949: | `client/custom/modules/global/src/views/modals/navbar-config-field-add.js` | May need update if virtual folders should be addable from SidenavConfig editing context |
950: 
951: ---
952: 
953: ### Related Files (for reference only, no changes needed)
954: 
955: | File Path | Pattern Reference |
956: |-----------|-------------------|
957: | `client/src/views/settings/modals/tab-list-field-add.js` | Pattern for adding addGroup/addDivider/addUrl buttons |
958: | `client/src/views/settings/modals/edit-tab-group.js` | Pattern for modal with dynamic field model |
959: | `client/src/views/settings/modals/edit-tab-url.js` | Another modal pattern |
960: | `client/src/views/settings/fields/tab-list.js` | Pattern for handling complex tab items |
961: | `client/src/views/site/navbar/quick-create.js` | Quick create implementation with RecordModal |
962: | `client/src/helpers/site/tabs.js` | Tab type detection pattern (isTabDivider, isTabUrl, etc.) |
963: | `client/src/views/site/navbar.js` | Tab rendering and preparation pattern (prepareTabItemDefs) |
964: | `client/custom/modules/global/src/views/site/navbar.js` | Existing custom navbar with team configs |
965: | `client/custom/modules/global/src/views/activities/fields/name-with-icon.js` | Entity icon retrieval pattern |
966: | `client/src/views/record/search.js` | presetFilters handling pattern (lines 303, 641-644) |
967: | `client/src/views/record/panels/relationship.js` | Filter resolution with primaryFilter (lines 574-581) |
968: | `client/src/helpers/record-modal.js` | showCreate() pattern |
969: | `client/res/templates/site/navbar.tpl` | Template structure (itemDataList pattern lines 188-189) |
970: 
971: ---
972: 
973: ## Implementation Order
974: 
975: ### Phase 1: Data Model & Core Modal
976: 1. Create `edit-tab-virtual-folder.js` modal
977: 2. Create `virtual-folder-entity.js` field
978: 3. Create `virtual-folder-filter.js` field
979: 4. Add translations
980: 
981: ### Phase 2: Tab List Integration
982: 1. Create `tab-list-field-add.js` modal extension
983: 2. Create `tab-list.js` field view extension
984: 3. Test virtual folder item creation/editing in tab list
985: 
986: ### Phase 3: Navbar Rendering
987: 1. Create `helpers/site/tabs.js` with isTabVirtualFolder()
988: 2. Update `navbar.js` to use custom TabsHelper and handle virtual folders
989: 3. Create `virtual-folder.js` view
990: 4. Create `virtual-folder.tpl` template
991: 5. Create `virtual-folder.css` styles
992: 
993: ### Phase 4: Testing & Polish
994: 1. Test collapse/expand persistence
995: 2. Test quick create functionality
996: 3. Test refresh action
997: 4. Test with various entity types
998: 5. Test with user preset filters
999: 6. Test ACL restrictions
1000: 7. Mobile/responsive behavior
1001: 
1002: ---
1003: 
1004: ## Error Handling
1005: 
1006: ### Invalid Entity Type
1007: - If `entityType` is disabled or ACL denied → hide virtual folder
1008: - Log warning in console
1009: 
1010: ### Invalid Filter
1011: - If `filterName` doesn't exist → fall back to no filter (empty string)
1012: - Use entity default list view
1013: 
1014: ### Fetch Error
1015: - Show "Failed to load" message in virtual folder
1016: - Provide retry button in more options
1017: 
1018: ### No Records
1019: - Show empty state message: "No records found"
1020: - Still show quick create button
1021: 
1022: ---
1023: 
1024: ## Summary of File Count
1025: 
1026: | Category | Count |
1027: |----------|-------|
1028: | CREATE | 8 files |
1029: | EDIT | 5 files |
1030: | CONSIDER | 2 files |
1031: | Reference | 13 files |
1032: 
1033: ---
1034: 
1035: *v2 Scope document - Addresses audit findings for filter resolution, navbar integration, and event handling.*
1036: 
1037: **Tool: todowrite**
1038: 
1039: **Input:**
1040: ```json
1041: {
1042:   "todos": [
1043:     {
1044:       "content": "Create v2 scope document addressing audit findings",
1045:       "priority": "high",
1046:       "status": "completed"
1047:     }
1048:   ]
1049: }
1050: ```
1051: 
1052: **Output:**
1053: ```
1054: [
1055:   {
1056:     "content": "Create v2 scope document addressing audit findings",
1057:     "priority": "high",
1058:     "status": "completed"
1059:   }
1060: ]
1061: ```
1062: 
1063: ---
1064: 
1065: ## Assistant (Scope · glm-5-free · 40.3s)
1066: 
1067: _Thinking:_
1068: 
1069: I've completed the v2 scope document that addresses all the critical findings from the audit. The key changes from v1 are:
1070: 
1071: 1. **Filter resolution strategy** - Now uses `collection.data.primaryFilter` pattern matching EspoCRM conventions (addressing Critical Finding #1)
1072: 
1073: 2. **Navbar integration approach** - Uses view injection via itemDataList pattern instead of template override, and extends the custom navbar's prepareTabItemDefs() (addressing Critical Finding #2)
1074: 
1075: 3. **Explicit filter merge logic** - Documented the presetFilters access pattern with code example
1076: 
1077: 4. **Event handling specification** - Added the listenTo pattern for entityType changes triggering filter reload
1078: 
1079: 5. **Other warnings addressed**:
1080:    - RecordModal import path: Explicitly shown
1081:    - Entity type ACL check: Uses `checkScope(scope, 'read')` 
1082:    - Template integration: Uses view injection pattern
1083:    - Loading state: Added isLoading with spinner
1084: 
1085: The scope document is now complete and ready for implementation.
1086: 
1087: ## Summary
1088: 
1089: I've created the v2 scope document that addresses all critical findings from the audit:
1090: 
1091: ### Critical Issues Resolved:
1092: 
1093: 1. **Filter Data Resolution Strategy** (Critical Finding #1)
1094:    - Now uses `collection.data.primaryFilter = filterName` pattern
1095:    - Follows `client/src/views/record/panels/relationship.js:574-581`
1096:    - Uses collection factory for proper filter resolution, ACL handling, and pagination support
1097: 
1098: 2. **Custom Navbar Integration Approach** (Critical Finding #2)
1099:    - Uses **view injection via itemDataList pattern** for cleaner separation
1100:    - No need to maintain a template fork
1101:    - Extends `prepareTabItemDefs()` in custom navbar
1102:    - Creates custom `TabsHelper` with `isTabVirtualFolder()` detection
1103: 
1104: ### Warnings Addressed:
1105: 
1106: | Warning | Resolution |
1107: |---------|------------|
1108: | RecordModal import path | Explicit import: `import RecordModal from 'helpers/record-modal';` |
1109: | Entity type ACL check | Uses `this.getAcl().checkScope(scope, 'read')` |
1110: | Filter field dynamic options | `listenTo(model, 'change:entityType', ...)` pattern |
1111: | Template integration | View injection pattern with `data()` override |
1112: | presetFilters access | Documented pattern with code example |
1113: 
1114: ### Suggestions Incorporated:
1115: 
1116: - **Loading state indicator** - Added `isLoading` state with spinner in template
1117: - **Collection factory pattern** - Preferred over raw Ajax for better integration
1118: 
1119: ### File Summary:
1120: 
1121: | Category | Count |
1122: |----------|-------|
1123: | CREATE | 8 files |
1124: | EDIT | 5 files |
1125: | CONSIDER | 2 files |
1126: 
1127: The implementation order is organized into 4 phases: Data Model & Core Modal → Tab List Integration → Navbar Rendering → Testing & Polish.

(End of file - total 1127 lines)
</content>
```

---

## Assistant (Scope · glm-5-free · 24.1s)

_Thinking:_

Now I understand the task. The user wants me to:
1. Take the v2 scope document and the v2 audit report
2. Create a v3 scope mapping that addresses the audit findings

The key critical finding from the audit that needs to be addressed:
1. **View HTML Injection Strategy Is Architecturally Flawed** - The audit recommends using `afterRender()` DOM injection instead of `data()` override because:
   - Views in EspoCRM render asynchronously
   - `data()` may be called before views finish rendering
   - Race condition issue

Other warnings to address:
1. ACL method incorrect - should use `acl.checkScope()` not `acl.check()`
2. setupTabDefsList virtual folder filtering not handled
3. Missing directory structure for helpers
4. Template structure mismatch with existing navbar pattern

Let me now explore the codebase to understand:
1. The existing custom navbar
2. The core navbar patterns
3. How views render in EspoCRM
4. The TabsHelper patterns
5. The template structure

Let me start exploring the relevant files.

Now I need to explore the codebase to understand the patterns and verify the scope. Let me examine the key reference files:

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/site/navbar.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/site/navbar.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of Monostax.
3:  *
4:  * Monostax – Custom EspoCRM extensions.
5:  * Copyright (C) 2025 Antonio Moura. All rights reserved.
6:  * Website: https://www.monostax.ai
7:  *
8:  * PROPRIETARY AND CONFIDENTIAL
9:  ************************************************************************/
10: 
11: import NavbarSiteView from "views/site/navbar";
12: import $ from "jquery";
13: 
14: const DEFAULT_TABLIST_ID = '__default_tablist__';
15: 
16: /**
17:  * Custom navbar view that:
18:  * 1. Filters out Conversas menu items for users without chatSsoUrl
19:  * 2. Implements Linear.app-style mobile drawer navigation
20:  * 3. Supports multi-sidenav sidebar via team-scoped SidenavConfig entities
21:  * Uses appParams from the /api/v1/App/user response.
22:  */
23: class CustomNavbarSiteView extends NavbarSiteView {
24:     /** @private */
25:     isMobileDrawerOpen = false;
26: 
27:     /** @private */
28:     _switchingConfig = false;
29: 
30:     /**
31:      * @private
32:      * @return {boolean}
33:      */
34:     hasChatwootAccess() {
35:         return !!this.getHelper().getAppParam("chatSsoUrl");
36:     }
37: 
38:     /**
39:      * Filter out Conversas menu items if user doesn't have chatSsoUrl.
40:      * @private
41:      * @param {Array} tabList
42:      * @return {Array}
43:      */
44:     filterConversasItems(tabList) {
45:         if (this.hasChatwootAccess()) {
46:             return tabList;
47:         }
48: 
49:         return tabList.filter((item) => {
50:             if (!item || typeof item !== "object") {
51:                 return true;
52:             }
53: 
54:             if (item.type === "divider" && item.text === "$Conversations") {
55:                 return false;
56:             }
57: 
58:             if (item.type === "url" && item.id && /^8535\d{2}$/.test(item.id)) {
59:                 return false;
60:             }
61: 
62:             return true;
63:         });
64:     }
65: 
66:     /**
67:      * Override getTabList to use team-scoped navbar config system.
68:      * Resolution priority:
69:      *   1. Team SidenavConfig (if any configs exist for user's teams)
70:      *   2. Legacy tab customization (existing useCustomTabList/addCustomTabs)
71:      *   3. System default tabList
72:      * @return {(Object|string)[]}
73:      */
74:     getTabList() {
75:         if (this.hasNavbarConfigSystem()) {
76:             const activeConfig = this.getActiveNavbarConfig();
77: 
78:             if (activeConfig) {
79:                 if (activeConfig.isDefaultTabList) {
80:                     return this.filterConversasItems(this.getLegacyTabList());
81:                 }
82: 
83:                 if (activeConfig.tabList) {
84:                     let tabList = Espo.Utils.cloneDeep(activeConfig.tabList);
85: 
86:                     if (this.isSide()) {
87:                         tabList.unshift('Home');
88:                     }
89: 
90:                     return this.filterConversasItems(tabList);
91:                 }
92:             }
93:         }
94: 
95:         return this.filterConversasItems(this.getLegacyTabList());
96:     }
97: 
98:     /**
99:      * Get the legacy tabList via parent's getTabList, filtered for Conversas.
100:      * @return {(Object|string)[]}
101:      */
102:     getLegacyTabList() {
103:         return super.getTabList();
104:     }
105: 
106:     /**
107:      * @return {boolean}
108:      */
109:     hasNavbarConfigSystem() {
110:         const configList = this.getNavbarConfigList();
111: 
112:         return configList && configList.length > 0;
113:     }
114: 
115:     /**
116:      * Get the navbar config list from team-scoped SidenavConfig entities.
117:      * Fetches from `teamSidenavConfigs` appParam (already filtered server-side).
118:      * Optionally adds a "Default" tabList option.
119:      * @return {Object[]}
120:      */
121:     getNavbarConfigList() {
122:         const configs = [...(this.getHelper().getAppParam('teamSidenavConfigs') || [])];
123: 
124:         if (this.getConfig().get('navbarConfigShowDefaultTabList')) {
125:             configs.push({
126:                 id: DEFAULT_TABLIST_ID,
127:                 name: this.getLanguage().translate('defaultConfig', 'navbarConfig', 'Global'),
128:                 isDefaultTabList: true,
129:             });
130:         }
131: 
132:         return configs;
133:     }
134: 
135:     /**
136:      * Get the active navbar config from the resolved list.
137:      * @return {Object|null}
138:      */
139:     getActiveNavbarConfig() {
140:         const configList = this.getNavbarConfigList();
141: 
142:         if (!configList || configList.length === 0) {
143:             return null;
144:         }
145: 
146:         const activeId = this.getPreferences().get('activeNavbarConfigId');
147: 
148:         if (activeId) {
149:             if (activeId === DEFAULT_TABLIST_ID) {
150:                 const defaultOption = configList.find(c => c.id === DEFAULT_TABLIST_ID);
151: 
152:                 if (defaultOption) {
153:                     return defaultOption;
154:                 }
155: 
156:                 console.warn('Default tabList option selected but setting is disabled, falling back');
157:             } else {
158:                 const found = configList.find(c => c.id === activeId);
159: 
160:                 if (found) {
161:                     return found;
162:                 }
163: 
164:                 console.warn('Active navbar config ID not found, falling back to default');
165:             }
166:         }
167: 
168:         return configList.find(c => c.isDefault) || configList[0];
169:     }
170: 
171:     /**
172:      * Override setup to add preference listener for activeNavbarConfigId.
173:      */
174:     setup() {
175:         super.setup();
176: 
177:         this.listenTo(this.getHelper().preferences, 'update', (attributeList) => {
178:             if (!attributeList) {
179:                 return;
180:             }
181: 
182:             if (attributeList.includes('activeNavbarConfigId')) {
183:                 this.setupTabDefsList();
184:                 this.reRender();
185:             }
186:         });
187:     }
188: 
189:     /**
190:      * Override afterRender to inject drawer styles, move header icons,
191:      * close-on-navigate, and set up the navbar config selector.
192:      */
193:     afterRender() {
194:         super.afterRender();
195: 
196:         this.injectMobileDrawerStyles();
197:         this.injectNavbarConfigSelectorStyles();
198:         this.setupMobileHeaderIcons();
199:         this.setupNavbarConfigSelector();
200: 
201:         this.listenTo(this.getRouter(), "routed", () => {
202:             if (this.isMobileDrawerOpen) {
203:                 this.closeMobileDrawer();
204:             }
205:         });
206:     }
207: 
208:     /**
209:      * Set up the navbar config selector in the sidebar.
210:      * @private
211:      */
212:     setupNavbarConfigSelector() {
213:         if (!this.shouldShowConfigSelector()) {
214:             return;
215:         }
216: 
217:         const leftContainer = this.element.querySelector('.navbar-left-container');
218:         const tabs = leftContainer ? leftContainer.querySelector('.tabs') : null;
219: 
220:         if (!leftContainer || !tabs) {
221:             return;
222:         }
223: 
224:         let container = leftContainer.querySelector('.navbar-config-selector-container');
225: 
226:         if (!container) {
227:             container = document.createElement('div');
228:             container.className = 'navbar-config-selector-container';
229:             leftContainer.insertBefore(container, tabs);
230:         }
231: 
232:         const configList = this.getNavbarConfigList();
233:         const activeConfig = this.getActiveNavbarConfig();
234: 
235:         this.createView(
236:             'navbarConfigSelector',
237:             'global:views/site/navbar-config-selector',
238:             {
239:                 selector: '.navbar-config-selector-container',
240:                 configList: configList,
241:                 activeConfigId: activeConfig ? activeConfig.id : null,
242:             },
243:             (view) => {
244:                 view.render();
245: 
246:                 this.listenTo(view, 'switch', (id) => {
247:                     this.switchNavbarConfig(id);
248:                 });
249:             }
250:         );
251:     }
252: 
253:     /**
254:      * @private
255:      * @return {boolean}
256:      */
257:     shouldShowConfigSelector() {
258:         if (!this.isSide()) {
259:             return false;
260:         }
261: 
262:         const configList = this.getNavbarConfigList();
263: 
264:         return configList && configList.length > 1;
265:     }
266: 
267:     /**
268:      * Switch the active navbar config and persist to preferences.
269:      * @param {string} configId
270:      */
271:     async switchNavbarConfig(configId) {
272:         if (this._switchingConfig) {
273:             return;
274:         }
275: 
276:         this._switchingConfig = true;
277: 
278:         Espo.Ui.notify(' ... ');
279: 
280:         try {
281:             await Espo.Ajax.putRequest('Preferences/' + this.getUser().id, {
282:                 activeNavbarConfigId: configId,
283:             });
284: 
285:             this.getPreferences().set('activeNavbarConfigId', configId);
286:             this.getPreferences().trigger('update', ['activeNavbarConfigId']);
287: 
288:             this.setupTabDefsList();
289:             this.reRender();
290: 
291:             Espo.Ui.notify(false);
292:         } catch (e) {
293:             console.error('Error switching navbar config:', e);
294:             Espo.Ui.error(
295:                 this.getLanguage().translate('errorSavingPreference', 'messages', 'Global')
296:             );
297:         } finally {
298:             this._switchingConfig = false;
299:         }
300:     }
301: 
302:     // =========================================================================
303:     // Mobile Drawer Navigation (Linear.app-style)
304:     // =========================================================================
305: 
306:     /**
307:      * @private
308:      * @return {boolean}
309:      */
310:     isMobileScreen() {
311:         const smallScreenWidth =
312:             this.getThemeManager().getParam("screenWidthXs") || 768;
313: 
314:         return window.innerWidth < smallScreenWidth;
315:     }
316: 
317:     /**
318:      * Override toggleCollapsable to use drawer on mobile.
319:      */
320:     toggleCollapsable() {
321:         if (this.isMobileScreen()) {
322:             if (this.isMobileDrawerOpen) {
323:                 this.closeMobileDrawer();
324:             } else {
325:                 this.openMobileDrawer();
326:             }
327: 
328:             return;
329:         }
330: 
331:         super.toggleCollapsable();
332:     }
333: 
334:     /**
335:      * Open the mobile drawer.
336:      * @private
337:      */
338:     openMobileDrawer() {
339:         this.isMobileDrawerOpen = true;
340: 
341:         document.body.classList.add("mobile-drawer-open");
342: 
343:         this.$mobileDrawerBackdrop = $("<div>")
344:             .addClass("mobile-drawer-backdrop")
345:             .on("click", () => this.closeMobileDrawer())
346:             .appendTo(document.body);
347: 
348:         requestAnimationFrame(() => {
349:             this.$mobileDrawerBackdrop.addClass("visible");
350:         });
351:     }
352: 
353:     /**
354:      * Close the mobile drawer.
355:      * @private
356:      */
357:     closeMobileDrawer() {
358:         if (!this.isMobileDrawerOpen) {
359:             return;
360:         }
361: 
362:         this.isMobileDrawerOpen = false;
363: 
364:         document.body.classList.remove("mobile-drawer-open");
365: 
366:         if (this.$mobileDrawerBackdrop) {
367:             this.$mobileDrawerBackdrop.remove();
368:             this.$mobileDrawerBackdrop = null;
369:         }
370:     }
371: 
372:     /**
373:      * Override xsCollapse to close drawer instead of just hiding collapsable.
374:      */
375:     xsCollapse() {
376:         if (this.isMobileDrawerOpen) {
377:             this.closeMobileDrawer();
378: 
379:             return;
380:         }
381: 
382:         super.xsCollapse();
383:     }
384: 
385:     /**
386:      * Move notification bell and user menu into .navbar-header on mobile.
387:      * @private
388:      */
389:     setupMobileHeaderIcons() {
390:         if (!this.isMobileScreen()) {
391:             return;
392:         }
393: 
394:         if (this.element.querySelector(".navbar-header-right")) {
395:             return;
396:         }
397: 
398:         const navbarHeader = this.element.querySelector(".navbar-header");
399: 
400:         if (!navbarHeader) {
401:             return;
402:         }
403: 
404:         const search = this.element.querySelector(".global-search-container");
405:         const quickCreate = this.element.querySelector(
406:             ".quick-create-container",
407:         );
408:         const bell = this.element.querySelector(
409:             ".notifications-badge-container",
410:         );
411:         const menu = this.element.querySelector(".menu-container");
412: 
413:         const rightIcons = document.createElement("div");
414:         rightIcons.className = "navbar-header-right";
415: 
416:         if (search) {
417:             search.classList.remove("navbar-form");
418:             rightIcons.appendChild(search);
419:         }
420: 
421:         if (quickCreate) {
422:             quickCreate.classList.remove("hidden-xs");
423:             rightIcons.appendChild(quickCreate);
424:         }
425: 
426:         if (bell) rightIcons.appendChild(bell);
427:         if (menu) rightIcons.appendChild(menu);
428: 
429:         navbarHeader.appendChild(rightIcons);
430:     }
431: 
432:     /**
433:      * Load mobile drawer CSS stylesheet (idempotent).
434:      * @private
435:      */
436:     injectMobileDrawerStyles() {
437:         if (document.getElementById("mobile-drawer-styles")) {
438:             return;
439:         }
440: 
441:         const link = document.createElement("link");
442:         link.id = "mobile-drawer-styles";
443:         link.rel = "stylesheet";
444:         link.href = "client/custom/modules/global/css/mobile-drawer.css";
445: 
446:         document.head.appendChild(link);
447:     }
448: 
449:     /**
450:      * Load navbar config selector CSS stylesheet (idempotent).
451:      * @private
452:      */
453:     injectNavbarConfigSelectorStyles() {
454:         if (document.getElementById("navbar-config-selector-styles")) {
455:             return;
456:         }
457: 
458:         const link = document.createElement("link");
459:         link.id = "navbar-config-selector-styles";
460:         link.rel = "stylesheet";
461:         link.href = "client/custom/modules/global/css/navbar-config-selector.css";
462: 
463:         document.head.appendChild(link);
464:     }
465: }
466: 
467: export default CustomNavbarSiteView;
468: 

(End of file - total 468 lines)
</content>
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/helpers/site/tabs.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/src/helpers/site/tabs.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of EspoCRM.
3:  *
4:  * EspoCRM – Open Source CRM application.
5:  * Copyright (C) 2014-2026 EspoCRM, Inc.
6:  * Website: https://www.espocrm.com
7:  *
8:  * This program is free software: you can redistribute it and/or modify
9:  * it under the terms of the GNU Affero General Public License as published by
10:  * the Free Software Foundation, either version 3 of the License, or
11:  * (at your option) any later version.
12:  *
13:  * This program is distributed in the hope that it will be useful,
14:  * but WITHOUT ANY WARRANTY; without even the implied warranty of
15:  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
16:  * GNU Affero General Public License for more details.
17:  *
18:  * You should have received a copy of the GNU Affero General Public License
19:  * along with this program. If not, see <https://www.gnu.org/licenses/>.
20:  *
21:  * The interactive user interfaces in modified source and object code versions
22:  * of this program must display Appropriate Legal Notices, as required under
23:  * Section 5 of the GNU Affero General Public License version 3.
24:  *
25:  * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
26:  * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
27:  ************************************************************************/
28: 
29: export default class TabsHelper {
30: 
31:     /**
32:      * @param {import('models/settings').default} config
33:      * @param {import('models/preferences').default} preferences
34:      * @param {import('models/user').default} user
35:      * @param {import('acl-manager').default} acl
36:      * @param {import('metadata').default} metadata
37:      * @param {import('language').default} language
38:      */
39:     constructor(config, preferences, user, acl, metadata, language) {
40:         /** @private */
41:         this.config = config;
42:         /** @private */
43:         this.preferences = preferences;
44:         /** @private */
45:         this.user = user;
46:         /** @private */
47:         this.acl = acl;
48:         /** @private */
49:         this.metadata = metadata;
50:         /** @private */
51:         this.language = language;
52:     }
53: 
54:     /**
55:      * @typedef {Object} TabsHelper~item
56:      * @property {string} [url]
57:      * @property {string} [text]
58:      * @property {'url'|'divider'} [type]
59:      * @property {(TabsHelper~item|string)[]} [itemList]
60:      */
61: 
62:     /**
63:      * Get the tab list.
64:      *
65:      * @return {(TabsHelper~item|string)[]}
66:      */
67:     getTabList() {
68:         let tabList = this.preferences.get('useCustomTabList') && !this.preferences.get('addCustomTabs') ?
69:             this.preferences.get('tabList') :
70:             this.config.get('tabList');
71: 
72:         if (this.preferences.get('useCustomTabList') && this.preferences.get('addCustomTabs')) {
73:             tabList = [
74:                 ...tabList,
75:                 ...(this.preferences.get('tabList') || []),
76:             ];
77:         }
78: 
79:         return Espo.Utils.cloneDeep(tabList) || [];
80:     }
81: 
82:     /**
83:      * Is a tab a divider.
84:      *
85:      * @param {string|{type?: string}} item
86:      */
87:     isTabDivider(item) {
88:         return typeof item === 'object' && item.type === 'divider';
89:     }
90: 
91:     /**
92:      * Is a tab more-delimiter.
93:      *
94:      * @param {string|{type?: string}} item
95:      */
96:     isTabMoreDelimiter(item) {
97:         return item === '_delimiter_' || item === '_delimiter-ext_';
98:     }
99: 
100:     /**
101:      * Is a tab a URL.
102:      *
103:      * @param {string|{type?: string}} item
104:      */
105:     isTabUrl(item) {
106:         return typeof item === 'object' && item.type === 'url';
107:     }
108: 
109:     /**
110:      * Is a tab a group.
111:      *
112:      * @param {string|{type?: string}} item
113:      */
114:     isTabGroup(item) {
115:         if (!this.isTabDivider(item) && !this.isTabUrl(item) && typeof item === 'object') {
116:             return true;
117:         }
118: 
119:         return false;
120:     }
121: 
122:     /**
123:      * Is a tab a scope.
124:      *
125:      * @param {string|{type?: string}} item
126:      */
127:     isTabScope(item) {
128:         if (typeof item === 'object' || this.isTabMoreDelimiter(item) || item === 'Home') {
129:             return false;
130:         }
131: 
132:         return true;
133:     }
134: 
135:     /**
136:      * Get a translated tab label.
137:      *
138:      * @param {{text?: string}|string} item
139:      */
140:     getTranslatedTabLabel(item) {
141:         const translateLabel = label => {
142:             if (label.indexOf('$') === 0) {
143:                 return this.language.translate(label.slice(1), 'navbarTabs');
144:             }
145: 
146:             return label;
147:         };
148: 
149:         if (this.isTabDivider(item) || this.isTabUrl(item) || this.isTabUrl(item) || this.isTabGroup(item)) {
150:             if (item.text) {
151:                 return translateLabel(item.text);
152:             }
153: 
154:             return ''
155:         }
156: 
157:         if (item === 'Home') {
158:             return this.language.translate('Home');
159:         }
160: 
161:         if (typeof item === 'object') {
162:             return '';
163:         }
164: 
165:         return this.language.translate(item, 'scopeNamesPlural');
166:     }
167: 
168:     /**
169:      * Check tab access.
170:      *
171:      * @param {Record|string} item
172:      * @return {boolean}
173:      */
174:     checkTabAccess(item) {
175:         if (this.isTabUrl(item)) {
176:             if (item.onlyAdmin && !this.user.isAdmin()) {
177:                 return false;
178:             }
179: 
180:             if (!item.aclScope) {
181:                 return true;
182:             }
183: 
184:             return this.acl.check(item.aclScope);
185:         }
186: 
187:         if (item === 'Home' || this.isTabMoreDelimiter(item)) {
188:             return true;
189:         }
190: 
191:         /** @type {Record<string, {disabled?: boolean, acl?: boolean, tabAclPermission?: string}>} */
192:         const scopes = this.metadata.get('scopes') || {};
193: 
194:         if (!scopes[item]) {
195:             return false;
196:         }
197: 
198:         const defs = scopes[item] || {};
199: 
200:         if (defs.disabled) {
201:             return false;
202:         }
203: 
204:         if (defs.acl) {
205:             return this.acl.check(item);
206:         }
207: 
208:         if (defs.tabAclPermission) {
209:             const level = this.acl.getPermissionLevel(defs.tabAclPermission);
210: 
211:             return level && level !== 'no';
212:         }
213: 
214:         return true;
215:     }
216: }
217: 

(End of file - total 217 lines)
</content>
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/res/templates/site/navbar.tpl"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/res/templates/site/navbar.tpl</path>
<type>file</type>
<content>1: <div class="navbar navbar-inverse" role="navigation">
2:     <div class="navbar-header">
3:         <button type="button" class="navbar-toggle" data-action="toggleCollapsable">
4:             <span class="fas fa-bars"></span>
5:         </button>
6:         <div class="navbar-logo-container"
7:             ><a
8:                 class="navbar-brand nav-link"
9:                 href="#"
10:             ><img src="{{logoSrc}}" class="logo" alt="logo"></a></div>
11:         <a role="button" class="side-menu-button"><span class="fas fa-bars"></span></a>
12:     </div>
13: 
14:     <div class="navbar-collapse navbar-body">
15:         <div class="navbar-left-container">
16:             <ul class="nav navbar-nav tabs">
17:                 {{#each tabDefsList1}}
18:                     <li
19:                         data-name="{{name}}"
20:                         class="not-in-more tab{{#if isGroup}} tab-group dropdown{{/if}}{{#if isDivider}} tab-divider{{/if}}"
21:                     >
22:                         {{#if isDivider}}
23:                             <div class="{{aClassName}}"><span class="label-text">{{#if label}}{{label}}{{/if}}</span></div>
24:                         {{/if}}
25:                         {{#unless isDivider}}
26:                             <a
27:                                     {{#if link}}href="{{link}}"{{else}}role="button"{{/if}}
28:                                     class="{{aClassName}}"
29:                                     {{#if color}}style="border-color: {{color}}"{{/if}}
30:                                 {{#if isGroup}}
31:                                     id="nav-tab-group-{{name}}"
32:                                     data-toggle="dropdown"
33:                                 {{/if}}
34:                             >
35:                     <span class="short-label"{{#if label}} title="{{label}}"{{/if}}{{#if color}} style="color: {{color}}"{{/if}}>
36:                         {{#if iconClass}}
37:                             <span class="{{iconClass}}"></span>
38:                         {{else}}
39:                             {{#if colorIconClass}}
40:                                 <span class="{{colorIconClass}}" style="color: {{color}}"></span>
41:                             {{/if}}
42:                             <span class="short-label-text">{{shortLabel}}</span>
43:                         {{/if}}
44:                     </span>
45:                                 {{#if label}}
46:                                     <span class="full-label">{{label}}</span>
47:                                 {{/if}}
48:                                 {{#if html}}{{{html}}}{{/if}}
49: 
50:                                 {{#if isGroup}}
51:                                     <span class="fas fa-caret-right group-caret"></span>
52:                                 {{/if}}
53:                             </a>
54:                         {{/unless}}
55:                         {{#if isGroup}}
56:                             <ul class="dropdown-menu" role="menu" aria-labelledby="nav-tab-group-{{name}}">
57:                                 {{#each itemList}}
58:                                     {{#if isDivider}}
59:                                         <li class="divider"></li>
60:                                     {{else}}
61:                                         <li data-name="{{name}}" class="in-group tab">
62:                                             <a
63:                                                     {{#if link}}href="{{link}}"{{else}}role="button"{{/if}}
64:                                                     class="{{aClassName}}"
65:                                                 {{#if color}}
66:                                                     style="border-color: {{color}}"
67:                                                 {{/if}}
68:                                                 {{#if isGroup}}
69:                                                     id="nav-tab-group-{{name}}"
70:                                                     data-toggle="dropdown"
71:                                                 {{/if}}
72:                                             >
73:                             <span class="short-label"{{#if color}} style="color: {{color}}"{{/if}}>
74:                                 {{#if iconClass}}
75:                                     <span class="{{iconClass}}"></span>
76:                                 {{else}}
77:                                     {{#if colorIconClass}}
78:                                         <span class="{{colorIconClass}}" style="color: {{color}}"></span>
79:                                     {{/if}}
80:                                     <span class="short-label-text">&nbsp;</span>
81:                                 {{/if}}
82:                             </span>
83:                                                 <span class="full-label">{{label}}</span>
84:                                             </a>
85:                                         </li>
86:                                     {{/if}}
87:                                 {{/each}}
88:                             </ul>
89:                         {{/if}}
90:                     </li>
91:                 {{/each}}
92:                 <li class="dropdown more{{#unless tabDefsList2.length}} hidden{{/unless}}">
93:                     <a
94:                         id="nav-more-tabs-dropdown"
95:                         class="dropdown-toggle"
96:                         data-toggle="dropdown"
97:                         role="button"
98:                         tabindex="0"
99:                     ><span class="fas fa-ellipsis-h more-icon"></span></a>
100:                     <ul class="dropdown-menu more-dropdown-menu" role="menu" aria-labelledby="nav-more-tabs-dropdown">
101:                         {{#each tabDefsList2}}
102:                             <li
103:                                 data-name="{{name}}"
104:                                 class="in-more tab{{#if className}} {{className}}{{/if}}{{#if isGroup}} dropdown tab-group{{/if}}{{#if isDivider}} tab-divider{{/if}}"
105:                             >
106:                                 {{#if isDivider}}
107:                                     <div class="{{aClassName}}{{#unless label}} no-text{{/unless}}"><span class="label-text">{{#if label}}{{label}}{{/if}}</span></div>
108:                                 {{/if}}
109:                                 {{#unless isDivider}}
110:                                     <a
111:                                             {{#if link}}href="{{link}}"{{else}}role="button"{{/if}}
112:                                             tabindex="0"
113:                                             class="{{aClassName}}"
114:                                         {{#if color}} style="border-color: {{color}}"{{/if}}
115:                                         {{#if isGroup}}
116:                                             id="nav-tab-group-{{name}}"
117:                                             data-toggle="dropdown"
118:                                         {{/if}}
119:                                     >
120:                             <span class="short-label"{{#if color}} style="color: {{color}}"{{/if}}>
121:                                 {{#if iconClass}}
122:                                     <span class="{{iconClass}}"></span>
123:                                 {{else}}
124:                                     {{#if colorIconClass}}
125:                                         <span class="{{colorIconClass}}" style="color: {{color}}"></span>
126:                                     {{/if}}
127:                                     <span class="short-label-text">&nbsp;</span>
128:                                 {{/if}}
129:                             </span>
130:                                         {{#if label}}
131:                                             <span class="full-label">{{label}}</span>
132:                                         {{/if}}
133:                                         {{#if html}}{{{html}}}{{/if}}
134: 
135:                                         {{#if isGroup}}
136:                                             <span class="fas fa-caret-right group-caret"></span>
137:                                         {{/if}}
138:                                     </a>
139:                                 {{/unless}}
140:                                 {{#if isGroup}}
141:                                     <ul class="dropdown-menu" role="menu" aria-labelledby="nav-tab-group-{{name}}">
142:                                         {{#each itemList}}
143:                                             {{#if isDivider}}
144:                                                 <li class="divider"></li>
145:                                             {{else}}
146:                                                 <li data-name="{{name}}" class="in-group tab">
147:                                                     <a
148:                                                             {{#if link}}href="{{link}}"{{else}}role="button"{{/if}}
149:                                                             tabindex="0"
150:                                                             class="{{aClassName}}"
151:                                                         {{#if color}}
152:                                                             style="border-color: {{color}}"
153:                                                         {{/if}}
154:                                                         {{#if isGroup}}
155:                                                             id="nav-tab-group-{{name}}"
156:                                                             data-toggle="dropdown"
157:                                                         {{/if}}
158:                                                     >
159:                                     <span class="short-label"{{#if color}} style="color: {{color}}"{{/if}}>
160:                                         {{#if iconClass}}
161:                                             <span class="{{iconClass}}"></span>
162:                                         {{else}}
163:                                             {{#if colorIconClass}}
164:                                                 <span class="{{colorIconClass}}" style="color: {{color}}"></span>
165:                                             {{/if}}
166:                                             <span class="short-label-text">&nbsp;</span>
167:                                         {{/if}}
168:                                     </span>
169:                                                         <span class="full-label">{{label}}</span>
170:                                                     </a>
171:                                                 </li>
172:                                             {{/if}}
173:                                         {{/each}}
174:                                     </ul>
175:                                 {{/if}}
176:                             </li>
177:                         {{/each}}
178:                     </ul>
179:                 </li>
180:             </ul>
181:             <a class="minimizer hidden" role="button" tabindex="0">
182:                 <span class="fas fa-chevron-right right"></span>
183:                 <span class="fas fa-chevron-left left"></span>
184:             </a>
185:         </div>
186:         <div class="navbar-right-container">
187:             <ul class="nav navbar-nav navbar-right">
188:                 {{#each itemDataList}}
189:                     <li class="{{class}}" data-item="{{name}}">{{{var key ../this}}}</li>
190:                 {{/each}}
191:                 <li class="dropdown menu-container">
192:                     <a
193:                         id="nav-menu-dropdown"
194:                         class="dropdown-toggle"
195:                         data-toggle="dropdown"
196:                         role="button"
197:                         tabindex="0"
198:                         title="{{translate 'Menu'}}"
199:                     ><span class="fas fa-ellipsis-v icon"></span></a>
200:                     <ul class="dropdown-menu" role="menu" aria-labelledby="nav-menu-dropdown">
201:                         {{#each menuDataList}}
202:                             {{#unless divider}}
203:                                 <li><a
204:                                     {{#if name}}data-name="{{name}}"{{/if}}
205:                                     {{#if link}}href="{{link}}"{{else}}role="button"{{/if}}
206:                                     tabindex="0"
207:                                     class="nav-link{{#if handler}} action{{/if}}"
208:                                 >{{#if html}}{{{html}}}{{else}}{{label}}{{/if}}</a></li>
209:                             {{else}}
210:                                 <li class="divider"></li>
211:                             {{/unless}}
212:                         {{/each}}
213:                     </ul>
214:                 </li>
215:             </ul>
216:         </div>
217:     </div>
218: </div>
219: 

(End of file - total 219 lines)
</content>
```

---

## Assistant (Scope · glm-5-free · 13.8s)

_Thinking:_

Now I have a good understanding of the codebase. Let me also look at the core navbar's `setupTabDefsList` and `prepareTabItemDefs` methods to understand how tab filtering and preparation works:

Let me examine the core navbar's filtering and view creation patterns:

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/site/navbar.js",
  "offset": 1070,
  "limit": 400
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/site/navbar.js</path>
<type>file</type>
<content>1070:     /**
1071:      * @private
1072:      */
1073:     setupTabDefsList() {
1074:         /** @type {{url: string, name: string}[]} */
1075:         this.urlList = [];
1076: 
1077:         const allTabList = this.getTabList();
1078: 
1079:         this.tabList = allTabList.filter((item, i) => {
1080:             if (!item) {
1081:                 return false;
1082:             }
1083: 
1084:             if (typeof item !== 'object') {
1085:                 return this.tabsHelper.checkTabAccess(item);
1086:             }
1087: 
1088:             if (this.tabsHelper.isTabDivider(item)) {
1089:                 if (!this.isSide()) {
1090:                     return false;
1091:                 }
1092: 
1093:                 if (i === allTabList.length - 1) {
1094:                     return false;
1095:                 }
1096: 
1097:                 return true;
1098:             }
1099: 
1100:             if (this.tabsHelper.isTabUrl(item)) {
1101:                 return this.tabsHelper.checkTabAccess(item);
1102:             }
1103: 
1104:             /** @type {(Record|string)[]} */
1105:             let itemList = (item.itemList || []).filter(item => {
1106:                 if (this.tabsHelper.isTabDivider(item)) {
1107:                     return true;
1108:                 }
1109: 
1110:                 return this.tabsHelper.checkTabAccess(item);
1111:             });
1112: 
1113:             itemList = itemList.filter((item, i) => {
1114:                 if (!this.tabsHelper.isTabDivider(item)) {
1115:                     return true;
1116:                 }
1117: 
1118:                 const nextItem = itemList[i + 1];
1119: 
1120:                 if (!nextItem) {
1121:                     return true;
1122:                 }
1123: 
1124:                 if (this.tabsHelper.isTabDivider(nextItem)) {
1125:                     return false;
1126:                 }
1127: 
1128:                 return true;
1129:             });
1130: 
1131:             itemList = itemList.filter((item, i) => {
1132:                 if (!this.tabsHelper.isTabDivider(item)) {
1133:                     return true;
1134:                 }
1135: 
1136:                 if (i === 0 || i === itemList.length - 1) {
1137:                     return false;
1138:                 }
1139: 
1140:                 return true;
1141:             });
1142: 
1143:             item.itemList = itemList;
1144: 
1145:             return !!itemList.length;
1146:         });
1147: 
1148:         let moreIsMet = false;
1149: 
1150:         this.tabList = this.tabList.filter((item, i) => {
1151:             const nextItem = this.tabList[i + 1];
1152:             const prevItem = this.tabList[i - 1];
1153: 
1154:             if (this.tabsHelper.isTabMoreDelimiter(item)) {
1155:                 moreIsMet = true;
1156:             }
1157: 
1158:             if (!this.tabsHelper.isTabDivider(item)) {
1159:                 return true;
1160:             }
1161: 
1162:             if (!nextItem) {
1163:                 return true;
1164:             }
1165: 
1166:             if (this.tabsHelper.isTabDivider(nextItem)) {
1167:                 return false;
1168:             }
1169: 
1170:             if (this.tabsHelper.isTabDivider(prevItem) && this.tabsHelper.isTabMoreDelimiter(nextItem) && moreIsMet) {
1171:                 return false;
1172:             }
1173: 
1174:             return true;
1175:         });
1176: 
1177:         if (moreIsMet) {
1178:             let end = this.tabList.length;
1179: 
1180:             for (let i = this.tabList.length - 1; i >= 0; i --) {
1181:                 const item = this.tabList[i];
1182: 
1183:                 if (!this.tabsHelper.isTabDivider(item)) {
1184:                     break;
1185:                 }
1186: 
1187:                 end = this.tabList.length - 1;
1188:             }
1189: 
1190:             this.tabList = this.tabList.slice(0, end);
1191:         }
1192: 
1193:         const tabDefsList = [];
1194: 
1195:         const colorsDisabled =
1196:             this.getConfig().get('scopeColorsDisabled') ||
1197:             this.getConfig().get('tabColorsDisabled');
1198: 
1199:         const tabIconsDisabled = this.getConfig().get('tabIconsDisabled');
1200: 
1201:         const params = {
1202:             colorsDisabled: colorsDisabled,
1203:             tabIconsDisabled: tabIconsDisabled,
1204:         };
1205: 
1206:         const vars = {
1207:             moreIsMet: false,
1208:             isHidden: false,
1209:         };
1210: 
1211:         this.tabList.forEach((tab, i) => {
1212:             if (this.tabsHelper.isTabMoreDelimiter(tab)) {
1213:                 if (!vars.moreIsMet) {
1214:                     vars.moreIsMet = true;
1215: 
1216:                     return;
1217:                 }
1218: 
1219:                 if (i === this.tabList.length - 1) {
1220:                     return;
1221:                 }
1222: 
1223:                 vars.isHidden = true;
1224: 
1225:                 tabDefsList.push({
1226:                     name: 'show-more',
1227:                     isInMore: true,
1228:                     className: 'show-more',
1229:                     html: '<span class="fas fa-ellipsis-h more-icon"></span>',
1230:                 });
1231: 
1232:                 return;
1233:             }
1234: 
1235:             tabDefsList.push(
1236:                 this.prepareTabItemDefs(params, tab, i, vars)
1237:             );
1238:         });
1239: 
1240:         this.tabDefsList = tabDefsList;
1241:     }
1242: 
1243:     /**
1244:      * @private
1245:      * @param {{
1246:      *     colorsDisabled: boolean,
1247:      *     tabIconsDisabled: boolean,
1248:      * }} params
1249:      * @param {Record|string} tab
1250:      * @param {number} i
1251:      * @param {Object} vars
1252:      * @return {{
1253:      *     isAfterShowMore: boolean,
1254:      *     isDivider: boolean,
1255:      *     color: null,
1256:      *     link: string,
1257:      *     name: string,
1258:      *     isInMore: boolean,
1259:      *     shortLabel: string,
1260:      *     label: string,
1261:      *     isGroup: boolean,
1262:      *     aClassName: string,
1263:      *     iconClass: null
1264:      * }}
1265:      */
1266:     prepareTabItemDefs(params, tab, i, vars) {
1267:         let link;
1268: 
1269:         let iconClass = null;
1270:         let color = null;
1271:         let isGroup = false;
1272:         let isDivider = false;
1273:         let isUrl = false;
1274:         let name = tab;
1275:         let aClassName = 'nav-link';
1276: 
1277:         const label = this.tabsHelper.getTranslatedTabLabel(tab);
1278: 
1279:         if (tab === 'Home') {
1280:             link = '#';
1281:         } else if (this.tabsHelper.isTabDivider(tab)) {
1282:             isDivider = true;
1283: 
1284:             aClassName = 'nav-divider-text';
1285:             name = `divider-${i}`;
1286:         } else if (this.tabsHelper.isTabUrl(tab)) {
1287:             isUrl = true;
1288: 
1289:             name = `url-${i}`;
1290:             link = tab.url || '#';
1291:             color = tab.color;
1292:             iconClass = tab.iconClass;
1293: 
1294:             this.urlList.push({name: name, url: link});
1295:         } else if (this.tabsHelper.isTabGroup(tab)) {
1296:             isGroup = true;
1297: 
1298:             color = tab.color;
1299:             iconClass = tab.iconClass;
1300: 
1301:             name = `group-${i}`;
1302: 
1303:             link = null;
1304: 
1305:             aClassName = 'nav-link-group';
1306:         } else {
1307:             link = '#' + tab;
1308:         }
1309: 
1310:         const shortLabel = label.substring(0, 2);
1311: 
1312:         if (!params.colorsDisabled && !isGroup && !isDivider && !isUrl) {
1313:             color = this.getMetadata().get(['clientDefs', tab, 'color']);
1314:         }
1315: 
1316:         if (
1317:             color &&
1318:             !/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/.test(color)
1319:         ) {
1320:             color = null;
1321:         }
1322: 
1323:         if (!params.tabIconsDisabled && !isGroup && !isDivider && !isUrl) {
1324:             iconClass = this.getMetadata().get(['clientDefs', tab, 'iconClass'])
1325:         }
1326: 
1327:         const o = {
1328:             link: link,
1329:             label: label,
1330:             shortLabel: shortLabel,
1331:             name: name,
1332:             isInMore: vars.moreIsMet,
1333:             color: color,
1334:             iconClass: iconClass,
1335:             isAfterShowMore: vars.isHidden,
1336:             aClassName: aClassName,
1337:             isGroup: isGroup,
1338:             isDivider: isDivider,
1339:         };
1340: 
1341:         if (isGroup) {
1342:             o.itemList = tab.itemList.map((tab, i) => {
1343:                 return this.prepareTabItemDefs(params, tab, i, vars);
1344:             });
1345:         }
1346: 
1347:         if (vars.isHidden) {
1348:             o.className = 'after-show-more';
1349:         }
1350: 
1351:         if (color && !iconClass) {
1352:             o.colorIconClass = 'color-icon fas fa-square';
1353:         }
1354: 
1355:         return o;
1356:     }
1357: 
1358:     /**
1359:      * @typedef {Object} MenuDataItem
1360:      * @property {string} [link]
1361:      * @property {string} [name]
1362:      * @property {string} [html]
1363:      * @property {string} [handler]
1364:      * @property {string} [actionFunction]
1365:      * @property {true} [divider]
1366:      */
1367: 
1368:     /**
1369:      * @private
1370:      */
1371:     setupMenu() {
1372:         let avatarHtml = this.getHelper().getAvatarHtml(this.getUser().id, 'small', 20, 'avatar-link');
1373: 
1374:         if (avatarHtml) {
1375:             avatarHtml += ' ';
1376:         }
1377: 
1378:         /** @type {MenuDataItem[]} */
1379:         this.menuDataList = [
1380:             {
1381:                 link: `#User/view/${this.getUser().id}`,
1382:                 html: avatarHtml + this.getHelper().escapeString(this.getUser().get('name')),
1383:             },
1384:             {divider: true}
1385:         ];
1386: 
1387:         /**
1388:          * @type {Record<string, {
1389:          *     order?: number,
1390:          *     groupIndex?: number,
1391:          *     link?: string,
1392:          *     labelTranslation?: string,
1393:          *     configCheck?: string,
1394:          *     disabled:? boolean,
1395:          *     handler?: string,
1396:          *     actionFunction?: string,
1397:          *     accessDataList?: module:utils~AccessDefs[],
1398:          * }>} items
1399:          */
1400:         const items = this.getMetadata().get('app.clientNavbar.menuItems') || {};
1401: 
1402:         const nameList = Object.keys(items).sort((n1, n2) => {
1403:             const o1 = items[n1].order;
1404:             const o2 = items[n2].order;
1405: 
1406:             const g1 = items[n1].groupIndex;
1407:             const g2 = items[n2].groupIndex;
1408: 
1409:             if (g2 === g1) {
1410:                 return o1 - o2;
1411:             }
1412: 
1413:             return g1 - g2;
1414:         });
1415: 
1416:         let currentGroup = 0;
1417: 
1418:         for (const name of nameList) {
1419:             const item = items[name];
1420: 
1421:             if (item.groupIndex !== currentGroup) {
1422:                 currentGroup = item.groupIndex;
1423: 
1424:                 this.menuDataList.push({divider: true});
1425:             }
1426: 
1427:             if (item.disabled) {
1428:                 continue;
1429:             }
1430: 
1431:             if (
1432:                 item.configCheck &&
1433:                 !Espo.Utils.checkActionAvailability(this.getHelper(), item)
1434:             ) {
1435:                 continue;
1436:             }
1437: 
1438:             if (
1439:                 item.accessDataList &&
1440:                 !Espo.Utils.checkAccessDataList(item.accessDataList, this.getAcl(), this.getUser())
1441:             ) {
1442:                 continue;
1443:             }
1444: 
1445:             this.menuDataList.push({
1446:                 name: name,
1447:                 link: item.link,
1448:                 label: this.getLanguage().translatePath(item.labelTranslation),
1449:                 handler: item.handler,
1450:                 actionFunction: item.actionFunction,
1451:             });
1452:         }
1453:     }
1454: 
1455:     showMoreTabs() {
1456:         this.$el.find('.tab-group.tab.dropdown').removeClass('open');
1457: 
1458:         this.isMoreTabsShown = true;
1459:         this.$more.addClass('more-expanded');
1460:         this.adjustBodyMinHeight();
1461:         this.trigger('show-more-tabs');
1462:     }
1463: 
1464:     hideMoreTabs() {
1465:         if (!this.isMoreTabsShown) {
1466:             return;
1467:         }
1468: 
1469:         this.$more.removeClass('more-expanded');

(File has more lines. Use 'offset' parameter to read beyond line 1469)
</content>
```

**Tool: glob**

**Input:**
```json
{
  "path": "/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src",
  "pattern": "**/*"
}
```

**Output:**
```
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential/record/edit.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/controllers/credential.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/resource-calendar.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential/fields/config.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential-type/record/detail.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/controllers/credential-history.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/modals/navbar-config-field-add.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/preferences/fields/active-navbar-config.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/site/navbar.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential-history/record/detail.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/handlers/attachment/toggle-public.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential/record/list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential/record/detail.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/site/navbar-config-selector.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/credential/fields/metadata.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/controllers/credential-type.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/record/detail.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/record/list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/record/search-mobile.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/modals/mobile-filter.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/record/panels/default-side.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/record/panels/activities.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/opportunity/record/kanban-item.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/opportunity/record/kanban.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/opportunity/fields/opportunity-stage.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/admin-for-user/index.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/search.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/fields/name-with-icon.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/fields/entity-type.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/activities/record/list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/controllers/calendar.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/controllers/admin-for-user.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/controllers/activities.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/fields/complex-created.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/fields/link-multiple-with-icons.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/fields/followers.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/fields/relationship-list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/mode-buttons.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/modals/manage-users.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/modals/resource-options.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/color-picker-popover.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/calendar.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/calendar/calendar-page.js
```

---

## Assistant (Scope · glm-5-free · 28.3s)

_Thinking:_

Good. Now I can see that:
1. The custom navbar at `client/custom/modules/global/src/views/site/navbar.js` already exists and extends the core navbar
2. The core navbar has `setupTabDefsList()` and `prepareTabItemDefs()` methods that need to be considered

Let me also check if there's a tab-list field extension in the global module, and look at the modals patterns:

**Tool: glob**

**Input:**
```json
{
  "path": "/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global",
  "pattern": "**/settings/**/*.js"
}
```

**Output:**
```
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/views/settings/fields/navbar-config-list.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/lib/transpiled/src/views/settings/modals/edit-navbar-config.js
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/modals/navbar-config-field-add.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/src/views/modals/navbar-config-field-add.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of Monostax.
3:  *
4:  * Monostax – Custom EspoCRM extensions.
5:  * Copyright (C) 2025 Antonio Moura. All rights reserved.
6:  * Website: https://www.monostax.ai
7:  *
8:  * PROPRIETARY AND CONFIDENTIAL
9:  ************************************************************************/
10: 
11: import Modal from 'views/modal';
12: import Model from 'model';
13: 
14: class NavbarConfigFieldAddModalView extends Modal {
15: 
16:     className = 'dialog dialog-record'
17: 
18:     templateContent = `<div class="record no-side-margin">{{{record}}}</div>`
19: 
20:     setup() {
21:         super.setup();
22: 
23:         this.headerText = this.translate('Add Navbar Configuration', 'labels', 'Settings');
24: 
25:         this.buttonList.push({
26:             name: 'add',
27:             label: 'Add',
28:             style: 'danger',
29:         });
30: 
31:         this.buttonList.push({
32:             name: 'cancel',
33:             label: 'Cancel',
34:         });
35: 
36:         this.shortcutKeys = {
37:             'Control+Enter': () => this.actionAdd(),
38:         };
39: 
40:         const detailLayout = [
41:             {
42:                 rows: [
43:                     [
44:                         {
45:                             name: 'name',
46:                             labelText: this.translate('name', 'fields'),
47:                         },
48:                         {
49:                             name: 'iconClass',
50:                             labelText: this.translate('iconClass', 'fields', 'EntityManager'),
51:                         },
52:                     ],
53:                     [
54:                         {
55:                             name: 'color',
56:                             labelText: this.translate('color', 'fields', 'EntityManager'),
57:                         },
58:                         false,
59:                     ],
60:                 ],
61:             },
62:         ];
63: 
64:         const model = this.model = new Model();
65: 
66:         model.name = 'NavbarConfig';
67: 
68:         model.setDefs({
69:             fields: {
70:                 name: {
71:                     type: 'varchar',
72:                     required: true,
73:                 },
74:                 iconClass: {
75:                     type: 'base',
76:                     view: 'views/admin/entity-manager/fields/icon-class',
77:                 },
78:                 color: {
79:                     type: 'base',
80:                     view: 'views/fields/colorpicker',
81:                 },
82:             },
83:         });
84: 
85:         this.createView('record', 'views/record/edit-for-modal', {
86:             detailLayout: detailLayout,
87:             model: model,
88:             selector: '.record',
89:         });
90:     }
91: 
92:     actionAdd() {
93:         const recordView = this.getView('record');
94: 
95:         if (recordView.validate()) {
96:             return;
97:         }
98: 
99:         const data = recordView.fetch();
100: 
101:         this.trigger('add', data);
102:         this.close();
103:     }
104: }
105: 
106: export default NavbarConfigFieldAddModalView;
107: 

(End of file - total 107 lines)
</content>
```

**Tool: glob**

**Input:**
```json
{
  "path": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals",
  "pattern": "*.js"
}
```

**Output:**
```
/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/tab-list-field-add.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/edit-tab-divider.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/edit-tab-url.js
/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/edit-tab-group.js
```

---

## Assistant (Scope · glm-5-free · 20.9s)

_Thinking:_

Now let me check the core tab-list-field-add modal to understand the button pattern:

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/tab-list-field-add.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/tab-list-field-add.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of EspoCRM.
3:  *
4:  * EspoCRM – Open Source CRM application.
5:  * Copyright (C) 2014-2026 EspoCRM, Inc.
6:  * Website: https://www.espocrm.com
7:  *
8:  * This program is free software: you can redistribute it and/or modify
9:  * it under the terms of the GNU Affero General Public License as published by
10:  * the Free Software Foundation, either version 3 of the License, or
11:  * (at your option) any later version.
12:  *
13:  * This program is distributed in the hope that it will be useful,
14:  * but WITHOUT ANY WARRANTY; without even the implied warranty of
15:  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
16:  * GNU Affero General Public License for more details.
17:  *
18:  * You should have received a copy of the GNU Affero General Public License
19:  * along with this program. If not, see <https://www.gnu.org/licenses/>.
20:  *
21:  * The interactive user interfaces in modified source and object code versions
22:  * of this program must display Appropriate Legal Notices, as required under
23:  * Section 5 of the GNU Affero General Public License version 3.
24:  *
25:  * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
26:  * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
27:  ************************************************************************/
28: 
29: import ArrayFieldAddModalView from 'views/modals/array-field-add';
30: 
31: class TabListFieldAddSettingsModalView extends ArrayFieldAddModalView {
32: 
33:     setup() {
34:         super.setup();
35: 
36:         if (!this.options.noGroups) {
37:             this.buttonList.push({
38:                 name: 'addGroup',
39:                 text: this.translate('Group Tab', 'labels', 'Settings'),
40:                 onClick: () => this.actionAddGroup(),
41:                 position: 'right',
42:                 iconClass: 'fas fa-plus fa-sm',
43:             });
44:         }
45: 
46:         this.buttonList.push({
47:             name: 'addDivider',
48:             text: this.translate('Divider', 'labels', 'Settings'),
49:             onClick: () => this.actionAddDivider(),
50:             position: 'right',
51:             iconClass: 'fas fa-plus fa-sm',
52:         });
53: 
54:         this.addButton({
55:             name: 'addUrl',
56:             text: this.translate('URL', 'labels', 'Settings'),
57:             onClick: () => this.actionAddUrl(),
58:             position: 'right',
59:             iconClass: 'fas fa-plus fa-sm',
60:         });
61:     }
62: 
63:     actionAddGroup() {
64:         this.trigger('add', {
65:             type: 'group',
66:             text: this.translate('Group Tab', 'labels', 'Settings'),
67:             iconClass: null,
68:             color: null,
69:         });
70:     }
71: 
72:     actionAddDivider() {
73:         this.trigger('add', {
74:             type: 'divider',
75:             text: null,
76:         });
77:     }
78: 
79:     actionAddUrl() {
80:         this.trigger('add', {
81:             type: 'url',
82:             text: this.translate('URL', 'labels', 'Settings'),
83:             url: null,
84:             iconClass: null,
85:             color: null,
86:             aclScope: null,
87:             onlyAdmin: false,
88:         });
89:     }
90: }
91: 
92: // noinspection JSUnusedGlobalSymbols
93: export default TabListFieldAddSettingsModalView;
94: 

(End of file - total 94 lines)
</content>
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/fields/tab-list.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/fields/tab-list.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of EspoCRM.
3:  *
4:  * EspoCRM – Open Source CRM application.
5:  * Copyright (C) 2014-2026 EspoCRM, Inc.
6:  * Website: https://www.espocrm.com
7:  *
8:  * This program is free software: you can redistribute it and/or modify
9:  * it under the terms of the GNU Affero General Public License as published by
10:  * the Free Software Foundation, either version 3 of the License, or
11:  * (at your option) any later version.
12:  *
13:  * This program is distributed in the hope that it will be useful,
14:  * but WITHOUT ANY WARRANTY; without even the implied warranty of
15:  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
16:  * GNU Affero General Public License for more details.
17:  *
18:  * You should have received a copy of the GNU Affero General Public License
19:  * along with this program. If not, see <https://www.gnu.org/licenses/>.
20:  *
21:  * The interactive user interfaces in modified source and object code versions
22:  * of this program must display Appropriate Legal Notices, as required under
23:  * Section 5 of the GNU Affero General Public License version 3.
24:  *
25:  * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
26:  * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
27:  ************************************************************************/
28: 
29: import ArrayFieldView from 'views/fields/array';
30: 
31: class TabListFieldView extends ArrayFieldView {
32: 
33:     addItemModalView = 'views/settings/modals/tab-list-field-add'
34: 
35:     noGroups = false
36:     noDelimiters = false
37: 
38:     setup() {
39:         super.setup();
40: 
41:         this.selected.forEach(item => {
42:             if (item && typeof item === 'object') {
43:                 if (!item.id) {
44:                     item.id = this.generateItemId();
45:                 }
46:             }
47:         });
48: 
49:         this.addActionHandler('editGroup', (e, target) => {
50:             this.editGroup(target.dataset.value);
51:         })
52:     }
53: 
54:     generateItemId() {
55:         return Math.floor(Math.random() * 1000000 + 1).toString();
56:     }
57: 
58:     setupOptions() {
59:         this.params.options = Object.keys(this.getMetadata().get('scopes'))
60:             .filter(scope => {
61:                 if (this.getMetadata().get(`scopes.${scope}.disabled`)) {
62:                     return false;
63:                 }
64: 
65:                 if (!this.getAcl().checkScope(scope)) {
66:                     return false;
67:                 }
68: 
69:                 return this.getMetadata().get(`scopes.${scope}.tab`);
70:             })
71:             .sort((v1, v2) => {
72:                 return this.translate(v1, 'scopeNamesPlural')
73:                     .localeCompare(this.translate(v2, 'scopeNamesPlural'));
74:             });
75: 
76:         if (!this.noDelimiters) {
77:             this.params.options.push('_delimiter_');
78:             this.params.options.push('_delimiter-ext_');
79:         }
80: 
81:         this.translatedOptions = {};
82: 
83:         this.params.options.forEach(item => {
84:             this.translatedOptions[item] = this.translate(item, 'scopeNamesPlural');
85:         });
86: 
87:         this.translatedOptions['_delimiter_'] = '. . .';
88:         this.translatedOptions['_delimiter-ext_'] = '. . .';
89:     }
90: 
91:     addValue(value) {
92:         if (value && typeof value === 'object') {
93:             if (!value.id) {
94:                 value.id = this.generateItemId();
95:             }
96: 
97:             const html = this.getItemHtml(value);
98: 
99:             this.$list.append(html);
100:             this.selected.push(value);
101: 
102:             this.trigger('change');
103: 
104:             return;
105:         }
106: 
107:         super.addValue(value);
108:     }
109: 
110:     removeValue(value) {
111:         const index = this.getGroupIndexById(value);
112: 
113:         if (~index) {
114:             this.$list.children(`[data-value="${value}"]`).remove();
115: 
116:             this.selected.splice(index, 1);
117:             this.trigger('change');
118: 
119:             return;
120:         }
121: 
122:         super.removeValue(value);
123:     }
124: 
125:     getItemHtml(value) {
126:         if (value && typeof value === 'object') {
127:             return this.getGroupItemHtml(value);
128:         }
129: 
130:         return super.getItemHtml(value);
131:     }
132: 
133:     /**
134:      *
135:      * @param {{id: string, text?: string|null, type: string}} item
136:      * @return {string | jQuery}
137:      */
138:     getGroupItemHtml(item) {
139:         const labelElement = document.createElement('span');
140:         labelElement.textContent = item.text ?? '';
141: 
142:         let icon;
143: 
144:         if (item.type === 'group') {
145:             icon = document.createElement('span');
146:             icon.className = 'far fa-list-alt text-muted';
147:         }
148: 
149:         if (item.type === 'url') {
150:             icon = document.createElement('span');
151:             icon.className = 'fas fa-link fa-sm text-muted';
152:         }
153: 
154:         if (item.type === 'divider') {
155:             labelElement.classList.add('text-soft', 'text-italic');
156:         }
157: 
158:         const itemElement = document.createElement('span');
159:         itemElement.append(labelElement);
160:         itemElement.className = 'text';
161: 
162:         if (icon) {
163:             icon.style.marginRight = 'var(--4px)';
164: 
165:             itemElement.prepend(icon);
166:         }
167: 
168:         const div = document.createElement('div');
169:         div.className = 'list-group-item';
170:         div.dataset.value = item.id;
171:         div.style.cursor = 'default';
172: 
173:         div.append(
174:             (() => {
175:                 const span = document.createElement('span');
176:                 span.className = 'drag-handle';
177:                 span.append(
178:                     (() => {
179:                         const span = document.createElement('span');
180:                         span.className = 'fas fa-grip fa-sm';
181: 
182:                         return span;
183:                     })(),
184:                 );
185: 
186:                 return span;
187:             })(),
188:             (() => {
189:                 const span = document.createElement('span');
190:                 span.className = 'item-button'
191:                 span.append(
192:                     (() => {
193:                         const a = document.createElement('a');
194:                         a.role = 'button';
195:                         a.tabIndex = 0;
196:                         a.dataset.value = item.id;
197:                         a.dataset.action = 'editGroup';
198:                         a.append(
199:                             (() => {
200:                                 const span = document.createElement('span');
201:                                 span.className = 'fas fa-pencil-alt fa-sm';
202: 
203:                                 return span;
204:                             })(),
205:                         );
206: 
207:                         return a;
208:                     })(),
209:                 )
210: 
211:                 return span;
212:             })(),
213:             itemElement,
214:             (() => {
215:                 const a = document.createElement('a');
216:                 a.role = 'button';
217:                 a.tabIndex = 0;
218:                 a.classList.add('pull-right');
219:                 a.dataset.value = item.id;
220:                 a.dataset.action = 'removeValue';
221:                 a.append(
222:                     (() => {
223:                         const span = document.createElement('span');
224:                         span.className = 'fas fa-times'
225: 
226:                         return span;
227:                     })(),
228:                 );
229: 
230:                 return a;
231:             })(),
232:         );
233: 
234:         return div.outerHTML;
235: 
236: 
237:         return $('<div>')
238:             .addClass('list-group-item')
239:             .attr('data-value', item.id)
240:             .css('cursor', 'default')
241:             .append(
242:                 $('<a>')
243:                     .attr('role', 'button')
244:                     .attr('tabindex', '0')
245:                     .attr('data-value', item.id)
246:                     .attr('data-action', 'editGroup')
247:                     .css('margin-right', '7px')
248:                     .append(
249:                         $('<span>').addClass('fas fa-pencil-alt fa-sm')
250:                     ),
251:                 $item,
252:                 '&nbsp;',
253:                 $('<a>')
254:                     .addClass('pull-right')
255:                     .attr('role', 'button')
256:                     .attr('tabindex', '0')
257:                     .attr('data-value', item.id)
258:                     .attr('data-action', 'removeValue')
259:                     .append(
260:                         $('<span>').addClass('fas fa-times')
261:                     )
262:             )
263:             .get(0).outerHTML;
264:     }
265: 
266:     fetchFromDom() {
267:         const selected = [];
268: 
269:         this.$el.find('.list-group .list-group-item').each((i, el) => {
270:             const value = $(el).data('value').toString();
271:             const groupItem = this.getGroupValueById(value);
272: 
273:             if (groupItem) {
274:                 selected.push(groupItem);
275: 
276:                 return;
277:             }
278: 
279:             selected.push(value);
280:         });
281: 
282:         this.selected = selected;
283:     }
284: 
285:     getGroupIndexById(id) {
286:         for (let i = 0; i < this.selected.length; i++) {
287:             const item = this.selected[i];
288: 
289:             if (item && typeof item === 'object') {
290:                 if (item.id === id) {
291:                     return i;
292:                 }
293:             }
294:         }
295: 
296:         return -1;
297:     }
298: 
299:     getGroupValueById(id) {
300:         for (const item of this.selected) {
301:             if (item && typeof item === 'object') {
302:                 if (item.id === id) {
303:                     return item;
304:                 }
305:             }
306:         }
307: 
308:         return null;
309:     }
310: 
311:     editGroup(id) {
312:         const item = Espo.Utils.cloneDeep(this.getGroupValueById(id) || {});
313: 
314:         const index = this.getGroupIndexById(id);
315:         const tabList = Espo.Utils.cloneDeep(this.selected);
316: 
317:         const view = {
318:             divider: 'views/settings/modals/edit-tab-divider',
319:             url: 'views/settings/modals/edit-tab-url'
320:         }[item.type] ||  'views/settings/modals/edit-tab-group';
321: 
322:         this.createView('dialog', view, {
323:             itemData: item,
324:             parentType: this.model.entityType,
325:         }, view => {
326:             view.render();
327: 
328:             this.listenToOnce(view, 'apply', itemData => {
329:                 for (const a in itemData) {
330:                     tabList[index][a] = itemData[a];
331:                 }
332: 
333:                 this.model.set(this.name, tabList);
334: 
335:                 view.close();
336:             });
337:         });
338:     }
339: 
340:     getAddItemModalOptions() {
341:         return {
342:             ...super.getAddItemModalOptions(),
343:             noGroups: this.noGroups,
344:         };
345:     }
346: 
347:     getValueForDisplay() {
348:         const labels = this.translatedOptions || {};
349: 
350:         /** @var {string[]} */
351:         const list = this.selected.map(item => {
352:             if (typeof item !== 'string') {
353:                 return ' - ' + (item.text || '?');
354:             }
355: 
356:             return labels[item] || item;
357:         });
358: 
359:         return list.map(text => {
360:             return $('<div>')
361:                 .addClass('multi-enum-item-container')
362:                 .text(text)
363:                 .get(0)
364:                 .outerHTML
365:         }).join('');
366:     }
367: }
368: 
369: export default TabListFieldView;
370: 

(End of file - total 370 lines)
</content>
```

---

## Assistant (Scope · glm-5-free · 14.5s)

_Thinking:_

Now I have a good understanding of the codebase. Let me also check the edit-tab-group.js modal to understand the pattern:

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/edit-tab-group.js"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/settings/modals/edit-tab-group.js</path>
<type>file</type>
<content>1: /************************************************************************
2:  * This file is part of EspoCRM.
3:  *
4:  * EspoCRM – Open Source CRM application.
5:  * Copyright (C) 2014-2026 EspoCRM, Inc.
6:  * Website: https://www.espocrm.com
7:  *
8:  * This program is free software: you can redistribute it and/or modify
9:  * it under the terms of the GNU Affero General Public License as published by
10:  * the Free Software Foundation, either version 3 of the License, or
11:  * (at your option) any later version.
12:  *
13:  * This program is distributed in the hope that it will be useful,
14:  * but WITHOUT ANY WARRANTY; without even the implied warranty of
15:  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
16:  * GNU Affero General Public License for more details.
17:  *
18:  * You should have received a copy of the GNU Affero General Public License
19:  * along with this program. If not, see <https://www.gnu.org/licenses/>.
20:  *
21:  * The interactive user interfaces in modified source and object code versions
22:  * of this program must display Appropriate Legal Notices, as required under
23:  * Section 5 of the GNU Affero General Public License version 3.
24:  *
25:  * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
26:  * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
27:  ************************************************************************/
28: 
29: import Modal from 'views/modal';
30: import Model from 'model';
31: 
32: class SettingsEditTabGroupModalView extends Modal {
33: 
34:     className = 'dialog dialog-record'
35: 
36:     templateContent = `<div class="record no-side-margin">{{{record}}}</div>`
37: 
38:     setup() {
39:         super.setup();
40: 
41:         this.headerText = this.translate('Group Tab', 'labels', 'Settings');
42: 
43:         this.buttonList.push({
44:             name: 'apply',
45:             label: 'Apply',
46:             style: 'danger',
47:         });
48: 
49:         this.buttonList.push({
50:             name: 'cancel',
51:             label: 'Cancel',
52:         });
53: 
54:         this.shortcutKeys = {
55:             'Control+Enter': () => this.actionApply(),
56:         };
57: 
58:         const detailLayout = [
59:             {
60:                 rows: [
61:                     [
62:                         {
63:                             name: 'text',
64:                             labelText: this.options.parentType === 'Preferences' ?
65:                                 this.translate('label', 'tabFields', 'Preferences') :
66:                                 this.translate('label', 'fields', 'Admin'),
67:                         },
68:                         {
69:                             name: 'iconClass',
70:                             labelText: this.options.parentType === 'Preferences' ?
71:                                 this.translate('iconClass', 'tabFields', 'Preferences') :
72:                                 this.translate('iconClass', 'fields', 'EntityManager'),
73:                         },
74:                         {
75:                             name: 'color',
76:                             labelText: this.options.parentType === 'Preferences' ?
77:                                 this.translate('color', 'tabFields', 'Preferences') :
78:                                 this.translate('color', 'fields', 'EntityManager'),
79:                         },
80:                     ],
81:                     [
82:                         {
83:                             name: 'itemList',
84:                             labelText:this.options.parentType === 'Preferences' ?
85:                                 this.translate('tabList', 'fields', 'Preferences') :
86:                                 this.translate('tabList', 'fields', 'Settings'),
87:                         },
88:                         false
89:                     ]
90:                 ]
91:             }
92:         ];
93: 
94:         const model = this.model = new Model();
95: 
96:         model.name = 'GroupTab';
97:         model.set(this.options.itemData);
98: 
99:         model.setDefs({
100:             fields: {
101:                 text: {
102:                     type: 'varchar',
103:                 },
104:                 iconClass: {
105:                     type: 'base',
106:                     view: 'views/admin/entity-manager/fields/icon-class',
107:                 },
108:                 color: {
109:                     type: 'base',
110:                     view: 'views/fields/colorpicker',
111:                 },
112:                 itemList: {
113:                     type: 'array',
114:                     view: 'views/settings/fields/group-tab-list',
115:                 },
116:             },
117:         });
118: 
119:         this.createView('record', 'views/record/edit-for-modal', {
120:             detailLayout: detailLayout,
121:             model: model,
122:             selector: '.record',
123:         });
124:     }
125: 
126:     actionApply() {
127:         const recordView = /** @type {import('views/record/edit').default} */this.getView('record');
128: 
129:         if (recordView.validate()) {
130:             return;
131:         }
132: 
133:         const data = recordView.fetch();
134: 
135:         this.trigger('apply', data);
136:     }
137: }
138: 
139: export default SettingsEditTabGroupModalView;
140: 

(End of file - total 140 lines)
</content>
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json"
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json</path>
<type>file</type>
<content>1: {
2:     "fields": {
3:         "weight": "Weight"
4:     },
5:     "fieldTypes": {
6:         "weight": "Weight"
7:     },
8:     "options": {
9:         "weightUnit": {
10:             "kg": "Kilogram (kg)",
11:             "g": "Gram (g)",
12:             "mg": "Milligram (mg)",
13:             "lb": "Pound (lb)",
14:             "oz": "Ounce (oz)",
15:             "t": "Ton (t)"
16:         },
17:         "activityEntityType": {
18:             "Meeting": "Meeting",
19:             "Call": "Call",
20:             "Task": "Task"
21:         }
22:     },
23:     "tooltips": {
24:         "weightDecimal": "Weight decimal storage. Uses VARCHAR type for database storage to maintain precise values."
25:     },
26:     "messages": {
27:         "validUnit": "Unit must be valid",
28:         "errorSavingPreference": "Error saving preference."
29:     },
30:     "boolFilters": {
31:         "onlyMy": "Participating",
32:         "assignedToMe": "Assigned to Me"
33:     },
34:     "scopeNames": {
35:         "Activities": "Activities",
36:         "CAIPlaybook": "Playbook",
37:         "Funnel": "Funnel",
38:         "OpportunityStage": "Opportunity Stage",
39:         "SidenavConfig": "Sidenav Configuration"
40:     },
41:     "scopeNamesPlural": {
42:         "Activities": "Activities",
43:         "CAIPlaybook": "Playbooks",
44:         "Funnel": "Funnels",
45:         "OpportunityStage": "Opportunity Stages",
46:         "SidenavConfig": "Sidenav Configurations"
47:     },
48:     "navbarTabs": {
49:         "Lists": "Lists"
50:     },
51:     "navbarConfig": {
52:         "switchView": "Switch View",
53:         "defaultConfig": "Default",
54:         "customConfig": "Custom",
55:         "noConfigs": "No configurations available",
56:         "selectConfig": "Select Configuration"
57:     }
58: }
59: 

(End of file - total 59 lines)
</content>
```

---

## Assistant (Scope · glm-5-free · 12.2s)

_Thinking:_

Now I have all the information I need. Let me also check the existing templates folder in the global module:

**Tool: glob**

**Input:**
```json
{
  "path": "/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates",
  "pattern": "**/*.tpl"
}
```

**Output:**
```
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/calendar/resource-calendar.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/site/navbar-config-selector.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/record/detail.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/modals/mobile-filter.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/record/search-mobile.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/opportunity/record/kanban-item.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/opportunity/record/kanban.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/admin-for-user/index.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/calendar/mode-buttons.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/calendar/color-picker-popover.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/activities/fields/name-with-icon/list-link.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/activities/fields/name-with-icon/list.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/activities/fields/entity-type/list.tpl
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/res/templates/activities/fields/entity-type/detail.tpl
```

**Tool: glob**

**Input:**
```json
{
  "path": "/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global",
  "pattern": "**/css/*.css"
}
```

**Output:**
```
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/css/navbar-config-selector.css
/home/antl3x/repos/monostax/mono/components/crm/source/client/custom/modules/global/css/mobile-drawer.css
```

---

## Assistant (Scope · glm-5-free · 13.9s)

_Thinking:_

I now have a complete picture. Let me also check the record/panels/relationship.js to see the primaryFilter pattern that was referenced:

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/record/panels/relationship.js",
  "offset": 560,
  "limit": 50
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/src/views/record/panels/relationship.js</path>
<type>file</type>
<content>560:         const key = 'panelFilter' + this.model.entityType + '-' + (this.panelName || this.name);
561: 
562:         if (filter) {
563:             this.getStorage().set('state', key, filter);
564:         } else {
565:             this.getStorage().clear('state', key);
566:         }
567:     }
568: 
569:     /**
570:      * Set a filter.
571:      *
572:      * @param {string} filter A filter.
573:      */
574:     setFilter(filter) {
575:         this.filter = filter;
576:         this.collection.data.primaryFilter = null;
577: 
578:         if (filter && filter !== 'all') {
579:             this.collection.data.primaryFilter = filter;
580:         }
581:     }
582: 
583:     // noinspection JSUnusedGlobalSymbols
584:     /**
585:      * A `select-filter` action.
586:      *
587:      * @protected
588:      */
589:     actionSelectFilter(data) {
590:         const filter = data.name;
591:         let filterInternal = filter;
592: 
593:         if (filter === 'all') {
594:             filterInternal = false;
595:         }
596: 
597:         this.storeFilter(filterInternal);
598:         this.setFilter(filterInternal);
599: 
600:         this.filterList.forEach(item => {
601:             const $el = this.$el.closest('.panel').find('[data-name="' + item + '"] span');
602: 
603:             if (item === filter) {
604:                 $el.removeClass('hidden');
605:             } else {
606:                 $el.addClass('hidden');
607:             }
608:         });
609: 

(File has more lines. Use 'offset' parameter to read beyond line 609)
</content>
```

**Tool: read**

**Input:**
```json
{
  "filePath": "/home/antl3x/repos/monostax/mono/components/crm/source/client/src/helpers/record-modal.js",
  "offset": 300,
  "limit": 80
}
```

**Output:**
```
<path>/home/antl3x/repos/monostax/mono/components/crm/source/client/src/helpers/record-modal.js</path>
<type>file</type>
<content>300:      *   returnDispatchParams?: {
301:      *       controller: string,
302:      *       action: string|null,
303:      *       options: {isReturn?: boolean} & Record,
304:      *   },
305:      *   collapseDisabled?: boolean,
306:      * }} params
307:      * @return {Promise<import('views/modals/edit').default>}
308:      * @since 9.1.0
309:      */
310:     async showCreate(view, params) {
311:         const entityType = params.entityType;
312: 
313:         const viewName = this.metadata.get(`clientDefs.${entityType}.modalViews.edit`) ||
314:             'views/modals/edit';
315: 
316:         /** @type {module:views/modals/edit~options & module:views/modal~Options} */
317:         const options = {
318:             entityType: entityType,
319:             fullFormDisabled: params.fullFormDisabled,
320:             returnUrl: params.returnUrl || this.router.getCurrentUrl(),
321:             returnDispatchParams: params.returnDispatchParams,
322:             relate: params.relate,
323:             attributes: params.attributes,
324:             focusForCreate: params.focusForCreate,
325:             layoutName: params.layoutName,
326:             fullFormUrl: params.fullFormUrl,
327:             collapseDisabled: params.collapseDisabled,
328:         };
329: 
330:         if (params.rootUrl) {
331:             options.rootUrl = params.rootUrl;
332:         }
333: 
334:         Espo.Ui.notifyWait();
335: 
336:         const modalView = /** @type {import('views/modals/edit').default} */
337:             await view.createView('modal', viewName, options);
338: 
339:         // @todo Revise.
340:         modalView.listenToOnce(modalView, 'remove', () => view.clearView('modal'));
341: 
342:         if (params.afterSave) {
343:             modalView.listenTo(modalView, 'after:save', (model, /** Record */o) => {
344:                 params.afterSave(model, {...o});
345:             });
346:         }
347: 
348:         if (params.beforeRender) {
349:             params.beforeRender(modalView);
350:         }
351: 
352:         if (params.onClose) {
353:             view.listenToOnce(modalView, 'close', () => params.onClose());
354:         }
355: 
356:         await modalView.render();
357: 
358:         Espo.Ui.notify();
359: 
360:         return modalView;
361:     }
362: }
363: 
364: export default RecordModalHelper;
365: 

(End of file - total 365 lines)
</content>
```

---

## Assistant (Scope · glm-5-free · 175.8s)

_Thinking:_

Excellent! Now I have all the information I need to create a comprehensive scope document. Let me summarize the key decisions and the file manifest based on the audit findings:

## Key Audit Findings to Address:

### Critical Finding #1: View HTML Injection Strategy Is Architecturally Flawed
- The v2 scope proposed injecting HTML via `data()` override
- This is problematic because views render asynchronously
- **Remedy:** Use `afterRender()` DOM injection instead

### Warning #1: ACL Method Incorrect in Custom TabsHelper
- v2 used `this.acl.check(item.entityType, 'read')`
- Should be `this.acl.checkScope(item.entityType, 'read')`

### Warning #2: setupTabDefsList Virtual Folder Filtering Not Handled
- Virtual folders may be incorrectly filtered out
- Need to handle in the filtering loop

### Warning #3: Missing Directory Structure for Helpers
- Need to create `client/custom/modules/global/src/helpers/site/` directory

### Warning #4: Template Structure Mismatch with Existing Navbar Pattern
- The proposed template wraps in `<li>` but navbar expects specific structure
- Use afterRender DOM injection as suggested in Critical Finding #1

## Decisions:

| # | Decision | Alternatives Considered | Rationale |
|---|----------|------------------------|-----------|
| 1 | Store virtual folder config as new tab type `virtualFolder` in tabList | Separate entity, JSON field | Reuses existing tabList infrastructure, follows group/divider/url pattern |
| 2 | Use `collection.data.primaryFilter = filterName` for filter resolution | Manual where-clause | Matches EspoCRM collection patterns |
| 3 | Use collection factory pattern | Raw Ajax | Enables filter resolution, pagination, ACL |
| 4 | **Use `afterRender()` DOM injection instead of `data()` override** | `data()` override with view.getHtml() | Views render asynchronously; `data()` race condition; audit critical finding |
| 5 | Collapse state in localStorage | Server-side preference | Per-device, no server load, instant toggle |
| 6 | Use presetFilters from Preferences | New SavedFilter entity link | Uses existing EspoCRM system |
| 7 | `maxItems` property (default: 5) | Hard-coded/config setting | Per-folder customization |
| 8 | Quick create via RecordModal helper | Custom modal | Consistent UX, proven code |
| 9 | More options: Refresh, View all | Complex action set | MVP, extensible |
| 10 | `isLoading` state with spinner | No indicator | Better UX |
| 11 | Extend custom navbar `prepareTabItemDefs()` | Hook into core navbar | Custom navbar already extends core |
| 12 | Use `acl.checkScope()` for virtual folder access | `acl.check()` | Correct method for scope-level ACL |
| 13 | Create virtual folder views in `setupTabDefsList()` after super | In filtering loop | Cleaner separation |
| 14 | Placeholder element with data-virtual-folder-id for DOM injection | Direct HTML injection | Allows async view rendering |

Now let me create the comprehensive scope document.

Now I have all the information needed to create the comprehensive v3 scope document. Based on the audit findings, the key change is addressing the **Critical Finding #1: View HTML Injection Strategy** by moving from `data()` override to `afterRender()` DOM injection.

Let me create the scope file manifest:

---

# Virtual Folder Tab List Type - v3 Scope Document

> **Version**: 3.0  
> **Codebase Root**: `components/crm/source/`  
> **Status**: SCOPE MAPPED  
> **Uses**: Global module (`custom/Espo/Modules/Global` and `client/custom/modules/global`)
> **No Tests Required**: Per user decision

## Overview

This feature adds a new tab list item type called `virtualFolder` that acts as a sibling to `group`, `divider`, and `url` types. A virtual folder:
- Displays entity records dynamically fetched via a preset filter
- Shows a collapsible "divider-like" header with entity icon + custom label
- Lists record items as clickable links under the header
- Provides a quick-create button and more options menu on hover

### Key Changes from v2

Based on v2 audit findings, v3 addresses:
1. **CRITICAL: View HTML injection strategy** - Uses `afterRender()` DOM injection with placeholder elements instead of `data()` override (fixes async rendering race condition)
2. **ACL method correction** - Uses `acl.checkScope()` instead of `acl.check()` for scope-level access
3. **Template structure alignment** - Placeholder elements in navbar template, actual view rendered in afterRender
4. **Directory structure** - Explicitly notes `helpers/site/` directory creation

---

## Decisions

| # | Decision | Alternatives Considered | Rationale |
|---|----------|------------------------|-----------|
| 1 | Store virtual folder config as new tab type `virtualFolder` in tabList | Separate entity, JSON field | Reuses existing tabList infrastructure, follows group/divider/url pattern |
| 2 | Use `collection.data.primaryFilter = filterName` for filter resolution | Manual where-clause construction | Matches EspoCRM collection patterns; see `client/src/views/record/panels/relationship.js:574-581` |
| 3 | Use collection factory pattern instead of raw Ajax | Raw `Espo.Ajax.getRequest()` | Enables proper filter resolution, pagination support, ACL handling |
| 4 | **Use `afterRender()` DOM injection with placeholder elements** | `data()` override with `view.getHtml()` | Views render asynchronously in EspoCRM; `data()` is called during render before views finish; audit Critical Finding #1 |
| 5 | Collapse state stored in browser localStorage | Server-side preference | Per-device preference, no server load, instant toggle |
| 6 | Use presetFilters from Preferences for saved filter selection | Create new SavedFilter entity link | Uses existing EspoCRM preset filter system |
| 7 | Limit visible items with `maxItems` property (default: 5) | Hard-coded limit, config setting | Per-folder customization, reasonable default |
| 8 | Quick create uses existing `RecordModal` helper with explicit import | Custom modal | Consistent UX, reuses proven code; import: `import RecordModal from 'helpers/record-modal';` |
| 9 | More options menu includes: Refresh, View all in list | Complex action set | MVP feature set, extensible later |
| 10 | Add `isLoading` state with spinner | No loading indicator | Better UX during record fetch |
| 11 | **Use `acl.checkScope()` for virtual folder access check** | `acl.check()` | `acl.check()` is for record-level access; `checkScope()` is for scope-level access (audit Warning #1) |
| 12 | **Placeholder `<li>` with `data-virtual-folder-id` attribute** | Direct HTML replacement in data() | Allows async view rendering; view attaches to placeholder in afterRender |
| 13 | Refresh virtual folder records after quick-create | No auto-refresh | Better UX: newly created records appear immediately |

---

## Data Model Design

### Virtual Folder Item Structure (stored in tabList)

```json
{
  "type": "virtualFolder",
  "id": "vf-123456",
  "label": "My Open Tasks",
  "entityType": "Task",
  "filterName": "myOpen",
  "maxItems": 5,
  "iconClass": null,
  "color": null,
  "orderBy": "createdAt",
  "order": "desc"
}
```

### Field Definitions

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | Must be `"virtualFolder"` |
| `id` | string | Yes | Unique identifier for collapse state |
| `label` | string | No | Custom label (falls back to entity plural name) |
| `entityType` | string | Yes | Target entity scope (e.g., "Task", "Opportunity") |
| `filterName` | string | No | Preset filter name from clientDefs or user presetFilters |
| `maxItems` | int | No | Max items to display (default: 5, 0 = unlimited) |
| `iconClass` | string | No | Override entity icon (falls back to entity iconClass) |
| `color` | string | No | Border/accent color |
| `orderBy` | string | No | Field to order by (default: entity default) |
| `order` | string | No | "asc" or "desc" (default: entity default) |

---

## File Manifest

### Files to CREATE (ordered by complexity/risk, highest first)

#### 1. Virtual Folder Navbar Item View (CRITICAL)

**Path:** `client/custom/modules/global/src/views/site/navbar/virtual-folder.js`

**Purpose:** Main view component for rendering a virtual folder in the sidenav. Handles record fetching, collapse/expand, quick create, and more options.

**Key Implementation Details:**
- Extends `View` class
- `template` property: `global:site/navbar/virtual-folder`
- **Uses collection factory pattern** for filter resolution:
  ```javascript
  async fetchRecords() {
      this.isLoading = true;
      const collection = this.getCollectionFactory().create(this.entityType);
      collection.maxSize = this.maxItems || 5;
      if (this.filterName) {
          collection.data.primaryFilter = this.filterName;
      }
      if (this.orderBy) {
          collection.setOrder(this.orderBy, this.order || 'desc');
      }
      await collection.fetch();
      this.recordList = collection.models;
      this.totalCount = collection.total;
      this.isLoading = false;
  }
  ```
- Manages collapse state via `localStorage` key `navbar-vf-{id}-collapsed`
- Creates quick create view using `RecordModal` helper with **after:save refresh**:
  ```javascript
  import RecordModal from 'helpers/record-modal';
  // ...
  async actionQuickCreate() {
      const helper = new RecordModal();
      const modal = await helper.showCreate(this, { entityType: this.entityType });
      this.listenToOnce(modal, 'after:save', () => this.fetchRecords());
  }
  ```
- Manages `isLoading` state with spinner in template
- Action handlers: `toggleCollapse`, `quickCreate`, `refresh`, `viewAll`

**Reference Patterns:**
- Collection factory + primaryFilter: `client/src/views/record/panels/relationship.js:574-581`
- RecordModal.showCreate(): `client/src/helpers/record-modal.js:310-361`
- Icon retrieval: `client/custom/modules/global/src/views/activities/fields/name-with-icon.js:52-55`

---

#### 2. Custom Navbar Extension (CRITICAL)

**Path:** `client/custom/modules/global/src/views/site/navbar.js` (EDIT existing file)

**Purpose:** Add virtual folder handling to the existing custom navbar using **afterRender() DOM injection strategy**.

**Changes Required:**

1. **Add import for custom TabsHelper:**
   ```javascript
   import TabsHelper from 'global:helpers/site/tabs';
   ```

2. **Override `setup()` to use custom TabsHelper:**
   Replace/instantiate custom TabsHelper after super.setup():
   ```javascript
   this.tabsHelper = new TabsHelper(
       this.getConfig(),
       this.getPreferences(),
       this.getUser(),
       this.getAcl(),
       this.getMetadata(),
       this.getLanguage()
   );
   ```

3. **Override `prepareTabItemDefs()` to handle virtual folders:**
   Add after existing methods (before closing brace):
   ```javascript
   prepareTabItemDefs(params, tab, i, vars) {
       // Check for virtual folder FIRST (before calling super)
       if (this.tabsHelper.isTabVirtualFolder(tab)) {
           return this.prepareVirtualFolderDefs(params, tab, i, vars);
       }
       
       // Delegate to parent for all other types
       return super.prepareTabItemDefs(params, tab, i, vars);
   }
   
   prepareVirtualFolderDefs(params, tab, i, vars) {
       const iconClass = tab.iconClass || 
           this.getMetadata().get(['clientDefs', tab.entityType, 'iconClass']) || 
           'fas fa-folder';
       
       return {
           name: `vf-${tab.id}`,
           isInMore: vars.moreIsMet,
           isVirtualFolder: true,
           virtualFolderId: tab.id,
           // Placeholder - actual rendering happens in afterRender
           isDivider: true, // Render as placeholder <li>
           aClassName: 'nav-virtual-folder-placeholder',
           label: '', // Empty label for placeholder
           html: `<div data-virtual-folder-id="${tab.id}"></div>`,
       };
   }
   ```

4. **Override `setupTabDefsList()` to create virtual folder views AND handle filtering:**
   ```javascript
   setupTabDefsList() {
       // Filter virtual folders through ACL
       const allTabList = this.getTabList();
       
       this.tabList = allTabList.filter((item, i) => {
           if (this.tabsHelper.isTabVirtualFolder(item)) {
               return this.tabsHelper.checkTabAccess(item);
           }
           // ... delegate other filtering to super via original logic
           return true; // Will be filtered by super
       });
       
       // Call super with modified list (or replicate super's logic)
       super.setupTabDefsList();
       
       // Create virtual folder views after tabDefsList is built
       this.virtualFolderViewKeys = [];
       this.tabDefsList.forEach((defs) => {
           if (defs.isVirtualFolder) {
               this.createVirtualFolderView(defs);
           }
       });
   }
   
   createVirtualFolderView(defs) {
       const key = 'virtualFolder-' + defs.virtualFolderId;
       this.virtualFolderViewKeys.push(key);
       
       // View will attach to placeholder via selector
       this.createView(key, 'global:views/site/navbar/virtual-folder', {
           virtualFolderId: defs.virtualFolderId,
           config: defs.config,
       });
   }
   ```

5. **Override `afterRender()` to inject virtual folder views:**
   ```javascript
   afterRender() {
       super.afterRender();
       
       // Inject virtual folder views into placeholder elements
       this.virtualFolderViewKeys.forEach(key => {
           const view = this.getView(key);
           if (view && view.element) {
               const placeholder = this.element.querySelector(
                   `[data-virtual-folder-id="${view.virtualFolderId}"]`
               );
               if (placeholder) {
                   // Replace placeholder with view element
                   placeholder.replaceWith(view.element);
               }
           }
       });
       
       // Existing code...
       this.injectMobileDrawerStyles();
       this.injectNavbarConfigSelectorStyles();
       // Add virtual folder styles injection
       this.injectVirtualFolderStyles();
       // ... rest of existing afterRender
   }
   ```

6. **Add CSS injection method:**
   ```javascript
   injectVirtualFolderStyles() {
       if (document.getElementById('virtual-folder-styles')) {
           return;
       }
       const link = document.createElement('link');
       link.id = 'virtual-folder-styles';
       link.rel = 'stylesheet';
       link.href = 'client/custom/modules/global/css/virtual-folder.css';
       document.head.appendChild(link);
   }
   ```

---

#### 3. Custom Tabs Helper (HIGH)

**Path:** `client/custom/modules/global/src/helpers/site/tabs.js` (CREATE)

**Purpose:** Extend core TabsHelper to add virtual folder detection and correct ACL check.

**IMPORTANT:** Directory `client/custom/modules/global/src/helpers/site/` must be created.

**Implementation:**
```javascript
import CoreTabsHelper from 'helpers/site/tabs';

export default class TabsHelper extends CoreTabsHelper {
    /**
     * Is a tab a virtual folder.
     * @param {string|{type?: string}} item
     * @return {boolean}
     */
    isTabVirtualFolder(item) {
        return typeof item === 'object' && item.type === 'virtualFolder';
    }
    
    /**
     * Override checkTabAccess to handle virtual folders.
     * @param {Record|string} item
     * @return {boolean}
     */
    checkTabAccess(item) {
        if (this.isTabVirtualFolder(item)) {
            // Use checkScope for scope-level ACL (not check which is for records)
            return this.acl.checkScope(item.entityType, 'read');
        }
        
        return super.checkTabAccess(item);
    }
}
```

**Reference:** Core TabsHelper at `client/src/helpers/site/tabs.js`

---

#### 4. Virtual Folder Template (HIGH)

**Path:** `client/custom/modules/global/res/templates/site/navbar/virtual-folder.tpl`

**Purpose:** Template for rendering a virtual folder. Note: This view's element will be injected into the navbar via `afterRender()` DOM manipulation.

**Structure:**
```handlebars
<div class="virtual-folder{{#if isCollapsed}} collapsed{{/if}}" 
     data-name="vf-{{id}}"
     data-virtual-folder-id="{{id}}">
    <div class="virtual-folder-header" data-action="toggleVirtualFolder" data-id="{{id}}">
        <span class="virtual-folder-icon {{iconClass}}"{{#if color}} style="color: {{color}}"{{/if}}></span>
        <span class="virtual-folder-label">{{label}}</span>
        <span class="virtual-folder-caret fas fa-chevron-{{#if isCollapsed}}right{{else}}down{{/if}}"></span>
        <div class="virtual-folder-actions">
            <a class="action" data-action="quickCreate" title="{{translate 'Create' scope='Global'}}">
                <span class="fas fa-plus"></span>
            </a>
            <a class="dropdown-toggle" data-toggle="dropdown">
                <span class="fas fa-ellipsis-v"></span>
            </a>
            <ul class="dropdown-menu pull-right">
                <li><a data-action="refresh">{{translate 'Refresh' scope='Global'}}</a></li>
                <li><a data-action="viewAll">{{translate 'View All' scope='Global'}}</a></li>
            </ul>
        </div>
    </div>
    <ul class="virtual-folder-items{{#if isCollapsed}} hidden{{/if}}">
        {{#if isLoading}}
            <li class="virtual-folder-loading"><span class="fas fa-spinner fa-spin"></span></li>
        {{else}}
            {{#each recordList}}
                <li class="virtual-folder-item">
                    <a href="#{{../entityType}}/view/{{id}}" class="nav-link">
                        {{name}}
                    </a>
                </li>
            {{/each}}
            {{#unless recordList.length}}
                <li class="virtual-folder-empty">{{translate 'No records found' scope='Global'}}</li>
            {{/unless}}
            {{#if hasMore}}
                <li class="virtual-folder-more">
                    <a href="#{{entityType}}/list{{#if filterQuery}}?{{filterQuery}}{{/if}}">
                        {{translate 'View all' scope='Global'}} ({{totalCount}})
                    </a>
                </li>
            {{/if}}
        {{/if}}
    </ul>
</div>
```

**Note:** Template renders a `<div>` not `<li>` because it will be inserted into a placeholder within the navbar's `<li>` structure.

---

#### 5. Virtual Folder Styles (HIGH)

**Path:** `client/custom/modules/global/css/virtual-folder.css`

**Key Styles:**
```css
/* Virtual folder container - sits inside navbar <li> */
.virtual-folder {
    position: relative;
}

.virtual-folder-header {
    display: flex;
    align-items: center;
    padding: var(--8px) var(--12px);
    cursor: pointer;
    border-left: 3px solid transparent;
}

.virtual-folder-header:hover {
    background-color: var(--nav-tab-hover-bg);
}

.virtual-folder-icon {
    margin-right: var(--8px);
    font-size: var(--14px);
}

.virtual-folder-label {
    flex: 1;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.virtual-folder-caret {
    font-size: var(--10px);
    color: var(--text-muted-color);
    transition: transform 0.2s ease;
}

.virtual-folder:not(.collapsed) .virtual-folder-caret {
    transform: rotate(90deg);
}

.virtual-folder-actions {
    display: none;
    align-items: center;
    gap: var(--4px);
}

.virtual-folder-header:hover .virtual-folder-actions {
    display: flex;
}

.virtual-folder-actions a {
    padding: var(--4px);
    color: var(--text-muted-color);
}

.virtual-folder-actions a:hover {
    color: var(--text-color);
}

.virtual-folder-items {
    list-style: none;
    padding-left: var(--20px);
    margin: 0;
    max-height: 300px;
    overflow-y: auto;
}

.virtual-folder-items.hidden {
    display: none;
}

.virtual-folder-item {
    padding: var(--4px) var(--12px);
}

.virtual-folder-item a {
    display: block;
    color: var(--text-muted-color);
    font-size: var(--13px);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.virtual-folder-item a:hover {
    color: var(--text-color);
}

.virtual-folder-empty {
    padding: var(--8px) var(--12px);
    color: var(--text-soft-color);
    font-style: italic;
    font-size: var(--12px);
}

.virtual-folder-loading {
    padding: var(--8px) var(--12px);
    text-align: center;
    color: var(--text-muted-color);
}

.virtual-folder-more {
    padding: var(--4px) var(--12px);
    border-top: 1px solid var(--border-color);
}

.virtual-folder-more a {
    font-size: var(--12px);
    color: var(--link-color);
}

/* Hide placeholder class */
.nav-virtual-folder-placeholder {
    display: none;
}
```

---

#### 6. Edit Virtual Folder Modal (MEDIUM)

**Path:** `client/custom/modules/global/src/views/settings/modals/edit-tab-virtual-folder.js`

**Purpose:** Modal for configuring virtual folder properties.

**Structure:** Follows pattern from `client/src/views/settings/modals/edit-tab-group.js`

**Key Implementation:**
- Extends `Modal` class
- Uses `templateContent = '<div class="record no-side-margin">{{{record}}}</div>'`
- Creates inline `Model` with field definitions for: label, entityType, filterName, maxItems, iconClass, color, orderBy, order
- Uses `views/record/edit-for-modal` for form rendering
- Listen to `change:entityType` to re-render filterName field

**Reference:** `client/src/views/settings/modals/edit-tab-group.js` for modal pattern

---

#### 7. Entity Type Field (MEDIUM)

**Path:** `client/custom/modules/global/src/views/settings/fields/virtual-folder-entity.js`

**Purpose:** Enum field for selecting entity type with ACL filtering.

**Implementation:**
- Extends `EnumFieldView`
- `setupOptions()` loads scopes with `tab: true` and ACL read access
- Uses `this.getAcl().checkScope(scope, 'read')` for ACL check
- Sorts options alphabetically by translated plural name

**Reference:** `client/src/views/settings/fields/tab-list.js:65-68` for ACL pattern

---

#### 8. Filter Name Field (MEDIUM)

**Path:** `client/custom/modules/global/src/views/settings/fields/virtual-folder-filter.js`

**Purpose:** Enum field for selecting preset filter based on selected entity.

**Implementation:**
- Extends `EnumFieldView`
- `setupOptions()` merges system filters from clientDefs + user presetFilters from Preferences
- Includes "No Filter" (empty string) option
- Must re-call `setupOptions()` when entityType changes (triggered from modal's listenTo)

**Reference:** `client/src/views/record/search.js:303, 641-644` for presetFilters access

---

### Files to EDIT

#### 1. Custom Navbar View (CRITICAL)

**Path:** `client/custom/modules/global/src/views/site/navbar.js`

**Summary of Changes:**
- Add import: `import TabsHelper from 'global:helpers/site/tabs';`
- Update `setup()` to instantiate custom TabsHelper
- Add `prepareTabItemDefs()` override for virtual folder handling
- Add `prepareVirtualFolderDefs()` method
- Override `setupTabDefsList()` to filter and create virtual folder views
- Add `createVirtualFolderView()` method
- Add `virtualFolderViewKeys` property
- Update `afterRender()` to inject virtual folder views + call `injectVirtualFolderStyles()`
- Add `injectVirtualFolderStyles()` method

---

#### 2. Tab List Field View Extension (MEDIUM)

**Path:** `client/custom/modules/global/src/views/settings/fields/tab-list.js` (CREATE)

**Purpose:** Extend core tab-list field to handle virtual folder type in display and editing.

**Key Changes:**
- Override `getGroupItemHtml()` to handle `item.type === 'virtualFolder'`
- Add `getVirtualFolderItemHtml()` method with folder icon
- Update `editGroup()` to route virtual folders to `global:views/settings/modals/edit-tab-virtual-folder`

**Reference:** Core `client/src/views/settings/fields/tab-list.js`

---

#### 3. Tab List Field Add Modal Extension (LOW)

**Path:** `client/custom/modules/global/src/views/settings/modals/tab-list-field-add.js` (CREATE)

**Purpose:** Extend core modal to add "Add Virtual Folder" button.

**Key Changes:**
- Extend `TabListFieldAddSettingsModalView`
- In `setup()`, add button after existing buttons
- Add `actionAddVirtualFolder()` that triggers `add` event with default virtual folder object

**Reference:** `client/src/views/settings/modals/tab-list-field-add.js`

---

#### 4. Global Translations (LOW)

**Path:** `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json`

**Add to appropriate sections:**
```json
{
    "labels": {
        "Virtual Folder": "Virtual Folder",
        "Add Virtual Folder": "Add Virtual Folder",
        "Edit Virtual Folder": "Edit Virtual Folder",
        "No Filter": "No Filter",
        "No records found": "No records found"
    },
    "fields": {
        "entityType": "Entity",
        "filterName": "Filter",
        "maxItems": "Max Items",
        "orderBy": "Order By",
        "order": "Order"
    }
}
```

---

#### 5. Settings Translations (LOW)

**Path:** `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json` (CREATE if doesn't exist)

**Add:**
```json
{
    "labels": {
        "Virtual Folder": "Virtual Folder"
    }
}
```

---

### Files to CONSIDER

| File Path | Reason |
|-----------|--------|
| `application/Espo/Resources/metadata/entityDefs/SidenavConfig.json` | If SidenavConfig.tabList field needs custom view override to use global tab-list field |
| `client/custom/modules/global/src/views/modals/navbar-config-field-add.js` | May need update if virtual folders should be addable from SidenavConfig editing context (similar to tab-list-field-add pattern) |

---

### Related Files (for reference only, no changes needed)

| File Path | Pattern Reference |
|-----------|-------------------|
| `client/src/views/settings/modals/tab-list-field-add.js` | Pattern for adding addGroup/addDivider/addUrl buttons |
| `client/src/views/settings/modals/edit-tab-group.js` | Pattern for modal with dynamic field model |
| `client/src/views/settings/modals/edit-tab-url.js` | Another modal pattern |
| `client/src/views/settings/fields/tab-list.js` | Pattern for handling complex tab items, getGroupItemHtml, editGroup |
| `client/src/views/site/navbar/quick-create.js` | Quick create implementation with RecordModal |
| `client/src/helpers/site/tabs.js` | Tab type detection pattern (isTabDivider, isTabUrl, etc.), checkTabAccess pattern |
| `client/src/views/site/navbar.js` | Tab rendering: setupTabDefsList (lines 1073-1241), prepareTabItemDefs (lines 1266-1356) |
| `client/custom/modules/global/src/views/site/navbar.js` | Existing custom navbar with team configs |
| `client/custom/modules/global/src/views/activities/fields/name-with-icon.js` | Entity icon retrieval pattern |
| `client/src/views/record/search.js` | presetFilters handling pattern (lines 303, 641-644) |
| `client/src/views/record/panels/relationship.js` | Filter resolution with primaryFilter (lines 574-581) |
| `client/src/helpers/record-modal.js` | showCreate() pattern (lines 310-361) |
| `client/res/templates/site/navbar.tpl` | Template structure, {{{html}}} injection point |

---

## Implementation Order

### Phase 1: Core Infrastructure
1. Create directory: `client/custom/modules/global/src/helpers/site/`
2. Create `helpers/site/tabs.js` with `isTabVirtualFolder()` and correct `checkTabAccess()`
3. Add translations to Global.json

### Phase 2: Tab List Integration (Settings UI)
1. Create `views/settings/fields/virtual-folder-entity.js`
2. Create `views/settings/fields/virtual-folder-filter.js`
3. Create `views/settings/modals/edit-tab-virtual-folder.js`
4. Create `views/settings/fields/tab-list.js` extension
5. Create `views/settings/modals/tab-list-field-add.js` extension
6. Test virtual folder creation/editing in tab list settings

### Phase 3: Navbar Rendering
1. Create `views/site/navbar/virtual-folder.js` view
2. Create `res/templates/site/navbar/virtual-folder.tpl` template
3. Create `css/virtual-folder.css` styles
4. Edit `views/site/navbar.js` to:
   - Import custom TabsHelper
   - Override setup(), prepareTabItemDefs(), setupTabDefsList()
   - Add afterRender() DOM injection
   - Add CSS injection

### Phase 4: Testing & Polish
1. Test collapse/expand persistence (localStorage)
2. Test quick create with auto-refresh
3. Test refresh action
4. Test with various entity types and filters
5. Test ACL restrictions (user without entity access)
6. Test mobile/responsive behavior

---

## Error Handling

### Invalid Entity Type
- If `entityType` is disabled or ACL denied → hide virtual folder (handled in TabsHelper.checkTabAccess)
- Log warning in console

### Invalid Filter
- If `filterName` doesn't exist → fall back to no filter (empty string)
- Collection fetch will use entity default list view

### Fetch Error
- Show "Failed to load" message in virtual folder
- Provide retry via refresh action in more options

### No Records
- Show empty state message: "No records found"
- Still show quick create button

---

## Summary of File Count

| Category | Count |
|----------|-------|
| CREATE (views/helpers) | 6 files |
| CREATE (templates/css) | 2 files |
| EDIT | 3 files |
| CONSIDER | 2 files |
| Reference | 13 files |

---

*v3 Scope document - Addresses v2 audit Critical Finding #1 (view injection strategy) and Warnings #1-4. Uses afterRender() DOM injection with placeholder elements for async-safe view rendering.*

---

