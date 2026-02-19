## v2 Scope - Multi-Sidenav Sidebar Ordering and Disable

Based on my analysis of the v1 scope, audit findings, and the existing codebase patterns, here is the complete file manifest for the v2 implementation:

## Decisions

| #   | Decision                                                             | Alternatives Considered                     | Rationale                                                                                      |
| --- | -------------------------------------------------------------------- | ------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| 1   | Add `order` field (int, default 10)                                  | Use `sortOrder`, `position`, `priority`     | Matches `OpportunityStage.order` pattern exactly                                               |
| 2   | Add `isDisabled` field (bool, default false)                         | Use `isActive` (inverted logic)             | `isDisabled` aligns with UI intent - "disabled" state is explicit                              |
| 3   | Filter disabled configs server-side in AppParam                      | Filter client-side in navbar.js             | Server-side filtering is more efficient, cleaner separation                                    |
| 4   | Order by `order` field ASC, then `name` ASC                          | Order by `order` only                       | Provides stable secondary sort for configs with same order value                               |
| 5   | Update collection default orderBy from `name` to `order`             | Keep collection orderBy as `name`           | Makes list views and other queries use programmatic order by default                           |
| 6   | Show `order` and `isDisabled` in list view                           | Show only in detail view                    | Admins need quick visibility of ordering/disabled status                                       |
| 7   | Add index on `order` column                                          | No index                                    | Small perf improvement for AppParam query, matches OpportunityStage pattern                    |
| 8   | **Add validation to prevent `isDisabled: true` + `isDefault: true`** | Hook logic to auto-unset; Document fallback | Explicit error is clearest UX - prevents admin from creating invalid state                     |
| 9   | **Add `filters.json` with `isDisabled` filter**                      | Skip filtering                              | Admin workflow benefits from quick filter toggle in list view                                  |
| 10  | **Skip `listSmall.json`**                                            | Add layout                                  | SidenavConfig is never shown in relationship panels (no relationships in scope)                |
| 11  | **Skip Bool/Primary filter classes**                                 | Add filter classes                          | `filters.json` provides sufficient admin filtering; classes are overkill for admin-only entity |

---

## File Manifest

### Files to EDIT (ordered by complexity/risk, highest first)

#### 1. `custom/Espo/Modules/Global/Hooks/SidenavConfig/EnsureSingleDefault.php` - **CRITICAL**

**Purpose:** Add validation to prevent a disabled config from being marked as default.

**Why it's complex:** This hook already manages the single-default-per-team constraint. Adding the `isDisabled` check requires understanding the hook's execution flow and ensuring the validation message is clear.

**Changes:**

- Add early check: if `isDisabled` is true and `isDefault` is true, throw a `BadRequest` exception with a clear error message
- This prevents the invalid state at the data layer

**Reference pattern:** The Funnel EnsureSingleDefault hook doesn't handle `isActive`, but for SidenavConfig we want explicit validation.

**Key code pattern (add before line 36):**

```php
// Prevent disabled configs from being default
if ($entity->get('isDisabled') && $entity->get('isDefault')) {
    throw new \Espo\Core\Exceptions\BadRequest('A disabled configuration cannot be marked as default.');
}
```

---

#### 2. `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json` - **CRITICAL**

**Purpose:** Add `order` and `isDisabled` fields, update collection orderBy, add index.

**Changes:**

Add to `fields` object (after `name` field):

```json
"order": {
    "type": "int",
    "default": 10,
    "min": 1,
    "tooltip": true
}
```

Add to `fields` object (after `isDefault` field):

```json
"isDisabled": {
    "type": "bool",
    "default": false,
    "tooltip": true
}
```

Update `collection` object:

```json
"collection": {
    "orderBy": "order",
    "order": "asc"
}
```

Add to `indexes` object:

```json
"order": {
    "columns": ["order"]
}
```

**Reference pattern:** `OpportunityStage.json` has identical `order` field structure (lines 10-14).

---

#### 3. `custom/Espo/Modules/Global/Classes/AppParams/TeamSidenavConfigs.php` - **CRITICAL**

**Purpose:** Filter out disabled configs and apply programmatic ordering.

**Changes to the query (replace lines 54-60):**

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

#### 4. `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detail.json` - **MEDIUM**

**Current content:** 4 rows in Overview panel
**Updated content:** 5 rows with `order` and `isDisabled` added early for admin prominence

```json
[
    {
        "label": "Overview",
        "rows": [
            [{ "name": "name" }, { "name": "teams" }],
            [{ "name": "order" }, { "name": "isDisabled" }],
            [{ "name": "iconClass" }, { "name": "color" }],
            [{ "name": "isDefault" }, false],
            [{ "name": "tabList", "fullWidth": true }]
        ]
    }
]
```

---

#### 5. `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/list.json` - **MEDIUM**

**Current content:** 3 columns (name, teams, isDefault)
**Updated content:** 5 columns with `order` first and `isDisabled` at end

```json
[
    { "name": "order", "width": 10 },
    { "name": "name", "width": 25 },
    { "name": "teams", "width": 35 },
    { "name": "isDefault", "width": 12 },
    { "name": "isDisabled", "width": 12 }
]
```

---

#### 6. `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detailSmall.json` - **LOW**

