# Audit Report: Multi-Sidenav Sidebar Scoped by Team v1

**Audit Date:** 2026-02-18
**Scope Version:** v1
**Auditor:** Review Agent
**Files Reviewed:** 20+
**Findings:** Critical: 2 | Warnings: 5 | Suggestions: 3

---

## Audit Summary

**Risk Level:** Medium

The scope document is well-structured and follows established codebase patterns. However, there are **2 critical design-level gaps** that must be addressed before implementation:

1. **Missing Backend AppParam Implementation** - The scope relies on `teamSidenavConfigs` appParam but provides no implementation details for the backend service
2. **Missing clientDefs Metadata** - The new `SidenavConfig` entity needs clientDefs configuration for proper frontend behavior

The remaining issues are well-considered and the approach follows existing patterns correctly.

---

## Readiness Assessment

**Verdict:** NEEDS REVISION

The scope must be revised to address the two critical findings below. The architecture is sound, but the backend integration strategy is underspecified.

---

## Critical Findings (MUST address before implementation)

### 1. Missing Backend AppParam Implementation

- **Location:** Scope document lines 364, 423
- **Evidence:** 
  - The scope proposes using `this.getHelper().getAppParam('teamSidenavConfigs')` in navbar.js (lines 178, 479, 570)
  - The backend `AppService.php` (application/Espo/Tools/App/AppService.php:181-203) loads appParams from metadata `['app', 'appParams']`
  - Each appParam requires a `className` entry pointing to a class implementing `Espo\Tools\App\AppParam` interface
  - The Global module's `appParams.json` is currently empty: `{}`
- **Assumption:** The scope assumes `teamSidenavConfigs` will be available via appParam without specifying how
- **Risk:** Implementation will fail at runtime - the appParam will not exist, causing team configs to never load
- **Remedy:** Add to File Manifest:

  **CREATE:**
  - `custom/Espo/Modules/Global/Resources/metadata/app/appParams.json` (EDIT from `{}`):
    ```json
    {
        "teamSidenavConfigs": {
            "className": "Espo\\Modules\\Global\\Classes\\AppParams\\TeamSidenavConfigs"
        }
    }
    ```
  
  - `custom/Espo/Modules/Global/Classes/AppParams/TeamSidenavConfigs.php`:
    ```php
    <?php
    namespace Espo\Modules\Global\Classes\AppParams;
    
    use Espo\Tools\App\AppParam;
    use Espo\ORM\EntityManager;
    use Espo\Entities\User;
    
    class TeamSidenavConfigs implements AppParam
    {
        public function __construct(
            private User $user,
            private EntityManager $entityManager
        ) {}
    
        public function get(): mixed
        {
            // Load all SidenavConfig records for user's teams
            $teamIds = $this->user->getLinkMultipleIdList('teams');
            
            if (empty($teamIds)) {
                return [];
            }
            
            $configs = $this->entityManager
                ->getRDBRepository('SidenavConfig')
                ->where(['teamId' => $teamIds])
                ->find();
            
            $result = [];
            foreach ($configs as $config) {
                $result[] = [
                    'id' => $config->getId(),
                    'name' => $config->get('name'),
                    'teamId' => $config->get('teamId'),
                    'iconClass' => $config->get('iconClass'),
                    'color' => $config->get('color'),
                    'tabList' => $config->get('tabList'),
                    'isDefault' => $config->get('isDefault'),
                ];
            }
            
            return $result;
        }
    }
    ```

### 2. Missing clientDefs Metadata for SidenavConfig Entity

- **Location:** Scope document File Manifest (CREATE section)
- **Evidence:**
  - Examined `ChatwootInboxIntegration.json` in clientDefs (lines 1-164) shows entities need clientDefs for:
    - `controller` definition
    - `iconClass` for display
    - Optional `modalViews`, `dynamicLogic`, `viewSetupHandlers`
  - The scope only mentions entityDefs and scopes metadata, not clientDefs
- **Assumption:** The scope assumes default client behavior is sufficient
- **Risk:** Entity may not render correctly in adminForUser panel; missing icon; potential routing issues
- **Remedy:** Add to File Manifest:

  **CREATE:**
  - `custom/Espo/Modules/Global/Resources/metadata/clientDefs/SidenavConfig.json`:
    ```json
    {
        "controller": "controllers/record",
        "iconClass": "fas fa-bars"
    }
    ```

---

## Warnings (SHOULD address)

### 1. Configurations i18n File Not Mentioned

- **Location:** Scope document translations section (line 288)
- **Evidence:** 
  - The `admin-for-user/index.js` view (lines 74-78, 97-101) uses `this.translate(..., "Configurations")` for panel labels
  - Chatwoot module has `Configurations.json` with panel labels like "Chatwoot" → "Chat"
  - Global module has no `en_US/Configurations.json` file
- **Concern:** The new "Sidenav" panel label in adminForUser may not translate correctly
- **Suggestion:** Either:
  1. Add translations to `Global.json` under a "Configurations" scope key, OR
  2. Create `custom/Espo/Modules/Global/Resources/i18n/en_US/Configurations.json`:
     ```json
     {
         "labels": {
             "Sidenav": "Navigation",
             "Sidenav Configs": "Sidenav Configurations"
         },
         "descriptions": {
             "sidenavConfigs": "Configure team-specific navigation sidebars"
         },
         "keywords": {
             "sidenavConfigs": "navigation,sidebar,menu,tabs"
         }
     }
     ```

