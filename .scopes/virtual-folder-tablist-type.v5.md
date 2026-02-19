# Virtual Folder Tab List Type - v5 Scope Document

> **Version**: 5.0  
> **Codebase Root**: `components/crm/source/`  
> **Status**: AUDIT APPROVED - READY TO IMPLEMENT  
> **Audit**: v4 audit passed with Low risk, 0 critical findings  
> **Uses**: Global module (`custom/Espo/Modules/Global` and `client/custom/modules/global`)
> **No Tests Required**: Per user decision

## Overview

This feature adds a new tab list item type called `virtualFolder` that acts as a sibling to `group`, `divider`, and `url` types. A virtual folder:
- Displays entity records dynamically fetched via a preset filter
- Shows a collapsible "divider-like" header with entity icon + custom label
- Lists record items as clickable links under the header
- Provides a quick-create button and more options menu on hover

### Audit Status (v4)

**Verdict:** READY TO IMPLEMENT

The v4 scope was audited and approved. Key findings:
- **Risk Level:** Low
- **Critical Findings:** 0 (all v3 issues resolved)
- **Warnings:** 1 (TabsHelper timing - confirmed correct approach)
- **Suggestions:** 2 (ARIA accessibility - optional future enhancement)

---

## Decisions

| # | Decision | Alternatives Considered | Rationale |
|---|----------|------------------------|-----------|
| 1 | Store virtual folder config as new tab type `virtualFolder` in tabList | Separate entity, JSON field | Reuses existing tabList infrastructure, follows group/divider/url pattern |
| 2 | Use `collection.data.primaryFilter = filterName` for filter resolution | Manual where-clause construction | Matches EspoCRM collection patterns; see `client/src/views/record/panels/relationship.js:574-581` |
| 3 | Use collection factory pattern instead of raw Ajax | Raw `Espo.Ajax.getRequest()` | Enables proper filter resolution, pagination support, ACL handling |
| 4 | Use `afterRender()` DOM injection with placeholder elements | `data()` override with `view.getHtml()` | Views render asynchronously in EspoCRM; `data()` is called during render before views finish |
| 5 | Collapse state stored in browser localStorage | Server-side preference | Per-device preference, no server load, instant toggle |
| 6 | Use presetFilters from Preferences for saved filter selection | Create new SavedFilter entity link | Uses existing EspoCRM preset filter system |
| 7 | Limit visible items with `maxItems` property (default: 5) | Hard-coded limit, config setting | Per-folder customization, reasonable default |
| 8 | Quick create uses existing `RecordModal` helper with explicit import | Custom modal | Consistent UX, reuses proven code; import: `import RecordModal from 'helpers/record-modal';` |
| 9 | More options menu includes: Refresh, View all in list | Complex action set | MVP feature set, extensible later |
| 10 | Add `isLoading` state with spinner | No loading indicator | Better UX during record fetch |
| 11 | Use `acl.checkScope()` for virtual folder access check | `acl.check()` | `acl.check()` is for record-level access; `checkScope()` is for scope-level access |
| 12 | Placeholder `<li>` with `data-virtual-folder-id` attribute | Direct HTML replacement in data() | Allows async view rendering; view attaches to placeholder in afterRender |
| 13 | Refresh virtual folder records after quick-create | No auto-refresh | Better UX: newly created records appear immediately |
| 14 | **Pass full `tab` object as `config` property** | Store tab separately and retrieve by ID | Simpler data flow, no need for additional lookup in `createVirtualFolderView()` |
| 15 | **Render virtual folder as `<li class="virtual-folder">`** | Render as `<div>` inside placeholder | Maintains navbar `<ul class="nav navbar-nav tabs">` structure consistency |
| 16 | **SidenavConfig.tabList `jsonArray` field** | New field type | Already `jsonArray` type, supports any JSON-serializable object structure |
| 17 | **Replace core TabsHelper after `super.setup()`** | Override before super | Core navbar instantiates TabsHelper in its `setup()` at lines 429-436. Custom navbar calls `super.setup()` first, then replaces with custom TabsHelper - this is the correct approach |

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
- Receives full config via `this.options.config` containing the complete tab object
- Uses collection factory pattern for filter resolution:
  ```javascript
  async fetchRecords() {
      this.isLoading = true;
      this.hasError = false;
      try {
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
      } catch (error) {
          this.hasError = true;
          this.errorMessage = this.translate('Failed to load', 'messages', 'Global');
          console.error('Virtual folder fetch error:', error);
      } finally {
          this.isLoading = false;
      }
  }
  ```
