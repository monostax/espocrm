# Virtual Folder - Open Relationship View - v2 Scope Document

> **Version**: 2.0  
> **Codebase Root**: `components/crm/source/`  
> **Status**: READY FOR IMPLEMENTATION  
> **Uses**: Global module (`custom/Espo/Modules/Global` and `client/custom/modules/global`)
> **Revises**: v1 - Addresses audit findings from `virtual-folder-open-relationship-view.v1.audit.md`

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
| 9 | **Fields handle `change:entityType` internally** | Modal handles all field updates | Consistent with existing `virtual-folder-filter.js` pattern; eliminates duplicate event handling |
| 10 | **Use `{silent: true}` when clearing dependent fields** | No silent flag | Prevents cascading change events and duplicate re-renders |
| 11 | **Add custom validation for conditional `relationshipLink` requirement** | Rely on field `required` attribute | Field `required` doesn't support conditional requirements |
| 12 | **Hide `relationshipLink` field when `openMode` is `view`** | Always show field | Better UX - users won't see irrelevant field |
| 13 | **Show toast notification for invalid relationship links** | Console warning only | Users understand why expected behavior doesn't occur |

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
- **Handles `change:entityType` internally** (consistent with `virtual-folder-filter.js` pattern)
- **Uses `{silent: true}` when clearing value** to prevent cascading events

**Reference Pattern (follows `virtual-folder-filter.js`):**
```javascript
setup() {
    this.setupOptions();

    this.listenTo(this.model, 'change:entityType', () => {
        this.model.set('relationshipLink', null, {silent: true});
        this.setupOptions();
        this.reRender();
    });

    super.setup();
}
```

---

### Files to EDIT

#### 1. Edit Virtual Folder Modal (CRITICAL)

**Path:** `client/custom/modules/global/src/views/settings/modals/edit-tab-virtual-folder.js`

**Changes Required:**

1. **Add new fields to detailLayout** - Add `openMode` and `relationshipLink` fields in a new row after the orderBy/order row:

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

2. **Add field definitions to model.setDefs()**:

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

3. **REMOVE the existing `change:entityType` listener** (lines 136-144) - The `virtual-folder-filter.js` field already handles this internally. This listener is redundant and causes duplicate execution.

**Delete this entire block:**
```javascript
this.listenTo(model, 'change:entityType', () => {
    const filterFieldView = this.getView('record').getFieldView('filterName');
    
    if (filterFieldView) {
        model.set('filterName', null);
        filterFieldView.setupOptions();
        filterFieldView.reRender();
    }
});
```

4. **Add change listener for `openMode`** - Clear `relationshipLink` when switching to `view` mode:

```javascript
this.listenTo(model, 'change:openMode', () => {
    if (model.get('openMode') === 'view') {
        model.set('relationshipLink', null, {silent: true});
    }
});
```

5. **Add custom validation for conditional `relationshipLink` requirement** - In `actionApply()`:

```javascript
actionApply() {
    const recordView = this.getView('record');
    const model = this.model;

    // Custom validation for conditional requirement
    if (model.get('openMode') === 'relationship' && !model.get('relationshipLink')) {
        const relationshipLinkField = recordView.getFieldView('relationshipLink');
        if (relationshipLinkField) {
            relationshipLinkField.setValidationMessage('required', 
                this.translate('relationshipLinkRequired', 'messages', 'Global'));
        }
        return;
    }

    if (recordView.validate()) {
        return;
    }

    const data = recordView.fetch();
    this.trigger('apply', data);
}
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

**Note:** `this.recordList` is a private implementation detail used only by this view's template. No other code references it directly.

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

5. **Add validation for stale relationship links** - In `getRecordUrl()`, check if the relationship still exists:

```javascript
getRecordUrl(recordId) {
    if (this.openMode === 'relationship' && this.relationshipLink) {
        // Validate that the relationship link still exists
        const linkDefs = this.getMetadata().get(['entityDefs', this.entityType, 'links']) || {};
        const linkDef = linkDefs[this.relationshipLink];
        
        if (!linkDef || linkDef.disabled || linkDef.utility || linkDef.layoutRelationshipsDisabled) {
            // Invalid or stale relationship link - show toast and fall back to view
            Espo.Ui.warning(this.translate('relationshipLinkInvalid', 'messages', 'Global'));
            console.warn(`Relationship link '${this.relationshipLink}' not found or invalid for entity '${this.entityType}'`);
            return `#${this.entityType}/view/${recordId}`;
        }
        
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
    },
    "messages": {
        "relationshipLinkRequired": "Relationship is required when Open Mode is Relationship View",
        "relationshipLinkInvalid": "The configured relationship is no longer available. Opening record view instead."
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
| `client/custom/modules/global/src/views/settings/fields/virtual-folder-filter.js` | Enum field with internal `change:entityType` handler - **follow this pattern** |
| `client/custom/modules/global/src/views/settings/fields/virtual-folder-entity.js` | Enum field pattern for entity selection |

---

## Implementation Order

### Phase 1: Field and Modal Updates
1. Create `views/settings/fields/virtual-folder-relationship-link.js` with:
   - Internal `change:entityType` listener (like `virtual-folder-filter.js`)
   - `{silent: true}` when clearing value
2. Edit `views/settings/modals/edit-tab-virtual-folder.js`:
   - **REMOVE the existing `change:entityType` listener** (critical fix)
   - Add openMode and relationshipLink fields to layout
   - Add field definitions to model
   - Add `change:openMode` listener
   - Add custom validation in `actionApply()`
3. Add translations to `Global.json`
4. Edit `views/settings/modals/tab-list-field-add.js` to include default values

### Phase 2: View and Template Updates
1. Edit `views/site/navbar/virtual-folder.js`:
   - Add openMode and relationshipLink properties
   - Update setup() to extract new config
   - Add getRecordUrl() method with validation and toast notification
   - Update data() to include URL per record
2. Edit template `res/templates/site/navbar/virtual-folder.tpl`:
   - Use `{{url}}` instead of hardcoded path

### Phase 3: Testing
1. Test with `openMode: "view"` (default behavior - should work as before)
2. Test with `openMode: "relationship"` and valid `relationshipLink`
3. Test relationship link field updates when entityType changes
4. Test that relationshipLink clears when switching back to view mode
5. Test validation error when saving with `openMode: "relationship"` and no `relationshipLink`
6. Test with entities that have no relationships (should show "No Relationship" option)
7. Test URL generation for both modes
8. Test toast notification appears when relationship link is invalid/stale
9. **Verify no duplicate event handling** - change entityType and confirm setupOptions/reRender only called once per field

---

## Error Handling

### Invalid Relationship Link
- If `relationshipLink` is set but the link no longer exists → fall back to record view
- **Show toast notification:** "The configured relationship is no longer available. Opening record view instead."
- Log warning in console: "Relationship link '{link}' not found or invalid for entity '{entityType}'"

### Missing Relationship Link When Required
- If `openMode` is `relationship` but `relationshipLink` is null → validation error
- **Show validation message:** "Relationship is required when Open Mode is Relationship View"
- Prevents save until user selects a relationship

### Entity Has No Relationships
- Relationship link field shows only "No Relationship" option
- User can still select `openMode: "relationship"` but validation will require them to select a relationship
- If they somehow save with `relationshipLink: null`, falls back to record view

---

## Summary of File Count

| Category | Count |
|----------|-------|
| CREATE | 1 file |
| EDIT | 5 files |
| Reference | 5 files |

---

## Changes from v1

| # | Change | Reason |
|---|--------|--------|
| 1 | Decision 9: Fields handle `change:entityType` internally | Eliminates duplicate event handling (Critical finding) |
| 2 | Decision 10: Use `{silent: true}` consistently | Prevents cascading change events (Warning) |
| 3 | Decision 11: Custom validation for conditional requirement | Field `required` doesn't support conditions (Warning) |
| 4 | Decision 12: Hide relationshipLink when openMode is view | Better UX (Suggestion) |
| 5 | Decision 13: Toast notification for invalid links | Users understand behavior (Suggestion) |
| 6 | Added explicit instruction to REMOVE modal's `change:entityType` listener | Eliminates duplicate execution (Critical finding) |
| 7 | Added validation message translations | Support custom validation |
| 8 | Added toast notification translation | Support user-facing error messages |
| 9 | Added note about `recordList` being private | Addresses warning about data transformation |
| 10 | Enhanced test cases to verify no duplicate event handling | Ensure critical fix is verified |

---

*v2 Scope document - Ready for implementation. Addresses all audit findings from v1.*