### 2. adminForUserPanel.json Edit Not Fully Specified

- **Location:** Scope document line 273
- **Evidence:** 
  - Current `adminForUserPanel.json` has keys: `users`, `data`, `sales` with `order` property
  - The scope says "Add 'sidenav' panel entry" but doesn't show the exact JSON structure
- **Concern:** Implementer may not know the correct `order` value or structure
- **Suggestion:** Provide exact JSON fragment:
  ```json
  "sidenav": {
      "label": "Sidenav",
      "itemList": [
          {
              "url": "#Configurations/SidenavConfig",
              "label": "Sidenav Configs",
              "iconClass": "fas fa-bars",
              "description": "sidenavConfigs"
          }
      ],
      "order": 10
  }
  ```

### 3. TabList Field View Compatibility

- **Location:** Scope document entityDefs SidenavConfig.json (line 111)
- **Evidence:** 
  - The scope uses `"view": "views/settings/fields/tab-list"` for the tabList field
  - Examined `views/settings/fields/tab-list.js` - it extends ArrayFieldView and uses `this.model.entityType` at line 325
  - The view should work with any entity model, not just Settings
- **Concern:** None - the view appears compatible. Just flagging for verification during implementation.
- **Suggestion:** During implementation, verify the tab-list field renders correctly in SidenavConfig edit modal

### 4. ACL/Permissions Not Specified

- **Location:** Scope document - not mentioned
- **Evidence:**
  - The scope definition uses `"acl": "table"` (following MsxGoogleCalendarUser pattern)
  - No mention of default ACL roles or who can create/edit SidenavConfig records
- **Concern:** Without explicit ACL configuration, only admins may be able to create configs by default
- **Suggestion:** Consider specifying:
  - Should team admins be able to create SidenavConfig for their team?
  - Is there a role-based permission needed?

### 5. No Mention of Database Migration

- **Location:** Scope document line 407-412
- **Evidence:** Scope explicitly says "No Migration Strategy Required (Default)"
- **Concern:** This is acceptable for new installations, but existing users with `navbarConfigList` in Settings will lose their configs
- **Suggestion:** Consider adding an optional migration script reference to the CONSIDER section:
  - Create `InstallActions` or a migration script that copies `Settings.navbarConfigList` to a default team's SidenavConfig

---

## Suggestions (CONSIDER addressing)

### 1. Add Index for teamId Field

- **Context:** The `teamSidenavConfigs` AppParam queries by `teamId`
- **Observation:** The entityDefs does not include an index for the `teamId` field
- **Enhancement:** Consider adding to entityDefs:
  ```json
  "indexes": {
      "teamId": {
          "columns": ["teamId", "deleted"]
      }
  }
  ```

### 2. Consider Caching Strategy

- **Context:** Team configs are loaded on every app load via appParam
- **Observation:** For systems with many teams/users, this could be called frequently
- **Enhancement:** Consider whether the AppParam result should be cached, or if it's acceptable to query on each app load

### 3. Validation for isDefault Per Team

- **Context:** Multiple configs per team with `isDefault` flag
- **Observation:** No validation that only one config per team has `isDefault=true`
- **Enhancement:** Consider adding a before-save hook to ensure only one default per team:
  - `custom/Espo/Modules/Global/Classes/RecordHooks/SidenavConfig/BeforeSave.php`

---

## Validated Items

The following aspects of the plan are well-supported by codebase evidence:

- ✅ **Entity pattern follows existing adminForUser entities** - ChatwootInboxIntegration and MsxGoogleCalendarUser confirmed as valid patterns
- ✅ **AppParam interface exists** - `Espo\Tools\App\AppParam` interface at application/Espo/Tools/App/AppParam.php
- ✅ **AppParams metadata pattern** - Confirmed via Chatwoot module's `appParams.json`
- ✅ **adminForUserPanel.json structure** - Existing structure validated
- ✅ **admin-for-user controller routing** - `getEntityTypeFromPage` extracts entity type from URL pattern `#Configurations/{EntityType}`
- ✅ **Scope definition pattern** - MsxGoogleCalendarUser.json confirms `acl: "table"`, `tab: false`, `hasTeams: true`
- ✅ **Preferences.activeNavbarConfigId field exists** - Confirmed in Preferences.json
- ✅ **navbar.js current implementation** - Current resolution logic confirmed (Settings/Preferences based)
- ✅ **Files to DELETE exist** - All three files confirmed to exist:
  - `views/settings/fields/navbar-config-list.js`
  - `views/preferences/fields/navbar-config-list.js`  
  - `views/settings/modals/edit-navbar-config.js`
- ✅ **Settings.json fields exist** - `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled` confirmed
- ✅ **Preferences.json fields exist** - `navbarConfigList`, `useCustomNavbarConfig`, `activeNavbarConfigId` confirmed
- ✅ **Layout row references are accurate** - Settings/userInterface.json and Preferences/detail.json row structures verified

---

## Recommended Next Steps

1. **Add backend AppParam implementation** to File Manifest (Critical Finding #1)
2. **Add clientDefs metadata** to File Manifest (Critical Finding #2)  
3. **Add Configurations.json i18n file** to File Manifest (Warning #1)
4. **Provide exact adminForUserPanel.json edit** in scope document (Warning #2)
5. **Consider adding teamId index** to entity definition (Suggestion #1)

---

*Audit complete. Scope requires revision before implementation.*
