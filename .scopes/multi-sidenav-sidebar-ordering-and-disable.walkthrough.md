# Multi-Sidenav Sidebar Ordering and Disable — Implementation Walkthrough

> **Scope**: v3  
> **Codebase Root**: `components/crm/source/`  
> **Status**: IMPLEMENTED

## What Changed

Added two new fields to `SidenavConfig`: `order` (int, default 10) and `isDisabled` (bool, default false). The selector dropdown now displays configs sorted by `order` ASC then `name` ASC. Disabled configs are filtered out server-side in the AppParam query. Validation prevents marking a disabled config as default.

---

## File Manifest

### Files CREATED (1)

| # | Path | Purpose |
|---|------|---------|
| 1 | `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/filters.json` | List view filter for isDisabled |

### Files EDITED (7)

| # | Path | What changed |
|---|------|--------------|
| 1 | `custom/Espo/Modules/Global/Hooks/SidenavConfig/EnsureSingleDefault.php` | Added BadRequest import + validation for disabled+default |
| 2 | `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json` | Added order/isDisabled fields, collection orderBy, index |
| 3 | `custom/Espo/Modules/Global/Classes/AppParams/TeamSidenavConfigs.php` | Added isDisabled filter + order by clause |
| 4 | `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detail.json` | Added order and isDisabled fields in row 2 |
| 5 | `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/list.json` | Added order and isDisabled columns |
| 6 | `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detailSmall.json` | Added order and isDisabled fields |
| 7 | `custom/Espo/Modules/Global/Resources/i18n/en_US/SidenavConfig.json` | Added field labels and tooltips |

---

## File-by-File Walkthrough

### 1. `custom/Espo/Modules/Global/Hooks/SidenavConfig/EnsureSingleDefault.php`

**Edited.** Added validation to prevent a disabled config from being marked as default.

Changes:
- Added `use Espo\Core\Exceptions\BadRequest;` import
- Added early validation at the start of `beforeSave()` that throws if both `isDisabled` and `isDefault` are true

```php
use Espo\Core\Exceptions\BadRequest;

public function beforeSave(Entity $entity, SaveOptions $options): void
{
    if ($entity->get('isDisabled') && $entity->get('isDefault')) {
        throw new BadRequest('A disabled configuration cannot be marked as default.');
    }

    if (!$entity->get('isDefault')) {
        return;
    }
    // ... rest of existing logic
}
```

---

### 2. `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json`

**Edited.** Added `order` and `isDisabled` fields, updated collection default sorting, added index.

**order field:**
```json
"order": {
    "type": "int",
    "default": 10,
    "min": 1,
    "tooltip": true
}
```

**isDisabled field:**
```json
"isDisabled": {
    "type": "bool",
    "default": false,
    "tooltip": true
}
```

**Collection orderBy changed from `name` to `order`:**
```json
"collection": {
    "orderBy": "order",
    "order": "asc"
}
```

**Index added:**
```json
"indexes": {
    "order": {
        "columns": ["order"]
    }
}
```

---

### 3. `custom/Espo/Modules/Global/Classes/AppParams/TeamSidenavConfigs.php`

**Edited.** Query now filters out disabled configs and orders by `order` then `name`.

Before:
```php
$configs = $this->entityManager
    ->getRDBRepository('SidenavConfig')
    ->distinct()
    ->join('teams')
    ->where(['teams.id' => $teamIds])
    ->order('name')
    ->find();
```

After:
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

---

### 4. `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detail.json`

**Edited.** Added `order` and `isDisabled` in the second row for admin prominence.

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

---

### 5. `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/list.json`

**Edited.** Added `order` column first and `isDisabled` at the end.

```json
[
    {"name": "order", "width": 10},
    {"name": "name", "width": 25},
    {"name": "teams", "width": 35},
    {"name": "isDefault", "width": 12},
    {"name": "isDisabled", "width": 12}
]
```

---

### 6. `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detailSmall.json`

**Edited.** Added `order` and `isDisabled` in row 3.

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

### 7. `custom/Espo/Modules/Global/Resources/i18n/en_US/SidenavConfig.json`

**Edited.** Added field labels and tooltips.

```json
{
    "fields": {
        "order": "Order",
        "isDisabled": "Disabled"
    },
    "tooltips": {
        "order": "Controls the position of this configuration in the selector dropdown. Lower numbers appear first.",
        "isDisabled": "If checked, this configuration will be hidden from the selector dropdown but the record will be preserved."
    }
}
```

---

### 8. `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/filters.json`

**Created.** Provides quick filter toggle for `isDisabled` in the list view.

```json
["name", "teams", "isDisabled"]
```

---

## Data Flow

```
Admin creates/edits SidenavConfig
    → Sets order (default 10, min 1)
    → Can toggle isDisabled
    → If isDisabled + isDefault both true → Hook throws BadRequest
    → Database stores order and isDisabled columns

User loads app
    → /api/v1/App/user calls TeamSidenavConfigs AppParam
    → Query filters: teams.id IN (user teams) AND isDisabled = false
    → Query orders: order ASC, name ASC
    → Frontend receives filtered, ordered config list

Navbar renders
    → Selector dropdown shows configs in order sequence
    → Disabled configs never appear
    → If user's activeNavbarConfigId points to disabled config
      → Fallback: isDefault config → first config → legacy tabList
```

---

## Validation Rules

| Rule | Error |
|------|-------|
| `isDisabled: true` + `isDefault: true` | "A disabled configuration cannot be marked as default." |
| `order < 1` | Field validation prevents save (min: 1) |

---

## Deployment

After deploying the files:

```bash
php command.php rebuild
```

This creates the `order` and `is_disabled` columns in `sidenav_config` table, adds the index on `order`, and rebuilds metadata.

---

## Testing Checklist

| Test | Expected Result |
|------|-----------------|
| Create 3 configs with order 10, 20, 5 | Dropdown shows: 5, 10, 20 |
| Disable a config | Disappears from dropdown |
| Set active config, then disable it | Falls back to default or first config |
| Check both disabled and default | Error: "A disabled configuration cannot be marked as default." |
| List view | Order column visible, isDisabled filter works |
| Create config without order | Defaults to 10 |
