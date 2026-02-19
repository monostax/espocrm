# Virtual Folder Tab List Type - v1 Scope Document

> **Version**: 1.0  
> **Codebase Root**: `components/crm/source/`  
> **Status**: SCOPE MAPPED  
> **Uses**: Global module (`custom/Espo/Modules/Global` and `client/custom/modules/global`)

## Overview

This feature adds a new tab list item type called `virtualFolder` that acts as a sibling to `group`, `divider`, and `url` types. A virtual folder:
- Displays entity records dynamically fetched via a saved filter
- Shows a collapsible "divider-like" header with entity icon + custom label
- Lists record items as clickable links under the header
- Provides a quick-create button and more options menu on hover

### Key Features

1. **Dynamic Record Loading**: Records fetched from entity using saved filter (or no filter)
2. **Collapsible/Expandable**: Click header to toggle visibility of record items
3. **Entity Icon Display**: Shows entity icon + label in header (like divider with icon)
4. **Item Limit Control**: Optional max number of items to display
5. **Quick Create**: Plus button on hover opens quick-create modal for the entity
6. **More Options Menu**: Dropdown menu on hover for additional actions

---

## Decisions

| # | Decision | Alternatives Considered | Rationale |
|---|----------|------------------------|-----------|
| 1 | Store virtual folder config as new tab type `virtualFolder` in tabList | Separate entity, JSON field | Reuses existing tabList infrastructure, follows group/divider/url pattern |
| 2 | Fetch records dynamically at navbar render time | Pre-load via AppParam, cache | Simpler implementation, records always fresh |
| 3 | Collapse state stored in browser localStorage | Server-side preference | Per-device preference, no server load, instant toggle |
| 4 | Use presetFilters from Preferences for saved filter selection | Create new SavedFilter entity link | Uses existing EspoCRM preset filter system |
| 5 | Limit visible items with `maxItems` property (default: 5) | Hard-coded limit, config setting | Per-folder customization, reasonable default |
| 6 | Quick create uses existing `RecordModal` helper | Custom modal | Consistent UX, reuses proven code |
| 7 | More options menu includes: Edit folder config, Refresh, View all in list | Complex action set | MVP feature set, extensible later |
| 8 | Custom label overrides entity name in header | Always use entity name | Allows grouping under custom names (e.g., "My Open Tasks" vs "Tasks") |
| 9 | No backend PHP changes needed initially | Custom controller endpoints | All fetching via existing collection API |

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

| File Path | Purpose |
|-----------|---------|
| `client/custom/modules/global/src/views/site/navbar/virtual-folder.js` | Main view component for rendering a virtual folder in the sidenav. Handles record fetching, collapse/expand, quick create, and more options. |

**Key Implementation Details:**
- Extends `View` class
- `template` property: `global:site/navbar/virtual-folder`
- Fetches records via `Espo.Ajax.getRequest(entityType, {where: filterData, maxItems})`
- Manages collapse state via `localStorage` key `navbar-vf-{id}-collapsed`
- Creates quick create view using `RecordModal` helper
- Handles more options dropdown with refresh/edit actions

**Reference Patterns:**
- Quick create: `client/src/views/site/navbar/quick-create.js`
- Record fetching: `client/src/views/record/panels/relationship.js` lines 501-544
- Collapse toggle: CSS class toggling pattern from existing group dropdowns

---

#### 2. Virtual Folder Template (HIGH)

| File Path | Purpose |
|-----------|---------|
| `client/custom/modules/global/res/templates/site/navbar/virtual-folder.tpl` | Handlebars template for virtual folder rendering |

**Structure:**
```handlebars
<li class="tab tab-virtual-folder{{#if isCollapsed}} collapsed{{/if}}" data-name="vf-{{id}}">
    <div class="virtual-folder-header" data-action="toggleVirtualFolder" data-id="{{id}}">
        <span class="virtual-folder-icon {{iconClass}}"></span>
        <span class="virtual-folder-label">{{label}}</span>
        <span class="virtual-folder-caret fas fa-chevron-{{#if isCollapsed}}right{{else}}down{{/if}}"></span>
        <div class="virtual-folder-actions hidden">
            <a class="action" data-action="quickCreate" title="{{translate 'Create'}}">
                <span class="fas fa-plus"></span>
            </a>
            <a class="dropdown-toggle" data-toggle="dropdown">
                <span class="fas fa-ellipsis-v"></span>
            </a>
            <ul class="dropdown-menu pull-right">
                <li><a data-action="refresh">{{translate 'Refresh'}}</a></li>
                <li><a data-action="viewAll">{{translate 'View All'}}</a></li>
            </ul>
        </div>
    </div>
    <ul class="virtual-folder-items{{#if isCollapsed}} hidden{{/if}}">
        {{#each recordList}}
            <li class="virtual-folder-item">
                <a href="#{{../entityType}}/view/{{id}}" class="nav-link">
                    {{name}}
                </a>
            </li>
        {{/each}}
        {{#if hasMore}}
            <li class="virtual-folder-more">
                <a href="#{{entityType}}/list{{#if filterQuery}}?{{filterQuery}}{{/if}}">
                    {{translate 'View all'}} ({{totalCount}})
                </a>
            </li>
        {{/if}}
    </ul>
</li>
```

