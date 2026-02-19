# Multi-Sidenav Sidebar Scoped by Team - v4 File Manifest

> **Version**: 4.0  
> **Based on**: v3 scope + v3.audit findings  
> **Codebase Root**: `components/crm/source/`  
> **Status**: SCOPE MAPPED - READY FOR IMPLEMENTATION

## Overview

Move navbar configuration from User Interface (Settings/Preferences) to a team-scoped adminForUser panel. Each team can configure its own navbar tabLists, and users see configs from all teams they belong to.

### Key Changes from v3

| Finding                                                     | Resolution                                                            |
| ----------------------------------------------------------- | --------------------------------------------------------------------- |
| **Critical**: Missing Team.json edit for bidirectional link | Added Team.json to Files to EDIT with `sidenavConfigs` hasMany link   |
| **Warning**: Configurations.json clarification              | Added explicit note that this is a NEW file creation in Global module |
| **Warning**: Hook auto-discovery not documented             | Added implementation note about EspoCRM hook discovery convention     |
| **Suggestion**: ACL guidance                                | Added ACL expectations documentation in the scope definition          |

---

## Decisions

| #   | Decision                                                          | Alternatives Considered              | Rationale                                                                                                                         |
| --- | ----------------------------------------------------------------- | ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Create new `SidenavConfig` entity linked to Team                  | Add fields directly to Team entity   | Allows multiple configs per team, follows existing adminForUser entity patterns (ChatwootInboxIntegration, MsxGoogleCalendarUser) |
| 2   | User's `teams` determines which configs are available             | Use only `defaultTeam`               | Users get access to configs from ALL teams they belong to, providing flexibility for multi-team users                             |
| 3   | Keep `activeNavbarConfigId` in Preferences                        | Store in SidenavConfig or new entity | User's active selection is personal preference, follows existing pattern                                                          |
| 4   | Keep `navbarConfigShowDefaultTabList` in Settings                 | Add to SidenavConfig or Preferences  | System-level setting that applies globally, controlled by admin                                                                   |
| 5   | Remove Settings-level navbar config fields entirely               | Keep as fallback/global override     | Eliminates complexity of 3-level resolution (team → system → default)                                                             |
| 6   | Remove `useCustomNavbarConfig` and Preferences `navbarConfigList` | Keep for user-level override         | Team-based configs replace user-level customization                                                                               |
| 7   | Fallback to legacy `tabList` if no team configs exist             | Require configs for all users        | Backward compatible with existing installations                                                                                   |
| 8   | Admins without team see system `tabList`                          | Create implicit "Global" team        | Edge case - most users belong to at least one team                                                                                |
| 9   | Use special ID `__default_tablist__` for default tabList option   | Create pseudo-config record          | Simple, no database changes needed, easy to detect in resolution logic                                                            |
| 10  | Load team configs via AppParam `teamSidenavConfigs`               | AJAX call to custom endpoint         | AppParam loads with user session data, more efficient, follows Chatwoot pattern                                                   |
| 11  | Add index on `teamId` field                                       | No index                             | AppParam queries by teamId, improves performance for systems with many configs                                                    |
| 12  | Add `foreign` link to Team relationship                           | Omit foreign link                    | Enables bidirectional navigation (`Team.sidenavConfigs`), matches Funnel pattern with `foreign: "funnels"`                        |
| 13  | Add BeforeSave hook for isDefault validation                      | Manual admin enforcement             | Ensures only one default config per team automatically, matches Funnel/EnsureSingleDefault pattern                                |
| 14  | **ACL: table-level with admin-only create**                       | Team-level ACL, open create          | Only admins can create SidenavConfig records via adminForUser panel; read access follows team membership                          |

---

## File Manifest

### Files to CREATE (ordered by complexity/risk, highest first)

#### 1. Backend AppParam Class (CRITICAL)

