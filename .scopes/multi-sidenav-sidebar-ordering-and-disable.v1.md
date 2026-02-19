# Multi-Sidenav Sidebar - Ordering and Disable - v1 File Manifest

> **Version**: 1.0  
> **Based on**: Implemented v4 (team-scoped SidenavConfig)  
> **Codebase Root**: `components/crm/source/`  
> **Status**: SCOPE MAPPED - READY FOR IMPLEMENTATION

## Overview

Add programmatic ordering control and the ability to disable sidenav configurations. Currently, configs are ordered alphabetically by `name` with no way to hide a config from the selector without deleting it.

### Requirements

1. **Ordering** - Admins can set a numeric order for each SidenavConfig to control its position in the dropdown selector
2. **Disable** - Admins can mark a SidenavConfig as disabled, hiding it from the selector while preserving the record

---

## Decisions

| # | Decision | Alternatives Considered | Rationale |
|---|----------|------------------------|-----------|
| 1 | Add `order` field (int, default 10) | Use `sortOrder`, `position`, `priority` | Matches `OpportunityStage.order` pattern exactly |
| 2 | Add `isDisabled` field (bool, default false) | Use `isActive` (inverted logic) | `isDisabled` aligns with UI intent - "disabled" state is explicit |
| 3 | Filter disabled configs server-side in AppParam | Filter client-side in navbar.js | Server-side filtering is more efficient, cleaner separation |
| 4 | Order by `order` field ASC, then `name` ASC | Order by `order` only | Provides stable secondary sort for configs with same order value |
| 5 | Update collection default orderBy from `name` to `order` | Keep collection orderBy as `name` | Makes list views and other queries use programmatic order by default |
| 6 | Show `order` and `isDisabled` in list view | Show only in detail view | Admins need quick visibility of ordering/disabled status |
| 7 | Add index on `order` column | No index | Small perf improvement for AppParam query, matches OpportunityStage pattern |

---

## File Manifest

### Files to EDIT (ordered by complexity/risk, highest first)

#### 1. Entity Definition (CRITICAL)

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json` | ADD `order` field (int), ADD `isDisabled` field (bool), UPDATE collection orderBy, ADD index for `order` |

**Add to `fields` object (after `name` field is a good location):**

```json
"order": {
    "type": "int",
    "default": 10,
    "min": 1,
    "tooltip": true
}
```

**Add to `fields` object (after `isDefault` field is a good location):**

```json
"isDisabled": {
    "type": "bool",
    "default": false,
    "tooltip": true
}
```

**Update `collection` object (change from name to order):**

```json
"collection": {
    "orderBy": "order",
    "order": "asc"
}
```

**Add to `indexes` object:**

```json
"order": {
    "columns": ["order"]
}
```

**Reference Pattern:** `custom/Espo/Modules/Global/Resources/metadata/entityDefs/OpportunityStage.json` (has identical `order` field structure)

---

#### 2. Backend AppParam Class (CRITICAL)

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Classes/AppParams/TeamSidenavConfigs.php` | ADD filter for `isDisabled = false`, UPDATE order clause to use `order` then `name` |

**Current query (line 54-60):**

```php
$configs = $this->entityManager
    ->getRDBRepository('SidenavConfig')
    ->distinct()
    ->join('teams')
    ->where(['teams.id' => $teamIds])
    ->order('name')
    ->find();
```

**Replace with:**

```php
$configs = $this->entityManager
    ->getRDBRepository('SidenavConfig')
    ->distinct()
    ->join('teams')
    ->where([
        'teams.id' => $teamIds,
        'isDisabled' => false,
    ])
    ->order('order')
    ->order('name')
    ->find();
```

**Key Changes:**
- Add `'isDisabled' => false` to the `where()` clause
- Change `->order('name')` to `->order('order')->order('name')` for secondary sort

---

#### 3. Detail Layout (MEDIUM)

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detail.json` | ADD `order` and `isDisabled` fields to the Overview panel |

**Current content:**

```json
[
    {
        "label": "Overview",
        "rows": [
            [{"name": "name"}, {"name": "teams"}],
            [{"name": "iconClass"}, {"name": "color"}],
            [{"name": "isDefault"}, false],
            [{"name": "tabList", "fullWidth": true}]
        ]
    }
]
```

**Replace with:**

```json
[
    {
        "label": "Overview",
        "rows": [
            [{"name": "name"}, {"name": "teams"}],
            [{"name": "order"}, {"name": "isDisabled"}],
            [{"name": "iconClass"}, {"name": "color"}],
            [{"name": "isDefault"}, false],
            [{"name": "tabList", "fullWidth": true}]
        ]
    }
]
```

**Rationale:** Placing `order` and `isDisabled` early makes them prominent for admin workflow.

---

#### 4. List Layout (MEDIUM)

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/list.json` | ADD `order` and `isDisabled` columns |

**Current content:**

```json
[
    {"name": "name", "width": 30},
    {"name": "teams", "width": 40},
    {"name": "isDefault", "width": 15}
]
```

**Replace with:**