- Manages collapse state via `localStorage` key `navbar-vf-{id}-collapsed`
- Creates quick create view using `RecordModal` helper with after:save refresh:
  ```javascript
  import RecordModal from 'helpers/record-modal';
  // ...
  async actionQuickCreate() {
      const helper = new RecordModal();
      const modal = await helper.showCreate(this, { entityType: this.entityType });
      this.listenToOnce(modal, 'after:save', () => this.fetchRecords());
  }
  ```
- View All action implementation:
  ```javascript
  actionViewAll() {
      let url = `#${this.entityType}/list`;
      if (this.filterName) {
          url += `?primaryFilter=${this.filterName}`;
      }
      this.getRouter().navigate(url, {trigger: true});
  }
  ```
- Manages `isLoading` and `hasError` states with spinner/error in template
- Action handlers: `toggleCollapse`, `quickCreate`, `refresh`, `viewAll`

**Reference Patterns:**
- Collection factory + primaryFilter: `client/src/views/record/panels/relationship.js:574-581`
- RecordModal.showCreate(): `client/src/helpers/record-modal.js:310-361`
- Icon retrieval: `client/custom/modules/global/src/views/activities/fields/name-with-icon.js:52-55`

---

#### 2. Custom Navbar Extension (CRITICAL)

**Path:** `client/custom/modules/global/src/views/site/navbar.js` (EDIT existing file)

**Purpose:** Add virtual folder handling to the existing custom navbar using afterRender() DOM injection strategy.

**Changes Required:**

1. **Add import for custom TabsHelper:**
   ```javascript
   import TabsHelper from 'global:helpers/site/tabs';
   ```

2. **Override `setup()` to use custom TabsHelper:**
   **IMPORTANT:** The core navbar instantiates `this.tabsHelper` at lines 429-436 within its `setup()` method. The custom navbar calls `super.setup()` first which creates the core TabsHelper, then replaces it with the custom TabsHelper - this is the correct approach.
   ```javascript
   setup() {
       super.setup();  // Core creates its TabsHelper here
       // Replace with custom TabsHelper that handles virtual folders
       this.tabsHelper = new TabsHelper(
           this.getConfig(),
           this.getPreferences(),
           this.getUser(),
           this.getAcl(),
           this.getMetadata(),
           this.getLanguage()
       );
   }
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
           config: tab,  // Pass the full tab config for use in createVirtualFolderView
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
       // defs.config contains the full tab configuration
       this.createView(key, 'global:views/site/navbar/virtual-folder', {
           virtualFolderId: defs.virtualFolderId,
           config: defs.config,  // Full tab config: entityType, filterName, maxItems, etc.
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

**IMPORTANT:** Directory `client/custom/modules/global/src/helpers/site/` already exists.

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

**Purpose:** Template for rendering a virtual folder. 

**Renders as `<li class="virtual-folder">` to maintain navbar structure.**

**Structure:**
```handlebars
<li class="virtual-folder{{#if isCollapsed}} collapsed{{/if}}" 
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
        {{else if hasError}}
            <li class="virtual-folder-error">
                <span class="text-danger">{{errorMessage}}</span>
                <a class="action" data-action="refresh">{{translate 'Retry' scope='Global'}}</a>
            </li>
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
                    <a data-action="viewAll">
                        {{translate 'View all' scope='Global'}} ({{totalCount}})
                    </a>
                </li>
            {{/if}}
        {{/if}}
    </ul>
</li>
```

**Future Enhancement (optional):** Consider adding ARIA attributes for accessibility:
- `aria-expanded` attribute on toggle button
- `aria-label` on action buttons
- `role="menu"` on dropdown menu

---

#### 5. Virtual Folder Styles (HIGH)

**Path:** `client/custom/modules/global/css/virtual-folder.css`

**Key Styles:**
```css
/* Virtual folder container - now an <li> element */
.virtual-folder {
    position: relative;
    list-style: none;
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

.virtual-folder-error {
    padding: var(--8px) var(--12px);
    text-align: center;
}

.virtual-folder-error a {
    display: block;
    margin-top: var(--4px);
    font-size: var(--12px);
}

.virtual-folder-more {
    padding: var(--4px) var(--12px);
    border-top: 1px solid var(--border-color);
}

.virtual-folder-more a {
    font-size: var(--12px);
    color: var(--link-color);
    cursor: pointer;
}

/* Hide placeholder class */
.nav-virtual-folder-placeholder {
    display: none;
}
```

---

#### 6. Tab List Field View Extension (MEDIUM)

**Path:** `client/custom/modules/global/src/views/settings/fields/tab-list.js` (CREATE)

**Purpose:** Extend core tab-list field to handle virtual folder type in display and editing.

**Key Implementation:**
```javascript
import TabListFieldView from 'views/settings/fields/tab-list';

export default class CustomTabListFieldView extends TabListFieldView {
    
    /**
     * Override getGroupItemHtml to handle virtual folder type.
     */
    getGroupItemHtml(item) {
        if (item.type === 'virtualFolder') {
            return this.getVirtualFolderItemHtml(item);
        }
        return super.getGroupItemHtml(item);
    }
    
    /**
     * Get HTML for virtual folder item display.
     */
    getVirtualFolderItemHtml(item) {
        const labelElement = document.createElement('span');
        labelElement.textContent = item.label || item.entityType || 'Virtual Folder';
        
        const icon = document.createElement('span');
        icon.className = 'fas fa-folder text-muted';
        icon.style.marginRight = 'var(--4px)';
        
        const itemElement = document.createElement('span');
        itemElement.className = 'text';
        itemElement.append(icon, labelElement);
        
        const div = document.createElement('div');
        div.className = 'list-group-item';
        div.dataset.value = item.id;
        div.style.cursor = 'default';
        
        // Drag handle, edit button, remove button - same pattern as parent
        // ... (same DOM construction as getGroupItemHtml in core)
        
        return div.outerHTML;
    }
    
    /**
     * Override editGroup() with virtual folder routing.
     */
    editGroup(id) {
        const item = Espo.Utils.cloneDeep(this.getGroupValueById(id) || {});
        const index = this.getGroupIndexById(id);
        const tabList = Espo.Utils.cloneDeep(this.selected);
        
        const view = {
            divider: 'views/settings/modals/edit-tab-divider',
            url: 'views/settings/modals/edit-tab-url',
            virtualFolder: 'global:views/settings/modals/edit-tab-virtual-folder',
        }[item.type] || 'views/settings/modals/edit-tab-group';
        
        this.createView('dialog', view, {
            itemData: item,
            parentType: this.model.entityType,
        }, view => {
            view.render();
            
            this.listenToOnce(view, 'apply', itemData => {
                for (const a in itemData) {
                    tabList[index][a] = itemData[a];
                }
                
                this.model.set(this.name, tabList);
                
                view.close();
            });
        });
    }
}
```

**Reference:** Core `client/src/views/settings/fields/tab-list.js`

---

#### 7. Edit Virtual Folder Modal (MEDIUM)

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

#### 8. Entity Type Field (MEDIUM)

**Path:** `client/custom/modules/global/src/views/settings/fields/virtual-folder-entity.js`

**Purpose:** Enum field for selecting entity type with ACL filtering.

**Implementation:**
- Extends `EnumFieldView`
- `setupOptions()` loads scopes with `tab: true` and ACL read access
- Uses `this.getAcl().checkScope(scope, 'read')` for ACL check
- Sorts options alphabetically by translated plural name

**Reference:** `client/src/views/settings/fields/tab-list.js:65-68` for ACL pattern

---

#### 9. Filter Name Field (MEDIUM)

**Path:** `client/custom/modules/global/src/views/settings/fields/virtual-folder-filter.js`

**Purpose:** Enum field for selecting preset filter based on selected entity.

**Implementation:**
- Extends `EnumFieldView`
- `setupOptions()` merges system filters from clientDefs + user presetFilters from Preferences
- Includes "No Filter" (empty string) option
- Must re-call `setupOptions()` when entityType changes (triggered from modal's listenTo)

**Reference:** `client/src/views/record/search.js:303, 641-644` for presetFilters access

---

#### 10. Tab List Field Add Modal Extension (LOW)

**Path:** `client/custom/modules/global/src/views/settings/modals/tab-list-field-add.js` (CREATE)

**Purpose:** Extend core modal to add "Add Virtual Folder" button.

**Key Implementation:**
```javascript
import TabListFieldAddSettingsModalView from 'views/settings/modals/tab-list-field-add';

export default class CustomTabListFieldAddModal extends TabListFieldAddSettingsModalView {

    setup() {
        super.setup();
        
        this.addButton({
            name: 'addVirtualFolder',
            text: this.translate('Virtual Folder', 'labels', 'Settings'),
            onClick: () => this.actionAddVirtualFolder(),
            position: 'right',
            iconClass: 'fas fa-plus fa-sm',
        });
    }
    
    /**
     * Create default virtual folder object with all required fields.
     */
    actionAddVirtualFolder() {
        this.trigger('add', {
            type: 'virtualFolder',
            id: 'vf-' + Math.floor(Math.random() * 1000000 + 1),
            label: null,               // Falls back to entity plural name
            entityType: null,          // Required - user must select
            filterName: null,          // Optional - no filter by default
            maxItems: 5,               // Default limit
            iconClass: null,           // Falls back to entity icon
            color: null,               // No color by default
            orderBy: null,             // Uses entity default
            order: 'desc',             // Default order
        });
    }
}
```

**Reference:** `client/src/views/settings/modals/tab-list-field-add.js`

---

### Files to EDIT

#### 1. Custom Navbar View (CRITICAL)

**Path:** `client/custom/modules/global/src/views/site/navbar.js`

**Summary of Changes:**
- Add import: `import TabsHelper from 'global:helpers/site/tabs';`
- Update `setup()` to instantiate custom TabsHelper (after `super.setup()`)
- Add `prepareTabItemDefs()` override for virtual folder handling
- Add `prepareVirtualFolderDefs()` method with config property
- Override `setupTabDefsList()` to filter and create virtual folder views
- Add `createVirtualFolderView()` method receiving config from defs
- Add `virtualFolderViewKeys` property
- Update `afterRender()` to inject virtual folder views + call `injectVirtualFolderStyles()`
- Add `injectVirtualFolderStyles()` method

---

#### 2. Global Translations (LOW)

**Path:** `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json`

**Add to appropriate sections:**
```json
{
    "labels": {
        "Virtual Folder": "Virtual Folder",
        "Add Virtual Folder": "Add Virtual Folder",
        "Edit Virtual Folder": "Edit Virtual Folder",
        "No Filter": "No Filter",
        "No records found": "No records found",
        "Failed to load": "Failed to load",
        "Retry": "Retry",
        "View All": "View All",
        "View all": "View all"
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

#### 3. Settings Translations (LOW)

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

| File Path | Reason | Resolution |
|-----------|--------|------------|
| `application/Espo/Resources/metadata/entityDefs/SidenavConfig.json` | Need to verify tabList field can store virtual folder objects | **VERIFIED:** Field is `jsonArray` type which supports any JSON-serializable object structure. No changes needed. |
| `client/custom/modules/global/src/views/modals/navbar-config-field-add.js` | May need update if virtual folders should be addable from SidenavConfig editing | Same pattern as tab-list-field-add, but for SidenavConfig context. Create if SidenavConfig editing uses different modal. |

---

### Related Files (for reference only, no changes needed)

| File Path | Pattern Reference |
|-----------|-------------------|
| `client/src/views/settings/modals/tab-list-field-add.js` | Pattern for adding addGroup/addDivider/addUrl buttons |
| `client/src/views/settings/modals/edit-tab-group.js` | Pattern for modal with dynamic field model |
| `client/src/views/settings/modals/edit-tab-url.js` | Another modal pattern |
| `client/src/views/settings/modals/edit-tab-divider.js` | Divider modal pattern |
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
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json` | Confirms `jsonArray` type for tabList field |

---

## Implementation Order

### Phase 1: Core Infrastructure
1. Create `helpers/site/tabs.js` with `isTabVirtualFolder()` and correct `checkTabAccess()` (directory exists)
2. Add translations to Global.json and Settings.json

### Phase 2: Tab List Integration (Settings UI)
1. Create `views/settings/fields/virtual-folder-entity.js`
2. Create `views/settings/fields/virtual-folder-filter.js`
3. Create `views/settings/modals/edit-tab-virtual-folder.js`
4. Create `views/settings/fields/tab-list.js` extension with explicit editGroup()
5. Create `views/settings/modals/tab-list-field-add.js` extension with explicit default object
6. Test virtual folder creation/editing in tab list settings

### Phase 3: Navbar Rendering
1. Create `views/site/navbar/virtual-folder.js` view with error handling and viewAll action
2. Create `res/templates/site/navbar/virtual-folder.tpl` template as `<li>` element
3. Create `css/virtual-folder.css` styles
4. Edit `views/site/navbar.js` to:
   - Import custom TabsHelper
   - Override setup(), prepareTabItemDefs(), setupTabDefsList()
   - Pass full tab config in prepareVirtualFolderDefs()
   - Add afterRender() DOM injection
   - Add CSS injection

### Phase 4: Testing & Polish
1. Test collapse/expand persistence (localStorage)
2. Test quick create with auto-refresh
3. Test refresh action
4. Test viewAll navigation with filter
5. Test error handling (simulate fetch failure)
6. Test with various entity types and filters
7. Test ACL restrictions (user without entity access)
8. Test mobile/responsive behavior

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

## Error Handling

### Invalid Entity Type
- If `entityType` is disabled or ACL denied → hide virtual folder (handled in TabsHelper.checkTabAccess)
- Log warning in console

### Invalid Filter
- If `filterName` doesn't exist → fall back to no filter (empty string)
- Collection fetch will use entity default list view

### Fetch Error
- Show "Failed to load" message in virtual folder with retry link
- Provide retry via refresh action in more options or inline retry link

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
| CONSIDER | 0 files (SidenavConfig verified as compatible) |
| Reference | 15 files |

---

*v5 Scope document - Audit approved, ready for implementation. All v3/v4 audit findings addressed. Includes implementation-time watchpoints from v4 audit.*
