# Virtual Folder Tab List Type - v2 Scope Document

> **Version**: 2.0  
> **Codebase Root**: `components/crm/source/`  
> **Status**: SCOPE MAPPED  
> **Uses**: Global module (`custom/Espo/Modules/Global` and `client/custom/modules/global`)

## Overview

This feature adds a new tab list item type called `virtualFolder` that acts as a sibling to `group`, `divider`, and `url` types. A virtual folder:
- Displays entity records dynamically fetched via a preset filter
- Shows a collapsible "divider-like" header with entity icon + custom label
- Lists record items as clickable links under the header
- Provides a quick-create button and more options menu on hover

### Key Changes from v1

Based on audit findings, v2 addresses:
1. **Filter resolution strategy** - Uses `primaryFilter` collection parameter (matches EspoCRM patterns)
2. **Navbar integration approach** - View injection with `itemDataList` pattern for cleaner separation
3. **Explicit filter merge logic** - Documented presetFilters access pattern
4. **Event handling specification** - Dynamic field dependency pattern with listenTo

---

## Decisions

| # | Decision | Alternatives Considered | Rationale |
|---|----------|------------------------|-----------|
| 1 | Store virtual folder config as new tab type `virtualFolder` in tabList | Separate entity, JSON field | Reuses existing tabList infrastructure, follows group/divider/url pattern |
| 2 | Use `collection.data.primaryFilter = filterName` for filter resolution | Manual where-clause construction | Matches EspoCRM collection patterns; see `client/src/views/record/panels/relationship.js:574-581` |
| 3 | Use collection factory pattern instead of raw Ajax | Raw `Espo.Ajax.getRequest()` | Enables proper filter resolution, pagination support, ACL handling |
| 4 | **View injection via `itemDataList` pattern** | Custom navbar template override | Cleaner separation; no need to maintain template fork; follows `navbar.tpl` lines 188-189 pattern |
| 5 | Collapse state stored in browser localStorage | Server-side preference | Per-device preference, no server load, instant toggle |
| 6 | Use presetFilters from Preferences for saved filter selection | Create new SavedFilter entity link | Uses existing EspoCRM preset filter system |
| 7 | Limit visible items with `maxItems` property (default: 5) | Hard-coded limit, config setting | Per-folder customization, reasonable default |
| 8 | Quick create uses existing `RecordModal` helper with explicit import | Custom modal | Consistent UX, reuses proven code; import: `import RecordModal from 'helpers/record-modal';` |
| 9 | More options menu includes: Refresh, View all in list | Complex action set | MVP feature set, extensible later |
| 10 | Add `isLoading` state with spinner | No loading indicator | Better UX during record fetch |
| 11 | **Extend custom navbar's `prepareTabItemDefs()` override** | Hook into core navbar | Custom navbar already extends core; virtual folder handling belongs there |

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
  }
  ```
- Manages collapse state via `localStorage` key `navbar-vf-{id}-collapsed`
- Creates quick create view using `RecordModal` helper:
  ```javascript
  import RecordModal from 'helpers/record-modal';
  // ...
  async actionQuickCreate() {
      const helper = new RecordModal();
      await helper.showCreate(this, { entityType: this.entityType });
  }
  ```
- Manages `isLoading` state with spinner in template

**Reference Patterns:**
- Collection factory: `client/src/views/record/panels/relationship.js` lines 574-581 for `setFilter()` / `primaryFilter` pattern
- Quick create: `client/src/views/site/navbar/quick-create.js` lines 117-121
- Icon retrieval: `client/custom/modules/global/src/views/activities/fields/name-with-icon.js` lines 52-55

---

#### 2. Custom Navbar Extension (CRITICAL)

**Path:** `client/custom/modules/global/src/views/site/navbar.js` (EDIT existing file)

**Purpose:** Add virtual folder handling to the existing custom navbar.

**Changes Required:**

1. **Add import for custom TabsHelper:**
   ```javascript
   import TabsHelper from 'global:helpers/site/tabs';
   ```

2. **Override `prepareTabItemDefs()` to handle virtual folders:**
   Add after the existing methods, before the closing brace:
   ```javascript
   prepareTabItemDefs(params, tab, i, vars) {
       // Check for virtual folder FIRST (before calling super)
       if (this.tabsHelper.isTabVirtualFolder(tab)) {
           return this.prepareVirtualFolderDefs(tab, i, vars);
       }
       
       // Delegate to parent for all other types
       return super.prepareTabItemDefs(params, tab, i, vars);
   }
   
   prepareVirtualFolderDefs(tab, i, vars) {
       const iconClass = tab.iconClass || 
           this.getMetadata().get(['clientDefs', tab.entityType, 'iconClass']) || 
           'fas fa-folder';
       
       return {
           name: `vf-${tab.id}`,
           isInMore: vars.moreIsMet,
           isVirtualFolder: true,
           virtualFolderId: tab.id,
           entityType: tab.entityType,
           label: tab.label || this.getLanguage().translate(tab.entityType, 'scopeNamesPlural'),
           iconClass: iconClass,
           color: tab.color,
           aClassName: 'nav-link-virtual-folder',
           config: tab, // Pass full config to view
       };
   }
   ```

3. **Override `setupTabDefsList()` to create virtual folder views:**
   After the super call completes, iterate and create views:
   ```javascript
   setupTabDefsList() {
       super.setupTabDefsList();
       
       // Create virtual folder views for items in tabDefsList
       this.tabDefsList.forEach((defs) => {
           if (defs.isVirtualFolder) {
               this.createVirtualFolderView(defs);
           }
       });
   }
   
   createVirtualFolderView(defs) {
       const key = 'virtualFolder-' + defs.virtualFolderId;
       this.createView(key, 'global:views/site/navbar/virtual-folder', {
           selector: `[data-virtual-folder-id="${defs.virtualFolderId}"]`,
           virtualFolderConfig: defs.config,
       });
   }
   ```

4. **Override `data()` to inject virtual folder HTML:**
   ```javascript
   data() {
       const baseData = super.data();
       
       // Inject virtual folder HTML into tabDefsList items
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

---

#### 3. Custom Tabs Helper (HIGH)

**Path:** `client/custom/modules/global/src/helpers/site/tabs.js` (CREATE)

**Purpose:** Extend core TabsHelper to add virtual folder detection.

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
            // Check ACL on entity type
            return this.acl.check(item.entityType, 'read');
        }
        
        return super.checkTabAccess(item);
    }
}
```

**Update custom navbar to use this helper:**
In `client/custom/modules/global/src/views/site/navbar.js`, change:
```javascript
// In setup(), replace:
this.tabsHelper = new TabsHelper(
    this.getConfig(),
    this.getPreferences(),
    this.getUser(),
    this.getAcl(),
    this.getMetadata(),
    this.getLanguage()
);
```

---

#### 4. Virtual Folder Template (HIGH)

**Path:** `client/custom/modules/global/res/templates/site/navbar/virtual-folder.tpl`

**Structure:**
```handlebars
<li class="tab tab-virtual-folder{{#if isCollapsed}} collapsed{{/if}}" 
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
</li>
```

---

#### 5. Virtual Folder Styles (HIGH)

**Path:** `client/custom/modules/global/css/virtual-folder.css`

**Key Styles:**
```css
.tab-virtual-folder {
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

.tab-virtual-folder.collapsed .virtual-folder-caret {
    transform: rotate(0deg);
}

.tab-virtual-folder:not(.collapsed) .virtual-folder-caret {
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
```

**Also update:**
`client/custom/modules/global/src/views/site/navbar.js` - Add CSS injection in `afterRender()`:
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

#### 6. Edit Virtual Folder Modal (MEDIUM)

**Path:** `client/custom/modules/global/src/views/settings/modals/edit-tab-virtual-folder.js`

**Purpose:** Modal for configuring virtual folder properties.

**Structure:** Follows pattern from `client/src/views/settings/modals/edit-tab-group.js`

**Implementation:**
```javascript
import Modal from 'views/modal';
import Model from 'model';

class EditTabVirtualFolderModalView extends Modal {

    className = 'dialog dialog-record'
    
    templateContent = `<div class="record no-side-margin">{{{record}}}</div>`
    
    setup() {
        super.setup();
        
        this.headerText = this.translate('Virtual Folder', 'labels', 'Settings');
        
        this.buttonList.push({
            name: 'apply',
            label: 'Apply',
            style: 'danger',
        });
        
        this.buttonList.push({
            name: 'cancel',
            label: 'Cancel',
        });
        
        this.shortcutKeys = {
            'Control+Enter': () => this.actionApply(),
        };
        
        const detailLayout = [
            {
                rows: [
                    [
                        {
                            name: 'label',
                            labelText: this.translate('label', 'fields', 'Admin'),
                        },
                        {
                            name: 'entityType',
                            labelText: this.translate('entityType', 'fields', 'Global'),
                            view: 'global:views/settings/fields/virtual-folder-entity',
                        },
                    ],
                    [
                        {
                            name: 'filterName',
                            labelText: this.translate('filterName', 'fields', 'Global'),
                            view: 'global:views/settings/fields/virtual-folder-filter',
                        },
                        {
                            name: 'maxItems',
                            labelText: this.translate('maxItems', 'fields', 'Global'),
                        },
                    ],
                    [
                        {
                            name: 'iconClass',
                            labelText: this.translate('iconClass', 'fields', 'EntityManager'),
                            view: 'views/admin/entity-manager/fields/icon-class',
                        },
                        {
                            name: 'color',
                            labelText: this.translate('color', 'fields', 'EntityManager'),
                            view: 'views/fields/colorpicker',
                        },
                    ],
                    [
                        {
                            name: 'orderBy',
                            labelText: this.translate('orderBy', 'fields', 'Global'),
                        },
                        {
                            name: 'order',
                            labelText: this.translate('order', 'fields', 'Global'),
                        },
                    ],
                ],
            },
        ];
        
        const model = this.model = new Model();
        
        model.name = 'VirtualFolder';
        model.set(this.options.itemData);
        
        model.setDefs({
            fields: {
                label: { type: 'varchar' },
                entityType: { 
                    type: 'enum',
                    required: true,
                },
                filterName: { type: 'enum' },
                maxItems: { 
                    type: 'int',
                    default: 5,
                },
                iconClass: { type: 'base' },
                color: { type: 'base' },
                orderBy: { type: 'enum' },
                order: { 
                    type: 'enum',
                    options: ['asc', 'desc'],
                    default: 'desc',
                },
            },
        });
        
        this.createView('record', 'views/record/edit-for-modal', {
            detailLayout: detailLayout,
            model: model,
            selector: '.record',
        });
        
        // Listen for entityType changes to reload filter options
        this.listenTo(model, 'change:entityType', () => {
            const filterField = this.getView('record')?.getFieldView('filterName');
            if (filterField) {
                filterField.reRender();
            }
        });
    }
    
    actionApply() {
        const recordView = this.getView('record');
        
        if (recordView.validate()) {
            return;
        }
        
        const data = recordView.fetch();
        this.trigger('apply', data);
    }
}

export default EditTabVirtualFolderModalView;
```

**Reference:** `client/src/views/settings/modals/edit-tab-group.js`

---

#### 7. Entity Type Field (MEDIUM)

**Path:** `client/custom/modules/global/src/views/settings/fields/virtual-folder-entity.js`

**Purpose:** Field for selecting entity type.

**Implementation:**
```javascript
import EnumFieldView from 'views/fields/enum';

class VirtualFolderEntityFieldView extends EnumFieldView {
    
    setupOptions() {
        // Load scopes with tab: true and ACL read access
        const scopes = this.getMetadata().get('scopes') || {};
        
        this.params.options = Object.keys(scopes)
            .filter(scope => {
                if (scopes[scope].disabled) {
                    return false;
                }
                
                // ACL check with read access (not just check)
                if (!this.getAcl().checkScope(scope, 'read')) {
                    return false;
                }
                
                return scopes[scope].tab;
            })
            .sort((v1, v2) => {
                return this.translate(v1, 'scopeNamesPlural')
                    .localeCompare(this.translate(v2, 'scopeNamesPlural'));
            });
        
        this.translatedOptions = {};
        this.params.options.forEach(scope => {
            this.translatedOptions[scope] = this.translate(scope, 'scopeNamesPlural');
        });
    }
}

export default VirtualFolderEntityFieldView;
```

**Reference:** `client/src/views/settings/fields/tab-list.js` lines 65-68 for ACL pattern

---

#### 8. Filter Name Field (MEDIUM)

**Path:** `client/custom/modules/global/src/views/settings/fields/virtual-folder-filter.js`

**Purpose:** Field for selecting preset filter based on selected entity.

**Implementation:**
```javascript
import EnumFieldView from 'views/fields/enum';

class VirtualFolderFilterFieldView extends EnumFieldView {
    
    setupOptions() {
        const entityType = this.model.get('entityType');
        
        if (!entityType) {
            this.params.options = [''];
            this.translatedOptions = { '': '--' };
            return;
        }
        
        // System preset filters from clientDefs
        const systemFilters = this.getMetadata()
            .get(['clientDefs', entityType, 'filterList']) || [];
        
        // User preset filters from Preferences
        const presetFilters = this.getPreferences().get('presetFilters') || {};
        const userFilters = presetFilters[entityType] || [];
        
        // Merge and dedupe
        const allFilters = new Map();
        
        // Add "no filter" option
        allFilters.set('', this.translate('No Filter', 'labels', 'Global'));
        
        // Add system filters
        systemFilters.forEach(filter => {
            const name = typeof filter === 'string' ? filter : filter.name;
            const label = typeof filter === 'string' 
                ? this.translate(name, 'presetFilters', entityType)
                : (filter.label || this.translate(name, 'presetFilters', entityType));
            allFilters.set(name, label);
        });
        
        // Add user filters
        userFilters.forEach(filter => {
            if (!allFilters.has(filter.name)) {
                allFilters.set(filter.name, filter.name);
            }
        });
        
        this.params.options = [''].concat([...allFilters.keys()].filter(k => k !== ''));
        this.translatedOptions = Object.fromEntries(allFilters);
    }
}

export default VirtualFolderFilterFieldView;
```

**Reference:** `client/src/views/record/search.js` lines 303, 641-644 for presetFilters access pattern

---

### Files to EDIT

#### 1. Custom Navbar View (CRITICAL)

**Path:** `client/custom/modules/global/src/views/site/navbar.js`

**Changes:**
- Add import for custom TabsHelper: `import TabsHelper from 'global:helpers/site/tabs';`
- Update `setup()` to instantiate custom TabsHelper instead of core
- Override `prepareTabItemDefs()` to handle virtual folders
- Add `prepareVirtualFolderDefs()` method
- Override `setupTabDefsList()` to create virtual folder views
- Add `createVirtualFolderView()` method
- Add `injectVirtualFolderStyles()` method (call in `afterRender()`)
- Override `data()` to inject virtual folder HTML

**Pattern:** See detailed implementation in CREATE section above.

---

#### 2. Tab List Field View Extension (MEDIUM)

**Path:** `client/custom/modules/global/src/views/settings/fields/tab-list.js` (CREATE if doesn't exist, or find existing)

**Purpose:** Extend core tab-list field to handle virtual folder type.

**Implementation:**
```javascript
import CoreTabListFieldView from 'views/settings/fields/tab-list';

class TabListFieldView extends CoreTabListFieldView {
    
    getGroupItemHtml(item) {
        // Handle virtual folder type
        if (item.type === 'virtualFolder') {
            return this.getVirtualFolderItemHtml(item);
        }
        
        return super.getGroupItemHtml(item);
    }
    
    getVirtualFolderItemHtml(item) {
        const labelElement = document.createElement('span');
        labelElement.textContent = item.label || this.translate(item.entityType, 'scopeNamesPlural');
        
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
        
        // Drag handle
        const dragHandle = document.createElement('span');
        dragHandle.className = 'drag-handle';
        dragHandle.innerHTML = '<span class="fas fa-grip fa-sm"></span>';
        
        // Edit button
        const editBtn = document.createElement('a');
        editBtn.role = 'button';
        editBtn.tabIndex = 0;
        editBtn.dataset.value = item.id;
        editBtn.dataset.action = 'editGroup';
        editBtn.innerHTML = '<span class="fas fa-pencil-alt fa-sm"></span>';
        
        const itemButton = document.createElement('span');
        itemButton.className = 'item-button';
        itemButton.append(editBtn);
        
        // Remove button
        const removeBtn = document.createElement('a');
        removeBtn.role = 'button';
        removeBtn.tabIndex = 0;
        removeBtn.className = 'pull-right';
        removeBtn.dataset.value = item.id;
        removeBtn.dataset.action = 'removeValue';
        removeBtn.innerHTML = '<span class="fas fa-times"></span>';
        
        div.append(dragHandle, itemButton, itemElement, removeBtn);
        
        return div.outerHTML;
    }
    
    editGroup(id) {
        const item = Espo.Utils.cloneDeep(this.getGroupValueById(id) || {});
        
        // Route virtual folder to its own modal
        const view = item.type === 'virtualFolder'
            ? 'global:views/settings/modals/edit-tab-virtual-folder'
            : {
                divider: 'views/settings/modals/edit-tab-divider',
                url: 'views/settings/modals/edit-tab-url'
            }[item.type] || 'views/settings/modals/edit-tab-group';
        
        // ... rest of method same as parent
        const index = this.getGroupIndexById(id);
        const tabList = Espo.Utils.cloneDeep(this.selected);
        
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

export default TabListFieldView;
```

---

#### 3. Tab List Field Add Modal Extension (LOW)

**Path:** `client/custom/modules/global/src/views/settings/modals/tab-list-field-add.js` (CREATE)

**Purpose:** Extend core modal to add "Add Virtual Folder" button.

**Implementation:**
```javascript
import CoreTabListFieldAddModalView from 'views/settings/modals/tab-list-field-add';

class TabListFieldAddModalView extends CoreTabListFieldAddModalView {
    
    setup() {
        super.setup();
        
        // Add virtual folder button after existing buttons
        this.addButton({
            name: 'addVirtualFolder',
            text: this.translate('Virtual Folder', 'labels', 'Settings'),
            onClick: () => this.actionAddVirtualFolder(),
            position: 'right',
            iconClass: 'fas fa-plus fa-sm',
        });
    }
    
    actionAddVirtualFolder() {
        this.trigger('add', {
            type: 'virtualFolder',
            id: Math.floor(Math.random() * 1000000 + 1).toString(),
            label: null,
            entityType: null,
            filterName: null,
            maxItems: 5,
            iconClass: null,
            color: null,
            orderBy: null,
            order: 'desc',
        });
    }
}

export default TabListFieldAddModalView;
```

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
    },
    "messages": {
        "errorSavingPreference": "Error saving preference."
    }
}
```

---

#### 5. Settings Translations (LOW)

**Path:** `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json`

**Add:**
```json
{
    "labels": {
        "Virtual Folder": "Virtual Folder",
        "Group Tab": "Group Tab"
    }
}
```

---

### Files to CONSIDER

| File Path | Reason |
|-----------|--------|
| `application/Espo/Resources/metadata/entityDefs/SidenavConfig.json` | If SidenavConfig.tabList field needs custom view override to use global tab-list field |
| `client/custom/modules/global/src/views/modals/navbar-config-field-add.js` | May need update if virtual folders should be addable from SidenavConfig editing context |

---

### Related Files (for reference only, no changes needed)

| File Path | Pattern Reference |
|-----------|-------------------|
| `client/src/views/settings/modals/tab-list-field-add.js` | Pattern for adding addGroup/addDivider/addUrl buttons |
| `client/src/views/settings/modals/edit-tab-group.js` | Pattern for modal with dynamic field model |
| `client/src/views/settings/modals/edit-tab-url.js` | Another modal pattern |
| `client/src/views/settings/fields/tab-list.js` | Pattern for handling complex tab items |
| `client/src/views/site/navbar/quick-create.js` | Quick create implementation with RecordModal |
| `client/src/helpers/site/tabs.js` | Tab type detection pattern (isTabDivider, isTabUrl, etc.) |
| `client/src/views/site/navbar.js` | Tab rendering and preparation pattern (prepareTabItemDefs) |
| `client/custom/modules/global/src/views/site/navbar.js` | Existing custom navbar with team configs |
| `client/custom/modules/global/src/views/activities/fields/name-with-icon.js` | Entity icon retrieval pattern |
| `client/src/views/record/search.js` | presetFilters handling pattern (lines 303, 641-644) |
| `client/src/views/record/panels/relationship.js` | Filter resolution with primaryFilter (lines 574-581) |
| `client/src/helpers/record-modal.js` | showCreate() pattern |
| `client/res/templates/site/navbar.tpl` | Template structure (itemDataList pattern lines 188-189) |

---

## Implementation Order

### Phase 1: Data Model & Core Modal
1. Create `edit-tab-virtual-folder.js` modal
2. Create `virtual-folder-entity.js` field
3. Create `virtual-folder-filter.js` field
4. Add translations

### Phase 2: Tab List Integration
1. Create `tab-list-field-add.js` modal extension
2. Create `tab-list.js` field view extension
3. Test virtual folder item creation/editing in tab list

### Phase 3: Navbar Rendering
1. Create `helpers/site/tabs.js` with isTabVirtualFolder()
2. Update `navbar.js` to use custom TabsHelper and handle virtual folders
3. Create `virtual-folder.js` view
4. Create `virtual-folder.tpl` template
5. Create `virtual-folder.css` styles

### Phase 4: Testing & Polish
1. Test collapse/expand persistence
2. Test quick create functionality
3. Test refresh action
4. Test with various entity types
5. Test with user preset filters
6. Test ACL restrictions
7. Mobile/responsive behavior

---

## Error Handling

### Invalid Entity Type
- If `entityType` is disabled or ACL denied → hide virtual folder
- Log warning in console

### Invalid Filter
- If `filterName` doesn't exist → fall back to no filter (empty string)
- Use entity default list view

### Fetch Error
- Show "Failed to load" message in virtual folder
- Provide retry button in more options

### No Records
- Show empty state message: "No records found"
- Still show quick create button

---

## Summary of File Count

| Category | Count |
|----------|-------|
| CREATE | 8 files |
| EDIT | 5 files |
| CONSIDER | 2 files |
| Reference | 13 files |

---

*v2 Scope document - Addresses audit findings for filter resolution, navbar integration, and event handling.*

**Tool: todowrite**

**Input:**
```json
{
  "todos": [
    {
      "content": "Create v2 scope document addressing audit findings",
      "priority": "high",
      "status": "completed"
    }
  ]
}
```

**Output:**
```
[
  {
    "content": "Create v2 scope document addressing audit findings",
    "priority": "high",
    "status": "completed"
  }
]
```

---

## Assistant (Scope · glm-5-free · 40.3s)

_Thinking:_

I've completed the v2 scope document that addresses all the critical findings from the audit. The key changes from v1 are:

1. **Filter resolution strategy** - Now uses `collection.data.primaryFilter` pattern matching EspoCRM conventions (addressing Critical Finding #1)

2. **Navbar integration approach** - Uses view injection via itemDataList pattern instead of template override, and extends the custom navbar's prepareTabItemDefs() (addressing Critical Finding #2)

3. **Explicit filter merge logic** - Documented the presetFilters access pattern with code example

4. **Event handling specification** - Added the listenTo pattern for entityType changes triggering filter reload

5. **Other warnings addressed**:
   - RecordModal import path: Explicitly shown
   - Entity type ACL check: Uses `checkScope(scope, 'read')` 
   - Template integration: Uses view injection pattern
   - Loading state: Added isLoading with spinner

The scope document is now complete and ready for implementation.

## Summary

I've created the v2 scope document that addresses all critical findings from the audit:

### Critical Issues Resolved:

1. **Filter Data Resolution Strategy** (Critical Finding #1)
   - Now uses `collection.data.primaryFilter = filterName` pattern
   - Follows `client/src/views/record/panels/relationship.js:574-581`
   - Uses collection factory for proper filter resolution, ACL handling, and pagination support

2. **Custom Navbar Integration Approach** (Critical Finding #2)
   - Uses **view injection via itemDataList pattern** for cleaner separation
   - No need to maintain a template fork
   - Extends `prepareTabItemDefs()` in custom navbar
   - Creates custom `TabsHelper` with `isTabVirtualFolder()` detection

### Warnings Addressed:

| Warning | Resolution |
|---------|------------|
| RecordModal import path | Explicit import: `import RecordModal from 'helpers/record-modal';` |
| Entity type ACL check | Uses `this.getAcl().checkScope(scope, 'read')` |
| Filter field dynamic options | `listenTo(model, 'change:entityType', ...)` pattern |
| Template integration | View injection pattern with `data()` override |
| presetFilters access | Documented pattern with code example |

### Suggestions Incorporated:

- **Loading state indicator** - Added `isLoading` state with spinner in template
- **Collection factory pattern** - Preferred over raw Ajax for better integration

### File Summary:

| Category | Count |
|----------|-------|
| CREATE | 8 files |
| EDIT | 5 files |
| CONSIDER | 2 files |

The implementation order is organized into 4 phases: Data Model & Core Modal → Tab List Integration → Navbar Rendering → Testing & Polish.