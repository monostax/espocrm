# Virtual Folder - Open Relationship View - v1 Scope Document

> **Version**: 1.0  
> **Codebase Root**: `components/crm/source/`  
> **Status**: READY FOR IMPLEMENTATION  
> **Uses**: Global module (`custom/Espo/Modules/Global` and `client/custom/modules/global`)

## Overview

This feature enhances the existing virtual folder functionality to allow configuring the click behavior on record items. Instead of always opening the record view (`#Entity/view/id`), users can configure a virtual folder to open a relationship view (`#Entity/related/id/linkName`) when clicking on a record item.

### Use Case Example

A virtual folder for `Funnel` entity currently opens:
- `https://app.am.monostax.dev.localhost/#Funnel/view/698a21e0f3ad32d7d`

With this feature, it can be configured to open:
- `https://app.am.monostax.dev.localhost/#Funnel/related/698a21e0f3ad32d7d/opportunities`

This shows the `Opportunity` relationship full form list instead of the Funnel record detail view.

---

## Decisions

| # | Decision | Alternatives Considered | Rationale |
|---|----------|------------------------|-----------|
| 1 | Add `openMode` field with values `view`/`relationship` | Boolean `openRelationship` field | More explicit and extensible for future modes |
| 2 | Add `relationshipLink` field for link name selection | Auto-detect first relationship | Gives user control over which relationship to open |
| 3 | Filter relationships to `hasMany` and `hasChildren` types | Show all links | Matches the relationship panels behavior; only these types appear in relationships view |
| 4 | Filter out `disabled`, `utility`, `layoutRelationshipsDisabled` links | Show all hasMany/hasChildren | Follows the pattern from `bottom-panels-detail.js` for relationship panel filtering |
| 5 | Use `entityDefs.{entityType}.links` metadata for relationship list | Separate API call | Consistent with EspoCRM patterns; metadata already available client-side |
| 6 | Make `relationshipLink` required when `openMode` is `relationship` | Optional with fallback | Prevents broken behavior; user must explicitly select which relationship |
| 7 | Clear `relationshipLink` when `entityType` changes | Keep stale value | Prevents invalid configuration when entity changes |
| 8 | Default `openMode` to `view` | Default to `relationship` | Maintains backward compatibility; existing virtual folders work unchanged |

---

## Data Model Design

### Virtual Folder Item Structure (extended)

```json
{
  "type": "virtualFolder",
  "id": "vf-123456",
  "label": "My Funnels",
  "entityType": "Funnel",
  "filterName": null,
  "maxItems": 5,
  "iconClass": null,
  "color": null,
  "orderBy": "createdAt",
  "order": "desc",
  "openMode": "relationship",
  "relationshipLink": "opportunities"
}
```

### New Field Definitions

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `openMode` | enum | No | `view` | `view` = record detail, `relationship` = relationship view |
| `relationshipLink` | enum | Conditional | null | Link name to open; required when `openMode` is `relationship` |

---

## File Manifest

### Files to CREATE (ordered by complexity/risk, highest first)

#### 1. Virtual Folder Relationship Link Field View (HIGH)

**Path:** `client/custom/modules/global/src/views/settings/fields/virtual-folder-relationship-link.js`

**Purpose:** Enum field for selecting a relationship link from the entity's available relationships.

**Key Implementation Details:**
- Extends `EnumFieldView`
- `setupOptions()` retrieves links from `entityDefs.{entityType}.links`
- Filters for `hasMany` and `hasChildren` types only
- Filters out links with `disabled`, `utility`, or `layoutRelationshipsDisabled` flags
- Sorts options alphabetically by translated link name
- Shows "Select Entity First" message when no entityType selected
- Listens to `change:entityType` to refresh options