| File Path                                                             | Purpose                                                                                                                                                                                        |
| --------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Classes/AppParams/TeamSidenavConfigs.php` | Implements `Espo\Tools\App\AppParam` interface. Loads all SidenavConfig records for user's teams. Returns array of config objects with id, name, teamId, iconClass, color, tabList, isDefault. |

**Key Implementation Details:**

- Constructor: inject `User` and `EntityManager`
- `get()` method: query `SidenavConfig` entity where `teamId` IN user's teamIds
- Return structure: flat array of config objects
- Handle empty teams case: return `[]`

**Reference Pattern:** `custom/Espo/Modules/Chatwoot/Classes/AppParams/ChatwootSsoUrl.php`

---

#### 2. Entity Definition (CRITICAL)

| File Path                                                                     | Purpose                                                                                    |
| ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json` | New entity for team-scoped navbar configurations. Pattern follows `Funnel.json` structure. |

**Complete Structure:**

```json
{
    "fields": {
        "name": {
            "type": "varchar",
            "required": true,
            "maxLength": 255,
            "trim": true
        },
        "team": {
            "type": "link",
            "required": true,
            "view": "views/fields/link"
        },
        "iconClass": {
            "type": "varchar",
            "maxLength": 100
        },
        "color": {
            "type": "varchar",
            "maxLength": 7
        },
        "tabList": {
            "type": "jsonArray",
            "view": "views/settings/fields/tab-list"
        },
        "isDefault": {
            "type": "bool",
            "default": false,
            "tooltip": true
        },
        "createdAt": {
            "type": "datetime",
            "readOnly": true
        },
        "modifiedAt": {
            "type": "datetime",
            "readOnly": true
        },
        "createdBy": {
            "type": "link",
            "readOnly": true,
            "view": "views/fields/user"
        },
        "modifiedBy": {
            "type": "link",
            "readOnly": true,
            "view": "views/fields/user"
        },
        "teams": {
            "type": "linkMultiple",
            "view": "views/fields/teams"
        }
    },
    "links": {
        "team": {
            "type": "belongsTo",
            "entity": "Team",
            "foreign": "sidenavConfigs"
        },
        "teams": {
            "type": "hasMany",
            "entity": "Team",
            "relationName": "entityTeam",
            "layoutRelationshipsDisabled": true
        },
        "createdBy": {
            "type": "belongsTo",
            "entity": "User"
        },
        "modifiedBy": {
            "type": "belongsTo",
            "entity": "User"
        }
    },
    "collection": {
        "orderBy": "name",
        "order": "asc"
    },
    "indexes": {
        "teamId": {
            "columns": ["teamId", "deleted"]
        }
    }
}
```

**Reference Pattern:** `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Funnel.json`

---

#### 3. Navbar View Rewrite (CRITICAL)

| File Path                                               | Purpose                                                                                                                                       |
| ------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `client/custom/modules/global/src/views/site/navbar.js` | Rewrite config resolution logic. Replace `getNavbarConfigList()` to fetch from `teamSidenavConfigs` appParam instead of Settings/Preferences. |

**Key Changes:**

- Add constant: `const DEFAULT_TABLIST_ID = '__default_tablist__';`
- Rewrite `getNavbarConfigList()`:
    - Get `userTeamIds` from `this.getUser().get('teamsIds')`
    - Get `teamConfigs` from `this.getHelper().getAppParam('teamSidenavConfigs')`
    - Filter configs by user's team IDs
    - Add default tabList option if `navbarConfigShowDefaultTabList` setting is enabled
- Rewrite `getActiveNavbarConfig()`: handle `DEFAULT_TABLIST_ID` selection
- Rewrite `getTabList()`: handle `isDefaultTabList` flag
- Add `getLegacyTabList()`: call `super.getTabList()` and filter
- Update `shouldShowConfigSelector()`: remove `navbarConfigSelectorDisabled` check, use team configs
- Update `setup()` preference listener: remove `navbarConfigList`, `useCustomNavbarConfig` listeners

---

#### 4. Active Navbar Config Field Rewrite (HIGH)

| File Path                                                                           | Purpose                                                                                             |
| ----------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| `client/custom/modules/global/src/views/preferences/fields/active-navbar-config.js` | Rewrite to fetch options from team configs via appParam instead of Settings/Preferences configList. |

**Key Changes:**

- Rewrite `setupOptions()`:
    - Get `userTeamIds` from `this.getUser().get('teamsIds')`
    - Get `teamConfigs` from `this.getHelper().getAppParam('teamSidenavConfigs')`
    - Filter by user's teams
    - Add default tabList option if enabled
