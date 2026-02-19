# Multi-Sidenav Sidebar Scoped by Team - v3 Audit

> **Audited**: 2026-02-18  
> **Scope Version**: 3.0  
> **Auditor**: Claude Code  
> **Verdict**: NEEDS REVISION

---

## Summary

**Risk Level:** Medium  
**Files Reviewed:** 22 referenced files  
**Findings:** Critical: 1 | Warnings: 2 | Suggestions: 2

The scope document is comprehensive and well-structured with strong reference patterns. The v3 version has addressed prior audit findings including explicit links section structure and BeforeSave hook specification. However, one design-level gap remains: the Team entity needs modification to complete the bidirectional link relationship.

---

## Readiness Assessment

**Verdict:** NEEDS REVISION

One design-level issue must be resolved before implementation. The remaining items are well-specified and the patterns are validated.

---

## Critical Findings (MUST address before implementation)

### 1. Missing Team.json Edit for Bidirectional Link

- **Location:** `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Team.json`
- **Evidence:**
  - Decision #12 states: "Add `foreign` link to Team relationship... Enables bidirectional navigation (`Team.sidenavConfigs`)"
  - Current `Team.json` contains `funnels` link for Funnel entity but NO file edit is listed in the manifest
  - The Funnel pattern (referenced throughout) requires BOTH sides of the relationship
- **Assumption:** The scope assumes Team will automatically have a `sidenavConfigs` link without editing Team.json
- **Risk:** Without the inverse link, `Team.sidenavConfigs` navigation will not work, breaking the bidirectional navigation that Decision #12 explicitly endorses
- **Remedy:** Add Team.json to the "Files to EDIT" section:

```json
// Add to custom/Espo/Modules/Global/Resources/metadata/entityDefs/Team.json links:
"sidenavConfigs": {
    "type": "hasMany",
    "entity": "SidenavConfig",
    "foreign": "team"
}
```

---

## Warnings (SHOULD address)

### 1. Configurations.json File is NEW, Not an Edit

- **Location:** `custom/Espo/Modules/Global/Resources/i18n/en_US/Configurations.json`
- **Evidence:** File does not exist at the specified path (verified via glob search)
- **Concern:** The file is listed as item #12 in "Files to CREATE" but the description says "Panel labels for adminForUser" without explicitly noting this is a NEW file vs extending Chatwoot's existing Configurations.json
- **Suggestion:** Clarify in the manifest that this is a new file creation in the Global module (distinct from Chatwoot's version). The content structure is correct.

### 2. Hook Auto-Discovery Not Explicitly Documented

- **Location:** `custom/Espo/Modules/Global/Hooks/SidenavConfig/EnsureSingleDefault.php`
- **Evidence:** EspoCRM hooks are auto-discovered by directory convention (`Hooks/EntityName/HookClass.php`), but this is not stated in the scope
- **Concern:** Implementers unfamiliar with EspoCRM hook discovery may wonder if registration is required
- **Suggestion:** Add a note that hooks follow EspoCRM's auto-discovery pattern and require no explicit registration

---

## Suggestions (CONSIDER addressing)

### 1. Consider Migration Script for Backward Compatibility

- **Context:** The "Files to CONSIDER" section mentions migration but doesn't provide guidance
- **Observation:** Existing `Settings.navbarConfigList` data will be orphaned after this change
- **Enhancement:** Create an InstallAction or after-upgrade script that:
  1. Checks if `Settings.navbarConfigList` has data
  2. Finds or creates a default team
  3. Migrates configs to SidenavConfig records linked to that team

### 2. Add ACL Guidance for SidenavConfig

- **Context:** The scope definition uses `"acl": "table"` but no ACL table entries are defined
- **Observation:** The admin-for-user panel filters by ACL read permission, but default ACL for SidenavConfig is not specified
- **Enhancement:** Document expected ACL behavior:
  - Who can create SidenavConfig records?
  - Should team admins be able to create configs for their team only?
  - Add sample ACL table entries if needed

---

## Validated Items

The following aspects of the plan are well-supported with evidence:

- ✅ **Funnel.json pattern** - Entity definition structure matches exactly (lines 46-70 for links)
- ✅ **EnsureSingleDefault.php hook pattern** - Exact code structure verified in `Hooks/Funnel/EnsureSingleDefault.php`
- ✅ **ChatwootSsoUrl.php AppParam pattern** - Constructor injection pattern and `get()` method signature verified
- ✅ **navbar.js current implementation** - All referenced methods exist (`getNavbarConfigList`, `getActiveNavbarConfig`, `shouldShowConfigSelector`, `switchNavbarConfig`)
- ✅ **active-navbar-config.js current implementation** - `setupOptions()` and `getResolvedConfigList()` exist as described
- ✅ **Settings.json/Preferences.json current state** - Fields match what's described for deletion
- ✅ **Files to DELETE all exist** - Verified existence of all 3 deprecated files
- ✅ **adminForUserPanel.json structure** - Current structure matches expected edit pattern
- ✅ **appParams.json is empty `{}`** - Ready for the new entry
- ✅ **ClientDefs pattern from MsxGoogleCalendarUser** - Structure verified in PackEnterprise module
- ✅ **Configurations.json translation pattern** - Verified from Chatwoot module
- ✅ **Funnel detailSmall.json layout** - Pattern matches proposed SidenavConfig detailSmall.json structure
- ✅ **Team.json custom entityDefs exists** - Already contains `funnels` link, confirming the extension pattern

---

## Circular Rework Detection

No circular rework detected. The v3 scope shows consistent progression:
- v1 → v2: Added backend AppParam, clientDefs metadata
- v2 → v3: Added explicit links section, BeforeSave hook specification, pattern-based layout references

All decisions have been locked and are not being revisited.

---

## Recommended Next Steps

1. **[Critical]** Add `Team.json` edit to the file manifest to complete the bidirectional link relationship
2. **[Warning]** Add a note clarifying that `Configurations.json` in Global module is a new file (not extending Chatwoot's)
3. **[Warning]** Add a brief note about EspoCRM hook auto-discovery for the BeforeSave hook
4. **[Optional]** Consider adding ACL guidance for SidenavConfig entity access control

---

*Audit generated from comprehensive analysis of 22 referenced files and 120+ grep matches*