**Reference Pattern (from bottom-panels-detail.js:88-106):**
```javascript
setupOptions() {
    const entityType = this.model.get('entityType');
    
    if (!entityType) {
        this.params.options = [''];
        this.translatedOptions = {'': this.translate('Select Entity First', 'labels', 'Global')};
        return;
    }
    
    const linkDefs = this.getMetadata().get(['entityDefs', entityType, 'links']) || {};
    const options = [''];
    
    Object.keys(linkDefs).forEach(link => {
        if (
            linkDefs[link].disabled ||
            linkDefs[link].utility ||
            linkDefs[link].layoutRelationshipsDisabled
        ) {
            return;
        }
        
        if (!['hasMany', 'hasChildren'].includes(linkDefs[link].type)) {
            return;
        }
        
        options.push(link);
    });
    
    this.params.options = options.sort((a, b) => {
        return this.translate(a, 'links', entityType)
            .localeCompare(this.translate(b, 'links', entityType));
    });
    
    this.translatedOptions = {
        '': this.translate('No Relationship', 'labels', 'Global')
    };
    
    this.params.options.forEach(link => {
        if (link === '') return;
        this.translatedOptions[link] = this.translate(link, 'links', entityType);
    });
}
```

---

### Files to EDIT

#### 1. Edit Virtual Folder Modal (CRITICAL)

**Path:** `client/custom/modules/global/src/views/settings/modals/edit-tab-virtual-folder.js`

**Changes Required:**

1. **Add new fields to detailLayout** - Add `openMode` and `relationshipLink` fields in a new row after the existing configuration:

```javascript
// Add to detailLayout rows array after the orderBy/order row
[
    {
        name: 'openMode',
        labelText: this.translate('openMode', 'fields', 'Global'),
    },
    {
        name: 'relationshipLink',
        labelText: this.translate('relationshipLink', 'fields', 'Global'),
        view: 'global:views/settings/fields/virtual-folder-relationship-link',
    },
    false,
],
```

2. **Add field definitions to model.setDefs()** - Add the new fields:

```javascript
fields: {
    // ... existing fields ...
    openMode: {
        type: 'enum',
        options: ['view', 'relationship'],
        default: 'view',
    },
    relationshipLink: {
        type: 'enum',
    },
}
```

3. **Add change listener for entityType** - Clear `relationshipLink` when entityType changes:

```javascript
// Add after existing change:entityType listener
this.listenTo(model, 'change:entityType', () => {
    // ... existing filterName logic ...
    
    const relationshipLinkFieldView = this.getView('record').getFieldView('relationshipLink');
    
    if (relationshipLinkFieldView) {
        model.set('relationshipLink', null);
        relationshipLinkFieldView.setupOptions();
        relationshipLinkFieldView.reRender();
    }
});
```

4. **Add change listener for openMode** - Clear `relationshipLink` when switching back to `view` mode:

```javascript
this.listenTo(model, 'change:openMode', () => {
    if (model.get('openMode') === 'view') {
        model.set('relationshipLink', null);
    }
});
```

---

#### 2. Virtual Folder View (CRITICAL)

**Path:** `client/custom/modules/global/src/views/site/navbar/virtual-folder.js`

**Changes Required:**

1. **Add new properties** in class body:

```javascript
openMode = 'view'
relationshipLink = null
```

2. **Update `setup()` method** - Extract new config values:

```javascript
setup() {
    const config = this.options.config || {};
    
    // ... existing config extraction ...
    
    this.openMode = config.openMode || 'view';
    this.relationshipLink = config.relationshipLink || null;
    
    // ... rest of setup ...
}
```

3. **Update `data()` method** - Include `recordUrl` for each record:

```javascript
data() {
    return {
        // ... existing data ...
        recordList: this.recordList.map(record => ({
            id: record.id,
            name: record.name,
            url: this.getRecordUrl(record.id),
        })),
    };
}
```

4. **Add `getRecordUrl()` method:**