- Remove listeners for `navbarConfigList`, `useCustomNavbarConfig`
- Remove `getResolvedConfigList()` method (no longer needed)
- Remove `navbarConfigDisabled` check (no longer applicable)

---

#### 5. BeforeSave Hook for isDefault Validation (HIGH)

| File Path                                                                | Purpose                                                                                                                                                    |
| ------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Hooks/SidenavConfig/EnsureSingleDefault.php` | Ensures only one SidenavConfig per Team is marked as default. When a config is set as default, all other configs for the same team are set to not default. |

**Structure:** Follows exact pattern from `Hooks/Funnel/EnsureSingleDefault.php`

**Implementation Note:** EspoCRM hooks are auto-discovered by directory convention (`Hooks/EntityName/HookClass.php`). No explicit registration in metadata is required.

```php
<?php
namespace Espo\Modules\Global\Hooks\SidenavConfig;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Ensures only one SidenavConfig per Team is marked as default.
 * @implements BeforeSave<Entity>
 */
class EnsureSingleDefault implements BeforeSave
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        // Only process if isDefault is being set to true
        if (!$entity->get('isDefault')) {
            return;
        }

        // Only process if this is a new entity or isDefault has changed
        if (!$entity->isNew() && !$entity->isAttributeChanged('isDefault')) {
            return;
        }

        $teamId = $entity->get('teamId');

        if (!$teamId) {
            return;
        }

        // Find all other configs for the same team that are marked as default
        $otherDefaults = $this->entityManager
            ->getRDBRepository('SidenavConfig')
            ->where([
                'teamId' => $teamId,
                'isDefault' => true,
                'id!=' => $entity->getId(),
            ])
            ->find();

        // Set them to not default
        foreach ($otherDefaults as $otherConfig) {
            $otherConfig->set('isDefault', false);
            $this->entityManager->saveEntity($otherConfig, ['skipHooks' => true]);
        }
    }
}
```

**Reference Pattern:** `custom/Espo/Modules/Global/Hooks/Funnel/EnsureSingleDefault.php`

---

#### 6. Entity Scope Definition (MEDIUM)

| File Path                                                                 | Purpose                                                                                              |
| ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/metadata/scopes/SidenavConfig.json` | Entity scope definition. Pattern follows `Funnel.json` but with `"tab": false` and `"acl": "table"`. |

**Structure:**

```json
{
    "entity": true,
    "layouts": true,
    "tab": false,
    "acl": "table",
    "module": "Global",
    "customizable": false,
    "importable": false,
    "object": true,
    "type": "Base",
    "hasTeams": true
}
```

**ACL Expectations:** With `"acl": "table"`, ACL entries should be created to grant:

- Admin: full CRUD access
- Regular users: read access only (configs are managed by admins via adminForUser)

---

#### 7. ClientDefs Metadata (MEDIUM)

| File Path                                                                     | Purpose                                                                           |
| ----------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/metadata/clientDefs/SidenavConfig.json` | Client-side entity metadata. Required for proper rendering in adminForUser panel. |

**Structure:**

```json
{
    "controller": "controllers/record",
    "iconClass": "fas fa-bars",
    "createDisabled": false,
    "defaultSidePanelFieldLists": {
        "detail": ["teams"],
        "edit": ["teams"],
        "detailSmall": ["teams"]
    }
}
```

**Reference Pattern:** `custom/Espo/Modules/PackEnterprise/Resources/metadata/clientDefs/MsxGoogleCalendarUser.json`

---

#### 8. AppParams Metadata (MEDIUM)

| File Path                                                          | Purpose                                                           |
| ------------------------------------------------------------------ | ----------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/metadata/app/appParams.json` | Register the `teamSidenavConfigs` appParam. Currently empty `{}`. |

**Edit: Replace entire content with:**

```json
{
    "teamSidenavConfigs": {
        "className": "Espo\\Modules\\Global\\Classes\\AppParams\\TeamSidenavConfigs"
    }
}
```

**Reference Pattern:** `custom/Espo/Modules/Chatwoot/Resources/metadata/app/appParams.json`

---

#### 9. adminForUserPanel Entry (MEDIUM)

