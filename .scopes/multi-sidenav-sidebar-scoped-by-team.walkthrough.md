# Multi-Sidenav Sidebar Scoped by Team — Implementation Walkthrough

> **Scope**: v4  
> **Codebase Root**: `components/crm/source/`  
> **Status**: IMPLEMENTED

## What Changed

Navbar configuration moved from Settings/Preferences (admin-editable JSON arrays) to a first-class `SidenavConfig` entity linked to Teams via many-to-many (`teams` linkMultiple). A single config can be shared across multiple teams. Admins create configs via the adminForUser panel and assign them to one or more teams. Users see configs from all their teams and pick one with the sidebar selector. The old per-user `navbarConfigList` / `useCustomNavbarConfig` fields and their views were removed.

---

## File Manifest

### Files CREATED (13)

| #  | Path | Purpose |
|----|------|---------|
| 1  | `custom/Espo/Modules/Global/Classes/AppParams/TeamSidenavConfigs.php` | AppParam — loads SidenavConfig records for user's teams via many-to-many join |
| 2  | `custom/Espo/Modules/Global/Controllers/SidenavConfig.php` | Controller — standard CRUD (extends Base) |
| 3  | `custom/Espo/Modules/Global/Hooks/SidenavConfig/EnsureSingleDefault.php` | BeforeSave hook — one default per overlapping team set |
| 4  | `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json` | Entity definition |
| 5  | `custom/Espo/Modules/Global/Resources/metadata/scopes/SidenavConfig.json` | Scope definition |
| 6  | `custom/Espo/Modules/Global/Resources/metadata/clientDefs/SidenavConfig.json` | Client-side metadata |
| 7  | `custom/Espo/Modules/Global/Resources/metadata/app/appParams.json` | Registers `teamSidenavConfigs` appParam |
| 8  | `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detail.json` | Detail view layout |
| 9  | `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/list.json` | List view layout |
| 10 | `custom/Espo/Modules/Global/Resources/layouts/SidenavConfig/detailSmall.json` | Small detail view layout |
| 11 | `custom/Espo/Modules/Global/Resources/i18n/en_US/SidenavConfig.json` | Entity translations |
| 12 | `custom/Espo/Modules/Global/Resources/i18n/en_US/Configurations.json` | AdminForUser panel labels |

### Files EDITED (8)

| #  | Path | What changed |
|----|------|--------------|
| 1  | `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Settings.json` | Replaced old fields with `navbarConfigShowDefaultTabList` |
| 2  | `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Preferences.json` | Removed old fields, kept `activeNavbarConfigId` |
| 3  | `custom/Espo/Modules/Global/Resources/metadata/app/adminForUserPanel.json` | Added "sidenav" panel |
| 4  | `custom/Espo/Modules/Global/Resources/layouts/Settings/userInterface.json` | Replaced navbar config rows with `navbarConfigShowDefaultTabList` |
| 5  | `custom/Espo/Modules/Global/Resources/layouts/Preferences/detail.json` | Removed `useCustomNavbarConfig` and `navbarConfigList` rows |
| 6  | `custom/Espo/Modules/Global/Resources/i18n/en_US/Settings.json` | Replaced with `navbarConfigShowDefaultTabList` translations |
| 7  | `custom/Espo/Modules/Global/Resources/i18n/en_US/Preferences.json` | Kept only `activeNavbarConfigId` translations |
| 8  | `custom/Espo/Modules/Global/Resources/i18n/en_US/Global.json` | Added `SidenavConfig` to scopeNames/scopeNamesPlural |
| 9  | `client/custom/modules/global/src/views/site/navbar.js` | Rewrote config resolution to use `teamSidenavConfigs` appParam |
| 10 | `client/custom/modules/global/src/views/preferences/fields/active-navbar-config.js` | Rewrote options to use team configs appParam |

### Files DELETED (3)

| #  | Path | Reason |
|----|------|--------|
| 1  | `client/custom/modules/global/src/views/settings/fields/navbar-config-list.js` | Configs are entity records now |
| 2  | `client/custom/modules/global/src/views/preferences/fields/navbar-config-list.js` | Users no longer create custom configs |
| 3  | `client/custom/modules/global/src/views/settings/modals/edit-navbar-config.js` | Editing happens via SidenavConfig record view |

---

## File-by-File Walkthrough

### 1. `custom/Espo/Modules/Global/Classes/AppParams/TeamSidenavConfigs.php`

**Created.** AppParam that ships with the `/api/v1/App/user` response under key `teamSidenavConfigs`. The frontend reads this instead of hitting a separate API.