```javascript
getRecordUrl(recordId) {
    if (this.openMode === 'relationship' && this.relationshipLink) {
        return `#${this.entityType}/related/${recordId}/${this.relationshipLink}`;
    }
    
    return `#${this.entityType}/view/${recordId}`;
}
```

---

#### 3. Virtual Folder Template (HIGH)

**Path:** `client/custom/modules/global/res/templates/site/navbar/virtual-folder.tpl`

**Changes Required:**

Update the record list item anchor to use the URL from data:

```handlebars
{{#each recordList}}
    <li class="virtual-folder-item">
        <a href="{{url}}">{{name}}</a>
    </li>
{{/each}}
```

This replaces the current:
```handlebars
<a href="#{{../entityType}}/view/{{id}}">{{name}}</a>
```

---

#### 4. Tab List Field Add Modal (MEDIUM)

**Path:** `client/custom/modules/global/src/views/settings/modals/tab-list-field-add.js`

**Changes Required:**

Update `actionAddVirtualFolder()` to include new default properties:

```javascript
actionAddVirtualFolder() {
    this.trigger('add', {
        type: 'virtualFolder',
        id: 'vf-' + Math.floor(Math.random() * 1000000 + 1),
        label: null,
        entityType: null,
        filterName: null,
        maxItems: 5,
        iconClass: null,
        color: null,
        orderBy: null,
        order: 'desc',
        openMode: 'view',           // NEW: default to view mode
        relationshipLink: null,     // NEW: no relationship selected
    });
}
```

---

#### 5. Global Translations (LOW)

**Path:** `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json`

**Add to appropriate sections:**

```json
{
    "labels": {
        "Select Entity First": "Select Entity First",
        "No Relationship": "No Relationship"
    },
    "fields": {
        "openMode": "Open Mode",
        "relationshipLink": "Relationship"
    },
    "options": {
        "openMode": {
            "view": "Record View",
            "relationship": "Relationship View"
        }
    }
}
```

---

### Related Files (for reference only, no changes needed)

| File Path | Pattern Reference |
|-----------|-------------------|
| `client/src/views/admin/layouts/bottom-panels-detail.js` | Relationship filtering logic (lines 88-106) |
| `client/src/router.js` | Related route pattern: `:controller/related/:id/:link` (line 124) |
| `client/src/views/modals/related-list.js` | Related URL construction pattern (lines 274, 284) |
| `client/custom/modules/global/src/views/settings/fields/virtual-folder-filter.js` | Similar enum field with entityType dependency |
| `client/custom/modules/global/src/views/settings/fields/virtual-folder-entity.js` | Enum field pattern for entity selection |

---

## Implementation Order

### Phase 1: Field and Modal Updates
1. Create `views/settings/fields/virtual-folder-relationship-link.js` with relationship filtering
2. Edit `views/settings/modals/edit-tab-virtual-folder.js`:
   - Add openMode and relationshipLink fields to layout
   - Add field definitions to model
   - Add entityType change listener
   - Add openMode change listener
3. Add translations to `Global.json`
4. Edit `views/settings/modals/tab-list-field-add.js` to include default values

### Phase 2: View and Template Updates
1. Edit `views/site/navbar/virtual-folder.js`:
   - Add openMode and relationshipLink properties
   - Update setup() to extract new config
   - Add getRecordUrl() method
   - Update data() to include URL per record
2. Edit template `res/templates/site/navbar/virtual-folder.tpl`:
   - Use `{{url}}` instead of hardcoded path

### Phase 3: Testing
1. Test with `openMode: "view"` (default behavior - should work as before)
2. Test with `openMode: "relationship"` and valid `relationshipLink`
3. Test relationship link field updates when entityType changes
4. Test that relationshipLink clears when switching back to view mode
5. Test with entities that have no relationships (should show "No Relationship" option)
6. Test URL generation for both modes

---

## Error Handling

### Invalid Relationship Link
- If `relationshipLink` is set but the link no longer exists → fall back to record view
- Log warning in console: "Relationship link '{link}' not found for entity '{entityType}'"

### Missing Relationship Link When Required
- If `openMode` is `relationship` but `relationshipLink` is null → fall back to record view
- Log warning in console

### Entity Has No Relationships
- Relationship link field shows only "No Relationship" option
- User can still select `openMode: "relationship"` but will get record view behavior

---

## Summary of File Count

| Category | Count |
|----------|-------|
| CREATE | 1 file |
| EDIT | 5 files |
| Reference | 5 files |

---

*v1 Scope document - Ready for implementation. Extends existing virtual folder feature with relationship view capability.*