| File Path                                                                  | Purpose                                       |
| -------------------------------------------------------------------------- | --------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/metadata/app/adminForUserPanel.json` | Add "sidenav" panel entry to existing object. |

**Edit: Add to existing object (preserve existing `users`, `data`, `sales` keys):**

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

---

#### 10. Entity Layouts (LOW)

| File Path                                                                     | Purpose                             |
| ----------------------------------------------------------------------------- | ----------------------------------- |
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detail.json`      | Detail view layout                  |
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/list.json`        | List view layout                    |
| `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detailSmall.json` | Small detail view for quick editing |

**detail.json structure:**

```json
[
    {
        "label": "Overview",
        "rows": [
            [{ "name": "name" }, { "name": "team" }],
            [{ "name": "iconClass" }, { "name": "color" }],
            [{ "name": "isDefault" }, false],
            [{ "name": "tabList", "fullWidth": true }]
        ]
    }
]
```

**list.json structure:**

```json
[
    { "name": "name", "width": 30 },
    { "name": "team", "width": 40 },
    { "name": "isDefault", "width": 15 }
]
```

**detailSmall.json structure:**

```json
[
    {
        "label": "Overview",
        "rows": [
            [{ "name": "name" }],
            [{ "name": "team" }],
            [{ "name": "isDefault" }]
        ]
    }
]
```

**Reference Pattern:** `custom/Espo/Modules/Global/Resources/layouts/Funnel/detailSmall.json`

---

#### 11. Entity Translations (LOW)

| File Path                                                            | Purpose                                                  |
| -------------------------------------------------------------------- | -------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/i18n/en_US/SidenavConfig.json` | Entity translations: scopeNames, field labels, tooltips. |

**Structure:**

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
        "team": "Team",
        "iconClass": "Icon",
        "color": "Color",
        "tabList": "Tab List",
        "isDefault": "Default"
    },
    "tooltips": {
        "isDefault": "If checked, this configuration will be the default for users in this team who haven't selected a specific config."
    }
}
```

---

#### 12. Configurations Translations (LOW) - **NEW FILE**

| File Path                                                             | Purpose                                                                                                                                                                       |
| --------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Configurations.json` | **NEW FILE** - Panel labels for adminForUser. Used by `admin-for-user/index.js`. This is a new file in the Global module, NOT an extension of Chatwoot's Configurations.json. |

**Structure:**

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

**Reference Pattern:** `custom/Espo/Modules/Chatwoot/Resources/i18n/en_US/Configurations.json`

---

### Files to EDIT

#### 1. Team Entity Definition (CRITICAL - v4 Addition)

| File Path                                                            | Changes                                                                                     |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Team.json` | ADD `sidenavConfigs` link to complete bidirectional relationship with SidenavConfig entity. |

**Current content:**

```json
{
    "links": {
        "funnels": {
            "type": "hasMany",
            "entity": "Funnel",
            "foreign": "team"
        }
    }
}
```

**Add after `funnels` link:**

```json
{
    "links": {
        "funnels": {
            "type": "hasMany",
            "entity": "Funnel",
            "foreign": "team"
        },
        "sidenavConfigs": {
            "type": "hasMany",
            "entity": "SidenavConfig",
            "foreign": "team"
        }
    }
}
```

**Rationale:** This completes the bidirectional link relationship referenced in Decision #12. Without this, `Team.sidenavConfigs` navigation would not work.

---

#### 2. Settings Entity Definition (HIGH)

| File Path                                                                | Changes                                                                                                                                 |
| ------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Settings.json` | DELETE fields: `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. ADD field: `navbarConfigShowDefaultTabList`. |

**Replace entire content with:**

```json
{
    "fields": {
        "navbarConfigShowDefaultTabList": {
            "type": "bool",
            "default": false,
            "tooltip": true
        }
    }
}
```

---

#### 3. Preferences Entity Definition (HIGH)

| File Path                                                                   | Changes                                                                                  |
| --------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Preferences.json` | DELETE fields: `navbarConfigList`, `useCustomNavbarConfig`. KEEP `activeNavbarConfigId`. |

**Replace entire content with:**

```json
{
    "fields": {
        "activeNavbarConfigId": {
            "type": "varchar",
            "maxLength": 36,
            "view": "global:views/preferences/fields/active-navbar-config"
        }
    }
}
```

---

#### 4. Settings Layout (MEDIUM)