---

#### 3. Virtual Folder Styles (HIGH)

| File Path | Purpose |
|-----------|---------|
| `client/custom/modules/global/css/virtual-folder.css` | Styles for virtual folder component |

**Key Styles:**
- `.tab-virtual-folder` - Container styling
- `.virtual-folder-header` - Header with hover actions
- `.virtual-folder-actions` - Hidden by default, show on header hover
- `.virtual-folder-items` - Collapsible item list
- `.virtual-folder-item` - Individual record item
- `.virtual-folder-caret` - Collapse/expand indicator
- Animation for collapse/expand

---

#### 4. Tab List Field Add Modal Extension (MEDIUM)

| File Path | Purpose |
|-----------|---------|
| `client/custom/modules/global/src/views/settings/modals/tab-list-field-add.js` | Extends core modal to add "Add Virtual Folder" button |

**Changes from base `views/settings/modals/tab-list-field-add.js`:**
- Add button: `{name: 'addVirtualFolder', text: 'Virtual Folder', iconClass: 'fas fa-folder'}`
- Add action: `actionAddVirtualFolder()` triggering `add` event with virtual folder skeleton

**Reference:** `client/src/views/settings/modals/tab-list-field-add.js`

---

#### 5. Edit Virtual Folder Modal (MEDIUM)

| File Path | Purpose |
|-----------|---------|
| `client/custom/modules/global/src/views/settings/modals/edit-tab-virtual-folder.js` | Modal for configuring virtual folder properties |

**Structure:** Follows pattern from `views/settings/modals/edit-tab-group.js`

**Fields:**
- `label` (varchar) - Custom display label
- `entityType` (enum) - Select from scopes with `tab: true`
- `filterName` (enum) - Dynamic options based on selected entityType (presetFilters from clientDefs + user preferences)
- `maxItems` (int) - Default 5
- `iconClass` (base with icon-class view)
- `color` (colorpicker)
- `orderBy` (enum) - Fields from selected entity
- `order` (enum) - asc/desc

**Reference:** `client/src/views/settings/modals/edit-tab-group.js`

---

#### 6. Entity Type Filter Field (MEDIUM)

| File Path | Purpose |
|-----------|---------|
| `client/custom/modules/global/src/views/settings/fields/virtual-folder-entity.js` | Field for selecting entity type and loading available filters |

**Implementation:**
- Extends `views/fields/enum`
- `setupOptions()`: Load scopes with `tab: true` and ACL read access
- On change: triggers event to reload filterName options

---

#### 7. Filter Name Field (MEDIUM)

| File Path | Purpose |
|-----------|---------|
| `client/custom/modules/global/src/views/settings/fields/virtual-folder-filter.js` | Field for selecting preset filter based on selected entity |

**Implementation:**
- Extends `views/fields/enum`
- Dynamic options based on `entityType` field value
- Merges: clientDefs filterList + user presetFilters for that entity
- Includes empty option for "no filter"

---

#### 8. Tab List Field View Extension (LOW)

| File Path | Purpose |
|-----------|---------|
| `client/custom/modules/global/src/views/settings/fields/tab-list.js` | Extends core tab-list field to handle virtual folder type |

**Changes:**
- Override `getGroupItemHtml()` to handle `type: 'virtualFolder'`
- Add icon class `fas fa-folder` for virtual folder items
- Add edit handler via `editVirtualFolder()` method
- Reference modal: `global:views/settings/modals/edit-tab-virtual-folder`

**Reference:** `client/src/views/settings/fields/tab-list.js`

---

### Files to EDIT

#### 1. Custom Navbar View (CRITICAL)

| File Path | Changes |
|-----------|---------|
| `client/custom/modules/global/src/views/site/navbar.js` | Add virtual folder handling in `prepareTabItemDefs()` and rendering |

**Changes:**
- Add `isTabVirtualFolder()` method to `TabsHelper` pattern
- In `setupTabDefsList()`: Handle virtual folder items (fetch records, prepare data)
- In `prepareTabItemDefs()`: Return virtual folder specific defs
- Create virtual folder views during navbar setup

**Pattern:** Follow existing group/divider handling in base navbar.js

---

#### 2. Tabs Helper Extension (HIGH)

