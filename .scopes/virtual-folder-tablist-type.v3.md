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