- Injects `User` and `EntityManager` via constructor.
- `get()` fetches the user's `teamsIds`, joins through the `teams` many-to-many relationship using `->distinct()->join('teams')->where(['teams.id' => $teamIds])`, returns a flat array of config objects (`id`, `name`, `iconClass`, `color`, `tabList`, `isDefault`).
- Returns `[]` when the user has no teams.
- Server-side filtering means the frontend does not need to re-filter by team.

```php
namespace Espo\Modules\Global\Classes\AppParams;

use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Tools\App\AppParam;

class TeamSidenavConfigs implements AppParam
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
    ) {}

    public function get(): array
    {
        $teamIds = $this->user->getLinkMultipleIdList('teams');

        if (empty($teamIds)) {
            return [];
        }

        $configs = $this->entityManager
            ->getRDBRepository('SidenavConfig')
            ->distinct()
            ->join('teams')
            ->where(['teams.id' => $teamIds])
            ->order('name')
            ->find();

        $result = [];

        foreach ($configs as $config) {
            $result[] = [
                'id' => $config->getId(),
                'name' => $config->get('name'),
                'iconClass' => $config->get('iconClass'),
                'color' => $config->get('color'),
                'tabList' => $config->get('tabList') ?? [],
                'isDefault' => (bool) $config->get('isDefault'),
            ];
        }

        return $result;
    }
}
```

---

### 2. `custom/Espo/Modules/Global/Controllers/SidenavConfig.php`

**Created.** Empty controller extending `Base` (which extends `Record`). Required because EspoCRM discovers controllers by scanning `Controllers/` directories — metadata alone is not enough.

```php
namespace Espo\Modules\Global\Controllers;

use Espo\Core\Templates\Controllers\Base;

class SidenavConfig extends Base
{
}
```

---

### 3. `custom/Espo/Modules/Global/Hooks/SidenavConfig/EnsureSingleDefault.php`

**Created.** BeforeSave hook auto-discovered by directory convention (`Hooks/{EntityName}/{HookClass}.php`). Ensures no two configs sharing a team can both be `isDefault = true`.

- Only fires when `isDefault` is being set to `true` on a new entity or when the attribute changed.
- Gets the config's team IDs via `$entity->getLinkMultipleIdList('teams')`.
- Queries other default configs that share ANY team via `->distinct()->join('teams')->where(['teams.id' => $teamIds, 'isDefault' => true, 'id!=' => ...])`.
- Uses `skipHooks` when saving the other configs to avoid infinite loops.

```php
namespace Espo\Modules\Global\Hooks\SidenavConfig;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

class EnsureSingleDefault implements BeforeSave
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->get('isDefault')) {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('isDefault')) {
            return;
        }

        $teamIds = $entity->getLinkMultipleIdList('teams');

        if (empty($teamIds)) {
            return;
        }

        $otherDefaults = $this->entityManager
            ->getRDBRepository('SidenavConfig')
            ->distinct()
            ->join('teams')
            ->where([
                'teams.id' => $teamIds,
                'isDefault' => true,
                'id!=' => $entity->getId(),
            ])
            ->find();

        foreach ($otherDefaults as $otherConfig) {
            $otherConfig->set('isDefault', false);
            $this->entityManager->saveEntity($otherConfig, ['skipHooks' => true]);
        }
    }
}
```

---

### 4. `custom/Espo/Modules/Global/Resources/metadata/entityDefs/SidenavConfig.json`

**Created.** Entity definition. Uses the standard `teams` linkMultiple (via `entityTeam` many-to-many table) for both scoping and ACL — a single config can belong to multiple teams.

Key fields:
- `name` (varchar, required) — config display name
- `iconClass` (varchar) — icon for the selector
- `color` (varchar, max 7) — hex color for the selector
- `tabList` (jsonArray, view: `views/settings/fields/tab-list`) — the actual navigation tabs
- `isDefault` (bool) — enforced by hook across overlapping teams
- `teams` (linkMultiple) — many-to-many via `entityTeam`, serves as both scoping and ACL

Links:
- `teams` → `hasMany` Team via `entityTeam` (standard many-to-many)

```json
{
    "fields": {
        "name": {
            "type": "varchar",
            "required": true,
            "maxLength": 255,
            "trim": true
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
    "indexes": {}
}
```

---

### 5. `custom/Espo/Modules/Global/Resources/metadata/scopes/SidenavConfig.json`