| File Path | Changes |
|-----------|---------|
| Create: `client/custom/modules/global/src/helpers/site/tabs.js` OR edit navbar.js directly | Add virtual folder detection method |

**Add Method:**
```javascript
isTabVirtualFolder(item) {
    return typeof item === 'object' && item.type === 'virtualFolder';
}
```

---

#### 3. Navbar Template (MEDIUM)

| File Path | Changes |
|-----------|---------|
| Create: `client/custom/modules/global/res/templates/site/navbar.tpl` | Override core template to add virtual folder rendering |

**Add after isDivider block:**
```handlebars
{{#if isVirtualFolder}}
    {{{var virtualFolderKey}}}
{{/if}}
```

---

#### 4. Global CSS (LOW)

| File Path | Changes |
|-----------|---------|
| `client/custom/modules/global/css/navbar-config-selector.css` OR new CSS file | Add import for virtual-folder.css |

---

#### 5. Global Translations (LOW)

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json` | Add translations for virtual folder labels |

**Add to appropriate sections:**
```json
{
    "labels": {
        "Virtual Folder": "Virtual Folder",
        "Add Virtual Folder": "Add Virtual Folder",
        "Edit Virtual Folder": "Edit Virtual Folder"
    },
    "fields": {
        "entityType": "Entity",
        "filterName": "Filter",
        "maxItems": "Max Items",
        "orderBy": "Order By"
    }
}
```

---

#### 6. Settings Translations (LOW)

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json` | Add virtual folder related translations |

---

### Files to CONSIDER

| File Path | Reason |
|-----------|--------|
| `application/Espo/Resources/metadata/entityDefs/SidenavConfig.json` | If SidenavConfig.tabList needs custom view override |
| `client/custom/modules/global/src/views/modals/navbar-config-field-add.js` | May need update if used in SidenavConfig editing context |

---

### Related Files (for reference only, no changes needed)

| File Path | Pattern Reference |
|-----------|-------------------|
| `client/src/views/settings/modals/tab-list-field-add.js` | Pattern for adding addGroup/addDivider/addUrl buttons |
| `client/src/views/settings/modals/edit-tab-group.js` | Pattern for modal with dynamic field model |
| `client/src/views/settings/modals/edit-tab-url.js` | Another modal pattern |
| `client/src/views/settings/fields/tab-list.js` | Pattern for handling complex tab items |
| `client/src/views/site/navbar/quick-create.js` | Quick create implementation pattern |
| `client/src/helpers/site/tabs.js` | Tab type detection pattern |
| `client/src/views/site/navbar.js` | Tab rendering and preparation pattern |
| `client/custom/modules/global/src/views/site/navbar.js` | Existing custom navbar with team configs |
| `client/custom/modules/global/src/views/activities/fields/name-with-icon.js` | Entity icon retrieval pattern |
| `client/src/views/record/search.js` | presetFilters handling pattern |
| `frontend/less/espo/root-variables.less` | CSS variables for consistent styling |

---

## Implementation Order

### Phase 1: Data Model & Core Modal
1. Create `edit-tab-virtual-folder.js` modal
2. Create `virtual-folder-entity.js` field
3. Create `virtual-folder-filter.js` field
4. Add translations

### Phase 2: Tab List Integration
1. Extend `tab-list-field-add.js` (or create global override)
2. Extend `tab-list.js` field view (or create global override)
3. Test virtual folder item creation/editing in tab list

### Phase 3: Navbar Rendering
1. Create `virtual-folder.js` view
2. Create `virtual-folder.tpl` template
3. Create `virtual-folder.css` styles
4. Update `navbar.js` to handle virtual folders
5. Update navbar template if needed

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
- If `filterName` doesn't exist → fall back to no filter
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
| EDIT | 6 files |
| CONSIDER | 2 files |
| Reference | 12 files |

---

## UI Mockup Description

```
┌─────────────────────────────────┐
│ [⚙] Business Config       [▼]  │  <- Config selector
├─────────────────────────────────┤
│ 🏠 Home                         │
├─────────────────────────────────┤
│ 👥 Accounts                     │
├─────────────────────────────────┤
│ 📋 MY OPEN TASKS          [+] [⋮]│  <- Virtual folder header
│   ▼                             │     (hover shows + and ⋮)
│   ├─ Follow up with John        │
│   ├─ Review proposal            │
│   ├─ Schedule meeting           │
│   ├─ Send invoice               │
│   ├─ Call client                │
│   └─ View all (12)              │  <- Link to full list
├─────────────────────────────────┤
│ 💼 Opportunities                │
├─────────────────────────────────┤
│ 📊 Reports                      │
└─────────────────────────────────┘

[+] button → Opens quick create modal for Task
[⋮] menu:
  - Refresh
  - View All
  - Edit Folder
```

---

*Scope document v1 - SCOPE MAPPED*