```json
[
    {"name": "order", "width": 10},
    {"name": "name", "width": 25},
    {"name": "teams", "width": 35},
    {"name": "isDefault", "width": 12},
    {"name": "isDisabled", "width": 12}
]
```

**Rationale:** `order` column first for quick visual scan of ordering. `isDisabled` shows at a glance which configs are hidden.

---

#### 5. Small Detail Layout (LOW)

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detailSmall.json` | ADD `order` field for quick editing |

**Current content:**

```json
[
    {
        "label": "Overview",
        "rows": [
            [{"name": "name"}],
            [{"name": "teams"}],
            [{"name": "isDefault"}]
        ]
    }
]
```

**Replace with:**

```json
[
    {
        "label": "Overview",
        "rows": [
            [{"name": "name"}],
            [{"name": "teams"}],
            [{"name": "order"}, {"name": "isDisabled"}],
            [{"name": "isDefault"}]
        ]
    }
]
```

---

#### 6. Entity Translations (LOW)

| File Path | Changes |
|-----------|---------|
| `custom/Espo/Modules/Global/Resources/i18n/en_US/SidenavConfig.json` | ADD field labels and tooltips for `order` and `isDisabled` |

**Add to `fields` object:**

```json
"order": "Order",
"isDisabled": "Disabled"
```

**Add to `tooltips` object:**

```json
"order": "Controls the position of this configuration in the selector dropdown. Lower numbers appear first.",
"isDisabled": "If checked, this configuration will be hidden from the selector dropdown but the record will be preserved."
```

**Complete file after changes:**

```json
{
    "scopeNames": {
        "SidenavConfig": "Sidenav Configuration"
    },
    "scopeNamesPlural": {
        "SidenavConfig": "Sidenav Configurations"
    },
    "fields": {
        "name": "Name",
        "teams": "Teams",
        "order": "Order",
        "isDisabled": "Disabled",
        "iconClass": "Icon",
        "color": "Color",
        "tabList": "Tab List",
        "isDefault": "Default"
    },
    "tooltips": {
        "order": "Controls the position of this configuration in the selector dropdown. Lower numbers appear first.",
        "isDisabled": "If checked, this configuration will be hidden from the selector dropdown but the record will be preserved.",
        "isDefault": "If checked, this configuration will be the default for users in these teams who haven't selected a specific config."
    }
}
```

---

### Files to CONSIDER

| File Path | Reason |
|-----------|--------|
| `client/custom/modules/global/src/views/site/navbar-config-selector.js` | May want to add visual indicator for disabled configs in future (e.g., show count of disabled configs). Currently not needed since disabled configs are filtered server-side. |
| `client/custom/modules/global/css/navbar-config-selector.css` | If future enhancement adds disabled item styling (grayed out in dropdown), would need CSS updates. |

---

### Related Files (for reference only, no changes needed)

| File Path | Pattern Reference |
|-----------|-------------------|
| `client/custom/modules/global/src/views/site/navbar.js` | Uses `getNavbarConfigList()` which reads from AppParam - no changes needed |
| `client/custom/modules/global/src/views/preferences/fields/active-navbar-config.js` | Uses `teamSidenavConfigs` AppParam - no changes needed |
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/OpportunityStage.json` | Reference for `order` field structure (int, default 10, min 1, tooltip) |
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Funnel.json` | Reference for `isActive` field (using `isDisabled` instead for clarity) |

---

## Error Handling

### Active Config Becomes Disabled

If a user's `activeNavbarConfigId` points to a config that is subsequently disabled:
- The AppParam will not include the disabled config
- `getActiveNavbarConfig()` will log a warning: "Active navbar config ID not found, falling back to default"
- Fallback behavior: uses `isDefault` config or first available config

### Order Field Validation

- `min: 1` ensures no zero or negative values
- Default of `10` provides room to insert configs before/after without renumbering all

---

## Implementation Order

### Phase 1: Backend (Database + AppParam)

1. EDIT `entityDefs/SidenavConfig.json` - Add fields, update collection orderBy, add index
2. Run `php command.php rebuild` to create database columns and index
3. EDIT `TeamSidenavConfigs.php` - Add filter and update ordering

### Phase 2: UI Layouts

4. EDIT `detail.json` - Add order and isDisabled fields
5. EDIT `list.json` - Add order and isDisabled columns
6. EDIT `detailSmall.json` - Add order and isDisabled fields

### Phase 3: Translations

7. EDIT `SidenavConfig.json` (i18n) - Add field labels and tooltips

### Phase 4: Testing

8. Test ordering: create 3+ configs with different order values, verify dropdown order
9. Test disable: disable a config, verify it disappears from selector
10. Test active config disabled: set active config, disable it, verify fallback
11. Test list view: verify order and isDisabled columns display correctly
12. Test detail view: verify fields save correctly

---

## Summary of File Count

| Category | Count |
|----------|-------|
| EDIT | 6 files |
| CONSIDER | 2 files |
| Reference | 5 files |

---

**Existing Data Behavior:**
- All existing SidenavConfig records will get `order = 10` (default)
- All existing SidenavConfig records will get `isDisabled = false` (default)
- Since all have same order value, they will be secondarily sorted by `name` alphabetically