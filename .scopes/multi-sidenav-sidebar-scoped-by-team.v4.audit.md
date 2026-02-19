# Multi-Sidenav Sidebar Scoped by Team - v4 Audit

> **Audited**: 2026-02-18  
> **Scope Version**: 4.0  
> **Auditor**: Claude Code  
> **Verdict**: READY TO IMPLEMENT

---

## Summary

**Risk Level:** Low  
**Files Reviewed:** 30+ referenced files  
**Findings:** Critical: 0 | Warnings: 1 | Suggestions: 1

The v4 scope document successfully addresses all critical findings from v3. The bidirectional Team.json link has been added, and all prior warnings have been resolved. The architecture is sound and follows established codebase patterns.

---

## Readiness Assessment

**Verdict:** **READY TO IMPLEMENT**

The design is sound. All critical findings from v3 have been properly addressed. The remaining warning is about ACL configuration which can be addressed via role management without code changes.

---

## Circular Rework Detection

No circular rework detected. The progression across versions shows consistent forward momentum:

| Version | Key Changes | Status |
|---------|-------------|--------|
| v1 → v2 | Added backend AppParam, clientDefs, Configurations.json | ✅ Locked |
| v2 → v3 | Added explicit links section, BeforeSave hook specification | ✅ Locked |
| v3 → v4 | Added Team.json edit for bidirectional link | ✅ Locked |

All decisions have been locked and are not being revisited.

---

## Critical Findings (MUST address before implementation)

*None - all prior critical findings have been properly resolved.*

---

## Warnings (SHOULD address)

### 1. No aclDefs File Listed Despite ACL Expectations

- **Location:** Files to CREATE section
- **Evidence:**
  - Decision #14 states: "ACL: table-level with admin-only create"
  - Scope metadata includes `"acl": "table"`
  - ACL Expectations section says "Regular users: read access only"
  - Reference pattern `MsxGoogleCalendarUser.json` has corresponding aclDefs file at `metadata/aclDefs/MsxGoogleCalendarUser.json` with `{"read": "team", "edit": "team", "delete": "team"}`
  - No aclDefs file is listed in Files to CREATE
- **Assumption:** The scope assumes default ACL behavior or role-based configuration will achieve the expected permissions
- **Concern:** Without an aclDefs file, regular users default to NO access. Admins would need to manually configure roles to grant read access. If read access is not granted, users won't see team configs in the selector.
- **Remedy (choose one):**
  1. **Add aclDefs file** to Files to CREATE:
     ```
     custom/Espo/Modules/Global/Resources/metadata/aclDefs/SidenavConfig.json
     ```
     With content:
     ```json
     {
         "read": "team",
         "edit": "no",
         "delete": "no",
         "create": "no",
         "stream": false
     }
     ```
  2. **OR** document in scope that admin must configure roles to grant read access to SidenavConfig for regular users

---

## Suggestions (CONSIDER addressing)

### 1. Migration Script for Backward Compatibility

- **Context:** Files to CONSIDER section mentions migration
- **Observation:** Existing `Settings.navbarConfigList` data will be orphaned. Organizations with existing navbar configurations will lose them after upgrade.
- **Enhancement:** Add InstallAction or after-upgrade script to:
  1. Check if `Settings.navbarConfigList` has data
  2. Find or create a default team
  3. Migrate each config to SidenavConfig records linked to that team

---

## Validated Items

The following aspects of the plan are well-supported with evidence:

- ✅ **Funnel.json entity definition pattern** - Verified at `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Funnel.json`, structure matches scope
- ✅ **EnsureSingleDefault.php hook pattern** - Verified at `Hooks/Funnel/EnsureSingleDefault.php`, exact code structure matches
- ✅ **Team.json current structure** - Verified contains `funnels` link only, ready for `sidenavConfigs` addition
- ✅ **Team.json bidirectional edit** - Properly listed in Files to EDIT section with correct structure
- ✅ **ChatwootSsoUrl.php AppParam pattern** - Verified constructor injection and `get()` method signature
- ✅ **AppParam interface** - Verified at `application/Espo/Tools/App/AppParam.php`
- ✅ **teamsIds in AppService** - Line 76 of AppService.php confirms `teamsIds` is returned in user app params
- ✅ **appParams.json is empty `{}`** - Ready for the new entry
- ✅ **Chatwoot appParams.json pattern** - Verified structure with `className` key
- ✅ **adminForUserPanel.json structure** - Verified with `users`, `data`, `sales` keys
- ✅ **admin-for-user/index.js translation scope** - Uses `"Configurations"` scope for translations
- ✅ **Configurations.json translation pattern** - Verified from Chatwoot module with `labels`, `descriptions`, `keywords`
- ✅ **Settings.json current fields** - `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled` verified
- ✅ **Preferences.json current fields** - `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId` verified
- ✅ **navbar.js current implementation** - `getNavbarConfigList()`, `getActiveNavbarConfig()`, `shouldShowConfigSelector()` verified
- ✅ **active-navbar-config.js current implementation** - `setupOptions()` and listeners verified
- ✅ **Settings/userInterface.json layout** - Rows 31-36 verified for Navbar section
- ✅ **Preferences/detail.json layout** - Rows 123-137 verified for navbar config section
- ✅ **Global.json navbarConfig translations** - `defaultConfig: "Default"` translation exists
- ✅ **Funnel detailSmall.json pattern** - Verified for layout structure reference
- ✅ **MsxGoogleCalendarUser clientDefs pattern** - Verified `controller`, `iconClass`, `defaultSidePanelFieldLists`
- ✅ **Files to DELETE verified** - All 3 files exist:
  - `views/settings/fields/navbar-config-list.js` ✅
  - `views/preferences/fields/navbar-config-list.js` ✅
  - `views/settings/modals/edit-navbar-config.js` ✅

---

## Implementation-Time Watchpoints

When implementing, be aware of:

1. **Hook auto-discovery**: Hooks are auto-discovered by directory convention (`Hooks/EntityName/HookClass.php`). No explicit registration required. (Documented in v4 scope)

2. **Configurations.json is NEW**: The `i18n/en_US/Configurations.json` in Global module is a new file, NOT an extension of Chatwoot's file. (Documented in v4 scope)

3. **Index includes `deleted` column**: The SidenavConfig `teamId` index includes `deleted` column (more complete than Funnel pattern which omits it)

---

## Recommended Next Steps

1. **[Optional - Warning]** Decide on ACL approach:
   - Option A: Add `aclDefs/SidenavConfig.json` file to manifest with `"read": "team"` and `"edit/delete/create": "no"`
   - Option B: Document that role configuration is required for read access

2. **[Optional - Enhancement]** Consider adding migration script guidance if backward compatibility is important for existing users

---

## v4 Audit Resolution Summary

| Finding Type | Description | Resolution |
|--------------|-------------|------------|
| **Critical (v3)** | Missing Team.json edit for bidirectional link | ✅ RESOLVED - Added to Files to EDIT section |
| **Warning (v3)** | Configurations.json file clarification | ✅ RESOLVED - Note added that this is a NEW file in Global module |
| **Warning (v3)** | Hook auto-discovery not documented | ✅ RESOLVED - Implementation note added in BeforeSave hook section |
| **Suggestion (v3)** | ACL guidance | ⚠️ PARTIAL - ACL expectations documented but no aclDefs file included |
| **Warning (v4)** | No aclDefs file listed | 🔶 PENDING - Choose Option A (add file) or Option B (role config) |

---

*Audit complete. Scope is ready for implementation.*