| File Path                                                                  | Changes                                                                                                                                                                            |
| -------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/layouts/Settings/userInterface.json` | In the Navbar tab section, DELETE the rows containing `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. ADD a row with `navbarConfigShowDefaultTabList`. |

**Current Navbar section (rows 31-36):**

```json
{
    "rows": [
        [{ "name": "navbarConfigList", "fullWidth": true }],
        [
            { "name": "navbarConfigDisabled" },
            { "name": "navbarConfigSelectorDisabled" }
        ]
    ]
}
```

**Replace with:**

```json
{
    "rows": [[{ "name": "navbarConfigShowDefaultTabList" }, false]]
}
```

---

#### 5. Preferences Layout (MEDIUM)

| File Path                                                              | Changes                                                                                                                                        |
| ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/layouts/Preferences/detail.json` | In the User Interface tab section, DELETE the rows containing `useCustomNavbarConfig` and `navbarConfigList`. KEEP `activeNavbarConfigId` row. |

**Current User Interface section (rows 123-137):**

```json
{
    "rows": [
        [{ "name": "useCustomNavbarConfig" }, false],
        [{ "name": "navbarConfigList", "fullWidth": true }],
        [{ "name": "activeNavbarConfigId" }, false]
    ]
}
```

**Replace with:**

```json
{
    "rows": [[{ "name": "activeNavbarConfigId" }, false]]
}
```

---

#### 6. Settings Translations (LOW)

| File Path                                                       | Changes                                                                                                                                                                                                                                                    |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json` | DELETE translations for `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. DELETE labels: `Edit Navbar Configuration`, `Add Navbar Configuration`, `Navbar Configuration`. ADD translations for `navbarConfigShowDefaultTabList`. |

**Replace entire content with:**

```json
{
    "fields": {
        "navbarConfigShowDefaultTabList": "Show Default Tab List Option"
    },
    "tooltips": {
        "navbarConfigShowDefaultTabList": "If checked, users will see a 'Default' option in the navbar config selector that uses the system-level tabList. This allows users to switch back to the default navigation from team-specific configs."
    }
}
```

---

#### 7. Preferences Translations (LOW)

| File Path                                                          | Changes                                                                                                            |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------ |
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Preferences.json` | DELETE translations for `navbarConfigList`, `useCustomNavbarConfig`. KEEP `activeNavbarConfigId` - update tooltip. |

**Replace entire content with:**

```json
{
    "fields": {
        "activeNavbarConfigId": "Active Navbar Configuration"
    },
    "tooltips": {
        "activeNavbarConfigId": "Select your active navbar configuration from the options provided by your teams. Use 'Default' to use the system-level tab list."
    }
}
```

---

#### 8. Global Translations (LOW)

