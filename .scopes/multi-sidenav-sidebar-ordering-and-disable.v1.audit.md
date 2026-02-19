## Audit Summary

**Risk Level:** Medium
**Files Reviewed:** 15 files (6 to edit, 4 reference patterns, 5 related files)
**Findings:** Critical: 1 | Warnings: 3 | Suggestions: 2

The scope is well-structured with accurate file references and sound architectural decisions. However, there is one critical edge case around the interaction between `isDisabled` and `isDefault` fields that requires attention.

---

## Readiness Assessment

**Verdict:** NEEDS REVISION

One design-level issue requires resolution before implementation: the `EnsureSingleDefault` hook doesn't account for disabled configs, which could lead to a disabled config being the team's default.

---

## Critical Findings (MUST address before implementation)

### 1. EnsureSingleDefault Hook Doesn't Handle Disabled Configs

- **Location:** `custom/Espo/Modules/Global/Hooks/SidenavConfig/EnsureSingleDefault.php`
- **Evidence:** The hook queries for `isDefault: true` configs (line 56) but doesn't check `isDisabled`. A disabled config can remain `isDefault: true` and will be hidden from the AppParam, but another config won't automatically become the default.
- **Assumption:** The scope assumes the `EnsureSingleDefault` hook behavior is unaffected by the new `isDisabled` field.
- **Risk:** If an admin disables the only default config for a team:
    1. The disabled config remains `isDefault: true` in the database
    2. The AppParam filters it out (returns empty or other configs)
    3. `getActiveNavbarConfig()` falls back to first available config (line 168)
    4. The `isDefault` flag becomes meaningless for that team
- **Remedy:** Choose one approach:
    1. **Add hook logic:** When `isDisabled` is set to `true`, unset `isDefault` and promote another config to default (if any exists for overlapping teams)
    2. **Add validation:** Prevent saving a config with both `isDisabled: true` AND `isDefault: true`
    3. **Document behavior:** Explicitly state in scope that disabled defaults remain in DB but are ignored by AppParam, and fallback behavior is intentional

---

## Warnings (SHOULD address)

### 1. No `filters.json` for SidenavConfig

- **Location:** `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/`
- **Evidence:** OpportunityStage has `filters.json` with `isActive` filter (line 3-4 of `layouts/OpportunityStage/filters.json`). Funnel also has `filters.json` with `isActive`.
- **Concern:** Admins won't have a quick way to filter SidenavConfig list by `isDisabled` status.
- **Suggestion:** Consider adding a `filters.json` file with `isDisabled` filter, or document that filtering isn't needed for admin-only record management.

### 2. Missing `listSmall.json` Layout

- **Location:** `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/`
- **Evidence:** OpportunityStage has `listSmall.json` with `order` and `isActive` fields (lines 8-17). SidenavConfig only has `list.json`, `detail.json`, `detailSmall.json`.
- **Concern:** If SidenavConfig is ever displayed in relationship panels on other entities, the small list view won't show `order` or `isDisabled` fields.
- **Suggestion:** Either add `listSmall.json` or document that SidenavConfig is never shown in relationship panels (which appears to be the case based on the `scopes/SidenavConfig.json` not having relationship definitions).

### 3. No Bool/Primary Filter Classes for isDisabled

- **Location:** `custom/Espo/Modules/Global/Classes/Select/SidenavConfig/` (doesn't exist)
- **Evidence:** OpportunityStage has `Classes/Select/OpportunityStage/BoolFilters/OnlyActive.php` and `Classes/Select/OpportunityStage/PrimaryFilters/Active.php` that filter by `isActive: true`.
- **Concern:** Without these filter classes, the `clientDefs` won't be able to configure default filters like OpportunityStage does (see `clientDefs/OpportunityStage.json` lines 6-12).
- **Suggestion:** If admins need to quickly filter to see only active configs, add similar filter classes. Otherwise, document as intentional omission for admin-only records.

---

## Suggestions (CONSIDER addressing)

### 1. Clarify `isDisabled` + `isDefault` Mutual Exclusivity

- **Context:** The scope mentions that disabled configs are hidden from selector, but doesn't explicitly state whether `isDisabled: true` and `isDefault: true` should be allowed together.
- **Observation:** The scope says disabled configs are "hidden from the selector while preserving the record" but doesn't address the default flag behavior.
- **Enhancement:** Add explicit decision in the Decisions table:
    - "Disabled configs can/cannot be marked as default"
    - If cannot, specify whether this is enforced via validation or hook

### 2. Consider Index on `isDisabled` Column

- **Context:** The scope adds an index on `order` but not on `isDisabled`.
- **Observation:** Funnel has an index on `isActive` (line 82-84 of `entityDefs/Funnel.json`). However, boolean columns typically have low cardinality and may not benefit from indexing.
- **Enhancement:** The current decision is sound (no index on `isDisabled`), but could be explicitly noted in Decisions for completeness.

---

## Validated Items

The following aspects of the plan are well-supported:

- **File existence verified:** All 6 files to edit exist at expected paths
- **Current content matches:** `entityDefs/SidenavConfig.json` has correct `fields`, `collection`, and `indexes` structure; `TeamSidenavConfigs.php` query matches lines 54-60 description
- **Reference pattern correct:** `OpportunityStage.json` has exact `order` field structure: `"type": "int", "default": 10, "min": 1, "tooltip": true` (lines 10-14)
- **Layouts match:** `detail.json`, `list.json`, `detailSmall.json` current content matches scope description
- **Translations structure verified:** `SidenavConfig.json` (i18n) has correct `fields` and `tooltips` structure
- **Fallback behavior verified:** `navbar.js` `getActiveNavbarConfig()` correctly falls back to `configList.find(c => c.isDefault) || configList[0]` and ultimately to `getLegacyTabList()` when no configs available
- **Rebuild command correct:** EspoCRM uses `php command.php rebuild` for schema changes (no migration files needed)

---

## Recommended Next Steps

1. **CRITICAL:** Decide on `isDisabled` + `isDefault` interaction and update scope with one of:
    - Hook modification to unset `isDefault` when `isDisabled` is set
    - Validation rule to prevent both being true
    - Explicit documentation that this edge case is handled by fallback behavior

2. **RECOMMENDED:** Add decision #8 to Decisions table documenting the `isDisabled` + `isDefault` behavior

3. **OPTIONAL:** If admin workflow needs filtering, add `filters.json` with `isDisabled` filter