**Created.** Scope definition. `tab: false` keeps it out of the main nav. `acl: "table"` enables table-level ACL (admin full CRUD, regular users read via team membership). `hasTeams: true` enables the standard teams ACL field.

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

---

### 6. `custom/Espo/Modules/Global/Resources/metadata/clientDefs/SidenavConfig.json`

**Created.** Client-side metadata for the entity. Uses the standard `controllers/record` controller. No `defaultSidePanelFieldLists` because `teams` is in the main detail layout.

```json
{
    "controller": "controllers/record",
    "iconClass": "fas fa-bars",
    "createDisabled": false
}
```

---

### 7. `custom/Espo/Modules/Global/Resources/metadata/app/appParams.json`

**Created (was `{}`).** Registers the `teamSidenavConfigs` AppParam so EspoCRM's `AppService` loads it with the user session.

```json
{
    "teamSidenavConfigs": {
        "className": "Espo\\Modules\\Global\\Classes\\AppParams\\TeamSidenavConfigs"
    }
}
```

---

### 8. `custom/Espo/Modules/Global/Resources/metadata/app/adminForUserPanel.json`

**Edited.** Added `sidenav` panel to the existing adminForUser configuration.

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

### 9. `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Settings.json`

**Edited.** Removed `navbarConfigList`, `navbarConfigDisabled`, `navbarConfigSelectorDisabled`. Added `navbarConfigShowDefaultTabList`.

Before:
```json
{
    "fields": {
        "navbarConfigList": { "type": "jsonArray", ... },
        "navbarConfigDisabled": { "type": "bool", ... },
        "navbarConfigSelectorDisabled": { "type": "bool", ... }
    }
}
```

After:
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

### 10. `custom/Espo/Modules/Global/Resources/metadata/entityDefs/Preferences.json`

**Edited.** Removed `navbarConfigList` and `useCustomNavbarConfig`. Kept `activeNavbarConfigId`.

Before:
```json
{
    "fields": {
        "navbarConfigList": { "type": "jsonArray", ... },
        "useCustomNavbarConfig": { "type": "bool", ... },
        "activeNavbarConfigId": { "type": "varchar", ... }
    }
}
```

After:
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

### 11. `custom/Espo/Modules/Global/Resources/layouts/Settings/userInterface.json`

**Edited.** In the Navbar tab section, replaced the `navbarConfigList` / `navbarConfigDisabled` / `navbarConfigSelectorDisabled` rows with a single `navbarConfigShowDefaultTabList` row.

Before:
```json
{
    "rows": [
        [{"name": "navbarConfigList", "fullWidth": true}],
        [{"name": "navbarConfigDisabled"}, {"name": "navbarConfigSelectorDisabled"}]
    ]
}
```

After:
```json
{
    "rows": [
        [{"name": "navbarConfigShowDefaultTabList"}, false]
    ]
}
```

---

### 12. `custom/Espo/Modules/Global/Resources/layouts/Preferences/detail.json`

**Edited.** In the User Interface tab, removed `useCustomNavbarConfig` and `navbarConfigList` rows. Kept `activeNavbarConfigId`.

Before:
```json
{
    "rows": [
        [{"name": "useCustomNavbarConfig"}, false],
        [{"name": "navbarConfigList", "fullWidth": true}],
        [{"name": "activeNavbarConfigId"}, false]
    ]
}
```

After:
```json
{
    "rows": [
        [{"name": "activeNavbarConfigId"}, false]
    ]
}
```

---

### 13. Layouts: `detail.json`, `list.json`, `detailSmall.json` (SidenavConfig)

**Created.** Standard EspoCRM layouts.

**detail.json** — Overview panel: name + teams, iconClass + color, isDefault, tabList (full width).

**list.json** — Three columns: name (30%), teams (40%), isDefault (15%).

**detailSmall.json** — Minimal: name, teams, isDefault.

---

### 14. Translations: `SidenavConfig.json`, `Configurations.json`, `Settings.json`, `Preferences.json`, `Global.json`

**SidenavConfig.json** — Created. scopeNames, field labels (including `teams`), tooltip for isDefault.

**Configurations.json** — Created. Labels for the adminForUser panel: "Sidenav" → "Navigation", "Sidenav Configs" → "Sidenav Configurations".

**Settings.json** — Replaced. Only `navbarConfigShowDefaultTabList` field label and tooltip.

**Preferences.json** — Replaced. Only `activeNavbarConfigId` field label and tooltip.

**Global.json** — Edited. Added `SidenavConfig` to both `scopeNames` and `scopeNamesPlural`.

---

### 15. `client/custom/modules/global/src/views/site/navbar.js`