| File Path                                                     | Changes                                                                                                                   |
| ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json` | ADD `scopeNames` and `scopeNamesPlural` for SidenavConfig (as fallback). Update `navbarConfig.defaultConfig` translation. |

**Add to `scopeNames` object:**

```json
"SidenavConfig": "Sidenav Configuration"
```

**Add to `scopeNamesPlural` object:**

```json
"SidenavConfig": "Sidenav Configurations"
```

---

### Files to DELETE

| File Path                                                                         | Reason                                                                                                                            |
| --------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `client/custom/modules/global/src/views/settings/fields/navbar-config-list.js`    | No longer needed - configs are entity records, not Settings field. **Note: File may not exist yet - verify before deleting.**     |
| `client/custom/modules/global/src/views/preferences/fields/navbar-config-list.js` | No longer needed - users don't create custom configs. **Note: File may not exist yet - verify before deleting.**                  |
| `client/custom/modules/global/src/views/settings/modals/edit-navbar-config.js`    | No longer needed - editing happens via SidenavConfig record edit view. **Note: File may not exist yet - verify before deleting.** |

---

### Files to CONSIDER

| File Path                                                           | Reason                                                                                                                    |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `client/custom/modules/global/src/views/preferences/record/edit.js` | May need to hide `activeNavbarConfigId` field if user has no teams or no configs available. Consider dynamic visibility.  |
| Migration script or InstallActions                                  | Optional: Copy existing `Settings.navbarConfigList` to a default team's SidenavConfig records for backward compatibility. |

---

### Related Files (for reference only, no changes needed)

| File Path                                                               | Pattern Reference                                                                  |
| ----------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `client/custom/modules/global/src/views/site/navbar-config-selector.js` | Selector component - should work with updated resolution logic                     |
| `client/custom/modules/global/css/navbar-config-selector.css`           | Selector styles - no changes needed                                                |
| `client/custom/modules/global/src/controllers/admin-for-user.js`        | Controller for adminForUser routing                                                |
| `client/custom/modules/global/src/views/admin-for-user/index.js`        | Panel index view with ACL filtering - uses `Configurations` scope for translations |
| `application/Espo/Tools/App/AppParam.php`                               | Interface definition for AppParam implementations                                  |
| `application/Espo/Tools/App/AppService.php`                             | Loads appParams from metadata                                                      |
| `application/Espo/Resources/metadata/entityDefs/Team.json`              | Team entity structure - contains `funnels` link pattern                            |
| `application/Espo/Resources/metadata/entityDefs/User.json`              | User entity with teams linkMultiple                                                |
| `custom/Espo/Modules/Global/Hooks/Funnel/EnsureSingleDefault.php`       | Exact pattern for isDefault validation hook                                        |

---

## Error Handling

### Missing Teams Fallback

- User has no `teamsIds` or empty array → return empty config list → triggers legacy `tabList` fallback

### Invalid Active Config ID

- `activeNavbarConfigId` references deleted config → log warning → fall back to `isDefault` or first config
- `activeNavbarConfigId` is `DEFAULT_TABLIST_ID` but setting is disabled → fall back to first team config

### Default TabList Option

- `navbarConfigShowDefaultTabList` is disabled → default option not added to selector
- User selects default tabList → uses system `tabList` from Settings
- Switching from default tabList to team config → normal config resolution applies

### AJAX Error Handling

- Maintain existing error handling in `switchNavbarConfig()` method
- AppParam errors handled by Espo core (returns null/empty)

---

## Implementation Order

### Phase 1: Backend Foundation

1. Create `TeamSidenavConfigs.php` AppParam class
2. Update `appParams.json` metadata
3. Create `SidenavConfig.json` entity definition
4. **Edit `Team.json` to add `sidenavConfigs` link**
5. Create `SidenavConfig` scope definition
6. Create `SidenavConfig` clientDefs metadata
7. Create `EnsureSingleDefault.php` BeforeSave hook

### Phase 2: UI Components

1. Create SidenavConfig layouts (detail, list, detailSmall)
2. Create SidenavConfig translations
3. Create Configurations translations (NEW file)
4. Add adminForUser panel entry

### Phase 3: Frontend Logic

1. Update navbar.js resolution logic
2. Update active-navbar-config.js field
3. Update Settings/Preferences layouts
4. Update Settings/Preferences entityDefs
5. Update Settings/Preferences/Global translations

### Phase 4: Cleanup

1. Delete deprecated views/modals (verify existence first)

### Phase 5: Testing

1. Test team config creation via adminForUser
2. Test resolution with user having single team
3. Test resolution with user having multiple teams
4. Test resolution with user having no teams
5. Test active config selection
6. Test fallback to legacy tabList
7. Test selector visibility (hidden when ≤1 config)
8. Test ACL filtering in adminForUser panel
9. Test `navbarConfigShowDefaultTabList` setting
10. Test selecting default tabList option
11. Test `isDefault` validation (only one default per team)
12. **Test `Team.sidenavConfigs` bidirectional navigation**

---

## Summary of File Count

| Category  | Count    |
| --------- | -------- |
| CREATE    | 12 files |
| EDIT      | 8 files  |
| DELETE    | 3 files  |
| CONSIDER  | 2 items  |
| Reference | 10 files |

---

## v4 Audit Resolution Summary

| Finding Type   | Description                                   | Resolution                                                    |
| -------------- | --------------------------------------------- | ------------------------------------------------------------- |
| **Critical**   | Missing Team.json edit for bidirectional link | Added to Files to EDIT section                                |
| **Warning**    | Configurations.json file clarification        | Added note that this is a NEW file in Global module           |
| **Warning**    | Hook auto-discovery not documented            | Added implementation note in BeforeSave hook section          |
| **Suggestion** | ACL guidance                                  | Added ACL expectations to scope definition and scope metadata |