**Current content:** 3 rows (name, teams, isDefault)
**Updated content:** 4 rows with `order` and `isDisabled`

```json
[
    {
        "label": "Overview",
        "rows": [
            [{ "name": "name" }],
            [{ "name": "teams" }],
            [{ "name": "order" }, { "name": "isDisabled" }],
            [{ "name": "isDefault" }]
        ]
    }
]
```

---

#### 7. `custom/Espo/Modules/Global/Resources/i18n/en_US/SidenavConfig.json` - **LOW**

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

---

### Files to CREATE

#### 8. `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/filters.json` - **LOW**

**Purpose:** Provide quick filter toggle for `isDisabled` in the list view.

**Content:**

```json
["name", "teams", "isDisabled"]
```

**Reference pattern:** `layouts/Funnel/filters.json` uses `isActive`; `layouts/OpportunityStage/filters.json` uses `isActive`.

---

### Files to CONSIDER

| File Path                                                                   | Reason                                                                                                                                                          |
| --------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/listSmall.json` | **NOT RECOMMENDED** - SidenavConfig has no relationships to other entities, so it's never displayed in relationship panels. Audit confirms this is intentional. |

---

### Related Files (for reference only, no changes needed)

| File Path                                                                        | Pattern Reference                                                                                                                     |
| -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `client/custom/modules/global/src/views/site/navbar.js`                          | Uses `getNavbarConfigList()` which reads from AppParam - already handles fallback when configs are filtered out (line 168)            |
| `client/custom/modules/global/src/views/site/navbar-config-selector.js`          | Uses `configList` from parent - no changes needed                                                                                     |
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/OpportunityStage.json` | Reference for `order` field structure (int, default 10, min 1, tooltip)                                                               |
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Funnel.json`           | Reference for `isActive` field pattern; also has index on `isActive` (not needed for `isDisabled`)                                    |
| `custom/Espo/Modules/Global/Resources/metadata/clientDefs/Funnel.json`           | Reference for filter configuration (boolFilterList, filterList, defaultFilterData) - NOT used for SidenavConfig since it's admin-only |
| `custom/Espo/Modules/Global/Hooks/Funnel/EnsureSingleDefault.php`                | Reference for hook structure; note that Funnel hook does NOT check `isActive`                                                         |

---

## Error Handling

### Disabled + Default Validation

If an admin attempts to save a config with both `isDisabled: true` AND `isDefault: true`:

- The `EnsureSingleDefault` hook throws a `BadRequest` exception
- Admin sees error: "A disabled configuration cannot be marked as default."
- Admin must either uncheck "Disabled" or uncheck "Default" before saving

### Active Config Becomes Disabled

If a user's `activeNavbarConfigId` points to a config that is subsequently disabled:

- The AppParam will not include the disabled config
- `getActiveNavbarConfig()` logs warning: "Active navbar config ID not found, falling back to default"
- Fallback chain (line 168 in navbar.js): `isDefault` config → first available config → legacy tabList

### Order Field Validation

- `min: 1` ensures no zero or negative values
- Default of `10` provides room to insert configs before/after without renumbering all

---

## Implementation Order

### Phase 1: Backend (Database + AppParam + Validation)

1. **EDIT** `entityDefs/SidenavConfig.json` - Add fields, update collection orderBy, add index
2. Run `php command.php rebuild` to create database columns and index
3. **EDIT** `TeamSidenavConfigs.php` - Add filter and update ordering
4. **EDIT** `EnsureSingleDefault.php` - Add validation for disabled+default

### Phase 2: UI Layouts

5. **EDIT** `detail.json` - Add order and isDisabled fields
6. **EDIT** `list.json` - Add order and isDisabled columns
7. **EDIT** `detailSmall.json` - Add order and isDisabled fields
8. **CREATE** `filters.json` - Add isDisabled filter

### Phase 3: Translations

9. **EDIT** `SidenavConfig.json` (i18n) - Add field labels and tooltips

### Phase 4: Testing

10. Test ordering: create 3+ configs with different order values, verify dropdown order
11. Test disable: disable a config, verify it disappears from selector
12. Test active config disabled: set active config, disable it, verify fallback
13. Test validation: attempt to save with both disabled AND default checked, verify error
14. Test list view: verify order and isDisabled columns display correctly
15. Test filters: verify isDisabled filter works in list view
16. Test detail view: verify fields save correctly

---

## Summary of File Count

| Category  | Count                    |
| --------- | ------------------------ |
| EDIT      | 7 files                  |
| CREATE    | 1 file                   |
| CONSIDER  | 1 file (not recommended) |
| Reference | 7 files                  |

---

## Changes from v1

| Change                                           | Reason                                                                  |
| ------------------------------------------------ | ----------------------------------------------------------------------- |
| **Added** `EnsureSingleDefault.php` to EDIT list | Addresses CRITICAL audit finding - validation prevents disabled+default |
| **Added** `filters.json` to CREATE list          | Addresses WARNING audit finding - admin workflow benefits from filter   |
| **Added** Decision #8                            | Documents the disabled+default validation choice                        |
| **Added** Decision #9                            | Documents the filters.json choice                                       |
| **Added** Decision #10                           | Documents decision to skip listSmall.json                               |
| **Added** Decision #11                           | Documents decision to skip filter classes                               |