**Rewritten.** The core frontend change. Key differences from the previous version:

**Removed:**
- `getNavbarConfigList()` no longer reads from `Settings.navbarConfigList` or `Preferences.navbarConfigList`
- `setup()` no longer listens for `navbarConfigList` or `useCustomNavbarConfig` preference changes
- `shouldShowConfigSelector()` no longer checks `navbarConfigSelectorDisabled`
- Client-side team filtering removed (server-side AppParam already filters by user's teams)

**Added:**
- `const DEFAULT_TABLIST_ID = '__default_tablist__';` — sentinel ID for the system default tabList option
- `getLegacyTabList()` — delegates to `super.getTabList()` for fallback

**Rewritten:**
- `getNavbarConfigList()` — reads directly from `this.getHelper().getAppParam('teamSidenavConfigs')` (no client-side team filtering needed), optionally appends the default tabList option if `navbarConfigShowDefaultTabList` is enabled
- `getActiveNavbarConfig()` — handles `DEFAULT_TABLIST_ID` as a special case; falls back to `isDefault` config, then first config
- `getTabList()` — if active config has `isDefaultTabList: true`, delegates to `getLegacyTabList()`; otherwise uses the config's `tabList`
- `shouldShowConfigSelector()` — simplified to just check `isSide()` and config count > 1
- `setup()` — only listens for `activeNavbarConfigId` changes

**Resolution priority:**
1. Team SidenavConfig → active config's `tabList`
2. Default tabList option → system `tabList` via `super.getTabList()`
3. No configs → legacy fallback via `super.getTabList()`

---

### 16. `client/custom/modules/global/src/views/preferences/fields/active-navbar-config.js`

**Rewritten.** Simplified enum field that populates its options from the `teamSidenavConfigs` appParam.

**Removed:**
- `getResolvedConfigList()` — no longer needed
- Listeners for `navbarConfigList`, `useCustomNavbarConfig`
- `navbarConfigDisabled` check
- Client-side team filtering (server-side AppParam handles it)

**Rewritten:**
- `setupOptions()` — reads `teamSidenavConfigs` directly from appParam, optionally adds default tabList option, hides field if no configs available

```js
setupOptions() {
    const configs = this.getHelper().getAppParam('teamSidenavConfigs') || [];

    if (this.getConfig().get('navbarConfigShowDefaultTabList')) {
        configs.push({
            id: DEFAULT_TABLIST_ID,
            name: this.getLanguage().translate('defaultConfig', 'navbarConfig', 'Global'),
        });
    }

    this.params.options = ['', ...configs.map(c => c.id)];
    this.translatedOptions = { '': this.translate('Default') };

    configs.forEach(c => {
        this.translatedOptions[c.id] = c.name || c.id;
    });

    if (!configs.length) {
        this.hide();
    }
}
```

---

### 17–19. Deleted Files

**`views/settings/fields/navbar-config-list.js`** — Was the drag-and-drop list editor for `Settings.navbarConfigList`. No longer needed since configs are SidenavConfig entity records managed via standard CRUD views.

**`views/preferences/fields/navbar-config-list.js`** — Extended the settings version for user preferences. Removed along with the preference-level config concept.

**`views/settings/modals/edit-navbar-config.js`** — Modal dialog for editing a single navbar config item (name, icon, color, tabList). Replaced by the SidenavConfig entity's standard detail/edit views.

---

## Data Flow

```
Admin creates SidenavConfig record (via adminForUser panel)
    → Assigns to one or more Teams via `teams` linkMultiple
    → EnsureSingleDefault hook unsets isDefault on other configs sharing any team
    → Database: `sidenav_config` table + `entity_team` junction table

User logs in
    → /api/v1/App/user loads AppParams
    → TeamSidenavConfigs.php joins through `entity_team` to find configs
      matching user's teams (DISTINCT to avoid duplicates from multi-team overlap)
    → Frontend receives `teamSidenavConfigs` array in appParam

Navbar renders
    → navbar.js calls getNavbarConfigList()
    → Uses appParam configs directly (already filtered server-side)
    → Optionally adds __default_tablist__ option
    → getActiveNavbarConfig() picks config by activeNavbarConfigId preference
    → getTabList() returns the selected config's tabList

User switches config via selector
    → switchNavbarConfig() PUTs to /api/v1/Preferences/{userId}
    → Updates local preference, re-renders navbar
```

---

## Deployment

After deploying the files:

```bash
php command.php rebuild
```

This clears the controller cache, creates the `sidenav_config` database table, and rebuilds metadata.
