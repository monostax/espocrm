# Multi-Sidenav Sidebar Mode - Implementation Plan v8

> **Version**: 8.0  
> **Based on**: `.scopes/multi-sidenav-sidebar.v7.md` and `.scopes/multi-sidenav-sidebar.v7.audit.md`  
> **Codebase Root**: `components/crm/source/`  
> **Status**: File Manifest - READY FOR IMPLEMENTATION

## Overview

Feature request to implement a multi-sidenav sidebar mode allowing users to toggle between different navbar configurations using a dropdown selector in the sidebar.

### Requirements
1. **UI Pattern**: Dropdown/selector in the sidebar for switching views
2. **Configuration**: Each navbar config has its own complete `tabList`
3. **Levels**: Both system-level defaults and user-level overrides
4. **Quantity**: Unlimited configurable navbar views

---

## Audit Corrections Applied (v7 → v8)

| Issue | v7 Problem | v8 Correction |
|-------|-----------|---------------|
| NavbarConfig entity | `getModelFactory().create('NavbarConfig')` - entity doesn't exist | Use `new Model()` with `model.name = 'NavbarConfig'` and `model.setDefs()` following `edit-tab-group.js:94-117` |
| Modal templates | Plans to create `.tpl` files | Use inline `templateContent` like `edit-tab-group.js:36` |
| navbar.js line reference | "after line 1000" - inside afterRender() | Insert after line 1045, before `selectTab()` |
| generateItemId() pattern | New format inconsistent with reference | Use exact `tab-list.js:54-55` pattern: `Math.floor(Math.random() * 1000000 + 1).toString()` |
| Model import | Missing `import Model from 'model'` | Add to both modal files |
| LESS import placement | At end of file | Move to top of `navbar.less` |

---

## Decisions Made

| Question | Decision | Rationale |
|----------|----------|-----------|
| Default Behavior | Keep existing `tabList` as fallback; first navbar config must be explicitly created | Backward compatible, no migration needed |
| Selector Visibility | Hidden when ≤1 navbar config exists | Cleaner UI when feature not actively used |
| Admin UI | Field on User Interface page (not separate page) | Simpler implementation, follows existing pattern |
| Portal Support | **Out of scope** for v8 | Portal has separate `tabList` system; can be added later |
| Storage Strategy | Server-side Preferences only | Syncs across devices, simpler implementation |
| Active Config Save | Use `Espo.Ajax.putRequest()` to update Preferences | No new backend action needed, uses existing REST API |

---

## Current System Architecture

### Existing Navbar Modes
- **Location**: `application/Espo/Resources/metadata/themes/Espo.json`
- Two modes supported: `side` (sidebar) and `top` (horizontal navbar)
- Configured via theme `params.navbar` enum field

### Current Tab List Structure
- **Settings Field**: `tabList` in `application/Espo/Resources/metadata/entityDefs/Settings.json:245-255`
- **Preferences Field**: `tabList` in `application/Espo/Resources/metadata/entityDefs/Preferences.json:171-181`
- **Field View**: `client/src/views/settings/fields/tab-list.js`
- **Helper Class**: `client/src/helpers/site/tabs.js`

### Existing Preference Fields (MUST ACCOUNT FOR)
| Field | Type | Location | Purpose |
|-------|------|----------|---------|
| `useCustomTabList` | bool | `Preferences.json:162` | User has custom tab list enabled |
| `addCustomTabs` | bool | `Preferences.json:166` | User's tabs are additive to system tabs |
| `tabList` | array | `Preferences.json:171` | User's custom tab list |

**Existing Resolution Logic** (`client/src/helpers/site/tabs.js:67-79`):
```javascript
getTabList() {
    let tabList = this.preferences.get('useCustomTabList') && !this.preferences.get('addCustomTabs') ?
        this.preferences.get('tabList') :
        this.config.get('tabList');

    if (this.preferences.get('useCustomTabList') && this.preferences.get('addCustomTabs')) {
        tabList = [
            ...tabList,
            ...(this.preferences.get('tabList') || []),
        ];
    }

    return Espo.Utils.cloneDeep(tabList) || [];
}
```

### Tab List Item Types
1. **Scope**: String entity name (e.g., `"Accounts"`, `"Contacts"`)
2. **Group**: Object with `type: "group"`, `text`, `iconClass`, `color`, `itemList`
3. **URL**: Object with `type: "url"`, `text`, `url`, `iconClass`, `color`, `aclScope`, `onlyAdmin`
4. **Divider**: Object with `type: "divider"`, `text`
5. **Delimiter**: String `"_delimiter_"` or `"_delimiter-ext_"` for more menu split

### Existing Translation Section (MUST USE)
**Location**: `application/Espo/Resources/i18n/en_US/Global.json:988-994`
```json
"navbarTabs": {
    "Business": "Business",
    "Marketing": "Marketing",
    "Support": "Support",
    "CRM": "CRM",
    "Activities": "Activities"
}
```

### Verified CSS Variables
**Location**: `frontend/less/espo/root-variables.less`
- Spacing: `--8px`, `--12px`, `--4px`, etc. (lines 10, 14, 6)
- Layout: `--navbar-width` (232px, line 108), `--border-radius` (line 440)
- Colors: `--navbar-inverse-link-hover-bg` (line 392), `--navbar-inverse-border` (line 388), `--dropdown-link-hover-bg` (line 490), `--dropdown-link-hover-color` (line 489)

### Verified Bootstrap Variables
**Location**: `frontend/less/espo/bootstrap/variables.less:37`
- `@screen-xs-max: (@screen-sm-min - 1px);`

---

## Data Model Design

### New Navbar Configuration Object
```json
{
  "id": "navbar-config-123",
  "name": "Business",
  "iconClass": "fas fa-briefcase",
  "color": "#4A90D9",
  "tabList": [
    "Home",
    "Accounts",
    "Contacts",
    {"type": "group", "text": "Sales", "itemList": ["Opportunities", "Leads"]}
  ],
  "isDefault": false
}
```

### Settings Fields (System Level)
| Field | Type | Description |
|-------|------|-------------|
| `navbarConfigList` | `jsonArray` | Array of navbar configuration objects |
| `navbarConfigDisabled` | `bool` | Disable user customization (default: false) |
| `navbarConfigSelectorDisabled` | `bool` | Hide selector dropdown (default: false) |

**Rationale for `jsonArray` type**: Unlike `tabList` which uses `array` type, `navbarConfigList` uses `jsonArray` because each config contains nested `tabList` with complex objects (groups, URLs, dividers), not just string scope names. This follows the pattern of `dashboardLayout` field.

### Preferences Fields (User Level)
| Field | Type | Description |
|-------|------|-------------|
| `navbarConfigList` | `jsonArray` | User's custom navbar configurations |
| `useCustomNavbarConfig` | `bool` | Use user configs instead of system (default: false) |
| `activeNavbarConfigId` | `varchar` | ID of currently active configuration |

---

## Resolution Logic (Complete)

```javascript
/**
 * Resolution Priority Order:
 * 1. Navbar config system (new feature) - if any navbarConfigList exists
 * 2. Legacy tab customization (existing feature) - useCustomTabList/addCustomTabs
 * 3. System default tabList
 */

getTabList() {
    // NEW: Check navbar config system first
    if (this.hasNavbarConfigSystem()) {
        const activeConfig = this.getActiveNavbarConfig();
        
        if (activeConfig && activeConfig.tabList) {
            return Espo.Utils.cloneDeep(activeConfig.tabList);
        }
    }
    
    // Existing logic remains as fallback
    let tabList = this.preferences.get('useCustomTabList') && 
                  !this.preferences.get('addCustomTabs') ?
        this.preferences.get('tabList') :
        this.config.get('tabList');

    if (this.preferences.get('useCustomTabList') && this.preferences.get('addCustomTabs')) {
        tabList = [
            ...tabList,
            ...(this.preferences.get('tabList') || []),
        ];
    }

    return Espo.Utils.cloneDeep(tabList) || [];
}

hasNavbarConfigSystem() {
    const configList = this.getNavbarConfigList();
    return configList && configList.length > 0;
}

getNavbarConfigList() {
    if (this.config.get('navbarConfigDisabled')) {
        return this.config.get('navbarConfigList') || [];
    }
    
    if (this.preferences.get('useCustomNavbarConfig')) {
        return this.preferences.get('navbarConfigList') || [];
    }
    
    return this.config.get('navbarConfigList') || [];
}

getActiveNavbarConfig() {
    const configList = this.getNavbarConfigList();
    
    if (!configList || configList.length === 0) {
        return null;
    }
    
    const activeId = this.preferences.get('activeNavbarConfigId');
    
    if (activeId) {
        const found = configList.find(c => c.id === activeId);
        if (found) return found;
        
        // ID not found - clear invalid preference
        console.warn('Active navbar config ID not found, falling back to default');
    }
    
    return configList.find(c => c.isDefault) || configList[0];
}

validateNavbarConfigList(configList) {
    if (!configList || configList.length === 0) return true;
    
    const ids = configList.map(c => c.id).filter(Boolean);
    
    if (new Set(ids).size !== ids.length) {
        throw new Error('Duplicate navbar config IDs detected');
    }
    
    return true;
}
```

---

## File Manifest

### Files to CREATE

#### 1. `client/src/views/settings/fields/navbar-config-list.js`
Field view for managing navbar configs in Settings. Extends ArrayFieldView pattern from tab-list.js.

```javascript
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

import ArrayFieldView from 'views/fields/array';

class NavbarConfigListFieldView extends ArrayFieldView {

    addItemModalView = 'views/modals/navbar-config-field-add'

    setup() {
        super.setup();

        this.selected.forEach(item => {
            if (item && typeof item === 'object') {
                if (!item.id) {
                    item.id = this.generateItemId();
                }
            }
        });

        this.addActionHandler('editConfig', (e, target) => {
            this.editConfig(target.dataset.value);
        });
    }

    generateItemId() {
        return Math.floor(Math.random() * 1000000 + 1).toString();
    }

    getItemHtml(value) {
        if (typeof value === 'object') {
            const html = this.getGroupItemHtml(value);
            return html;
        }
        return super.getItemHtml(value);
    }

    getGroupItemHtml(config) {
        const id = config.id || this.generateItemId();
        const name = config.name || 'Unnamed Config';
        const iconClass = config.iconClass || 'fas fa-th-large';
        const isDefault = config.isDefault || false;
        
        let html = '<li data-value="' + this.escapeString(id) + '">';
        html += '<div class="list-group-item">';
        html += '<span class="' + this.escapeString(iconClass) + '" style="margin-right: 8px;"></span>';
        html += '<span class="item-text">' + this.escapeString(name) + '</span>';
        if (isDefault) {
            html += ' <span class="text-muted small">(Default)</span>';
        }
        html += '<button type="button" class="btn btn-link btn-sm pull-right" data-action="editConfig" data-value="' + this.escapeString(id) + '">';
        html += '<span class="fas fa-pencil-alt"></span>';
        html += '</button>';
        html += '</div>';
        html += '</li>';
        
        return html;
    }

    editConfig(id) {
        const config = this.getConfigById(id);
        
        if (!config) {
            return;
        }

        this.createView('modal', 'views/settings/modals/edit-navbar-config', {
            configData: config,
        }, view => {
            view.render();

            this.listenToOnce(view, 'save', data => {
                this.updateConfig(id, data);
            });
        });
    }

    getConfigById(id) {
        return this.selected.find(c => c && c.id === id);
    }

    updateConfig(id, data) {
        const index = this.selected.findIndex(c => c && c.id === id);
        
        if (index !== -1) {
            this.selected[index] = { ...data, id: id };
            this.reRender();
            this.trigger('change');
        }
    }

    fetchFromDom() {
        const items = [];
        
        this.$list.children('li').each((i, li) => {
            const value = this.selected.find(c => c && c.id === $(li).data('value'));
            if (value) {
                items.push(value);
            }
        });
        
        this.selected = items;
    }
}

export default NavbarConfigListFieldView;
```

#### 2. `client/src/views/preferences/fields/navbar-config-list.js`
User-level navbar configs field view extending Settings version.

```javascript
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

import NavbarConfigListFieldView from 'views/settings/fields/navbar-config-list';

class PreferencesNavbarConfigListFieldView extends NavbarConfigListFieldView {

}

export default PreferencesNavbarConfigListFieldView;
```

#### 3. `client/src/views/preferences/fields/active-navbar-config.js`
Dropdown field to select active config.

```javascript
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

import EnumFieldView from 'views/fields/enum';

class ActiveNavbarConfigFieldView extends EnumFieldView {

    setupOptions() {
        let configList = [];
        
        if (this.getConfig().get('navbarConfigDisabled')) {
            configList = this.getConfig().get('navbarConfigList') || [];
        } else if (this.model.get('useCustomNavbarConfig')) {
            configList = this.model.get('navbarConfigList') || [];
        } else {
            configList = this.getConfig().get('navbarConfigList') || [];
        }
        
        this.params.options = configList.map(c => c.id);
        this.translatedOptions = {};
        
        configList.forEach(config => {
            let label = config.name;
            if (config.isDefault) {
                label += ' (' + this.translate('defaultConfig', 'navbarConfig') + ')';
            }
            this.translatedOptions[config.id] = label;
        });
        
        if (this.params.options.length === 0) {
            this.params.options = [''];
            this.translatedOptions[''] = this.translate('noConfigs', 'navbarConfig');
        }
    }
    
    setup() {
        super.setup();
        
        this.listenTo(this.model, 'change:useCustomNavbarConfig change:navbarConfigList', () => {
            this.setupOptions();
            this.reRender();
        });
        
        this.listenTo(this.getConfig(), 'change:navbarConfigList', () => {
            this.setupOptions();
            this.reRender();
        });
    }
}

export default ActiveNavbarConfigFieldView;
```

#### 4. `client/src/views/settings/modals/edit-navbar-config.js`
Modal for editing a single navbar config.

**CRITICAL**: Uses `new Model()` pattern with `model.name = 'NavbarConfig'` and `model.setDefs()` following `edit-tab-group.js:94-117`. Uses inline `templateContent`.

```javascript
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

import Modal from 'views/modal';
import Model from 'model';

class EditNavbarConfigModalView extends Modal {

    className = 'dialog dialog-record'

    templateContent = `<div class="record no-side-margin">{{{record}}}</div>`

    setup() {
        super.setup();

        this.headerText = this.translate('Edit Navbar Configuration', 'labels', 'Settings');

        this.buttonList.push({
            name: 'apply',
            label: 'Apply',
            style: 'danger',
        });

        this.buttonList.push({
            name: 'cancel',
            label: 'Cancel',
        });

        this.shortcutKeys = {
            'Control+Enter': () => this.actionApply(),
        };

        const configData = this.options.configData || {};

        const detailLayout = {
            rows: [
                [
                    { name: 'name' },
                    { name: 'iconClass' }
                ],
                [
                    { name: 'color' },
                    { name: 'isDefault' }
                ],
                [
                    { name: 'tabList', fullWidth: true }
                ]
            ]
        };

        const model = this.model = new Model();

        model.name = 'NavbarConfig';
        model.set({
            id: configData.id || null,
            name: configData.name || '',
            iconClass: configData.iconClass || 'fas fa-th-large',
            color: configData.color || '',
            tabList: configData.tabList || [],
            isDefault: configData.isDefault || false,
        });

        model.setDefs({
            fields: {
                name: {
                    type: 'varchar',
                    required: true,
                },
                iconClass: {
                    type: 'base',
                    view: 'views/admin/entity-manager/fields/icon-class',
                },
                color: {
                    type: 'base',
                    view: 'views/fields/colorpicker',
                },
                tabList: {
                    type: 'array',
                    view: 'views/settings/fields/tab-list',
                },
                isDefault: {
                    type: 'bool',
                },
            },
        });

        this.createView('record', 'views/record/edit-for-modal', {
            detailLayout: detailLayout,
            model: model,
            selector: '.record',
        });
    }

    actionApply() {
        const recordView = this.getView('record');

        if (recordView.validate()) {
            return;
        }

        const data = recordView.fetch();

        this.trigger('apply', data);
    }
}

export default EditNavbarConfigModalView;
```

#### 5. `client/src/views/modals/navbar-config-field-add.js`
Add item modal for navbar-config-list field.

**CRITICAL**: Uses `new Model()` pattern. Uses inline `templateContent`.

```javascript
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

import Modal from 'views/modal';
import Model from 'model';

class NavbarConfigFieldAddModalView extends Modal {

    className = 'dialog dialog-record'

    templateContent = `<div class="record no-side-margin">{{{record}}}</div>`

    setup() {
        super.setup();

        this.headerText = this.translate('Add Navbar Configuration', 'labels', 'Settings');

        this.buttonList.push({
            name: 'create',
            label: 'Create',
            style: 'primary',
            onClick: () => this.actionCreate(),
        });

        this.buttonList.push({
            name: 'cancel',
            label: 'Cancel',
        });

        const detailLayout = {
            rows: [
                [
                    { name: 'name' },
                    { name: 'iconClass' }
                ],
                [
                    { name: 'color' },
                    { name: 'isDefault' }
                ]
            ]
        };

        const model = this.model = new Model();

        model.name = 'NavbarConfig';
        model.set({
            name: '',
            iconClass: 'fas fa-th-large',
            color: '',
            tabList: [],
            isDefault: false,
        });

        model.setDefs({
            fields: {
                name: {
                    type: 'varchar',
                    required: true,
                },
                iconClass: {
                    type: 'base',
                    view: 'views/admin/entity-manager/fields/icon-class',
                },
                color: {
                    type: 'base',
                    view: 'views/fields/colorpicker',
                },
                isDefault: {
                    type: 'bool',
                },
            },
        });

        this.createView('record', 'views/record/edit-for-modal', {
            detailLayout: detailLayout,
            model: model,
            selector: '.record',
        });
    }

    actionCreate() {
        const recordView = this.getView('record');

        if (recordView.validate()) {
            return;
        }

        const data = recordView.fetch();

        if (!data.name) {
            Espo.Ui.error(this.translate('fieldIsRequired', 'messages').replace('{field}', 'Name'));
            return;
        }

        const id = Math.floor(Math.random() * 1000000 + 1).toString();

        this.trigger('add', { ...data, id: id });
    }
}

export default NavbarConfigFieldAddModalView;
```

#### 6. `client/src/views/site/navbar-config-selector.js`
Dropdown selector component rendered in sidebar.

```javascript
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

import View from 'view';

class NavbarConfigSelectorView extends View {

    template = 'site/navbar-config-selector'
    
    data() {
        const configList = this.options.configList || [];
        const activeConfigId = this.options.activeConfigId;
        
        let activeConfig = configList.find(c => c.id === activeConfigId);
        
        if (!activeConfig) {
            activeConfig = configList.find(c => c.isDefault) || configList[0];
        }
        
        return {
            configList: configList,
            activeConfig: activeConfig,
            activeConfigId: activeConfig ? activeConfig.id : null,
        };
    }
    
    setup() {
        this.addActionHandler('selectConfig', (e, target) => {
            const id = target.dataset.id;
            this.trigger('select', id);
        });
        
        this.events = {
            'keydown .dropdown-toggle': 'onKeydownToggle',
            'keydown .dropdown-menu a': 'onKeydownItem',
        };
    }
    
    onKeydownToggle(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            this.$('.dropdown-toggle').dropdown('toggle');
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            this.$('.dropdown-menu a:first').focus();
        }
    }
    
    onKeydownItem(e) {
        const $items = this.$('.dropdown-menu a');
        const index = $items.index(e.target);
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            $items.eq((index + 1) % $items.length).focus();
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            $items.eq((index - 1 + $items.length) % $items.length).focus();
        }
        if (e.key === 'Escape') {
            this.$('.dropdown-toggle').dropdown('toggle');
            this.$('.dropdown-toggle').focus();
        }
    }
}

export default NavbarConfigSelectorView;
```

#### 7. `client/res/templates/site/navbar-config-selector.tpl`
Template for navbar config selector dropdown.

**CRITICAL**: Uses `#ifEqual` (verified at view-helper.js:386), NOT `#ifEquals`.

```html
<div class="navbar-config-selector dropdown">
    <button 
        class="dropdown-toggle" 
        data-toggle="dropdown" 
        type="button"
        tabindex="0"
        aria-haspopup="true"
        aria-expanded="false"
        title="{{translate 'switchView' scope='navbarConfig'}}"
    >
        {{#if activeConfig.iconClass}}
            <span class="config-icon {{activeConfig.iconClass}}"{{#if activeConfig.color}} style="color: {{activeConfig.color}}"{{/if}}></span>
        {{else}}
            <span class="config-icon fas fa-th-large"></span>
        {{/if}}
        <span class="config-name">{{activeConfig.name}}</span>
        <span class="caret"></span>
    </button>
    <ul class="dropdown-menu" role="menu">
        {{#each configList}}
        <li class="{{#ifEqual id ../activeConfigId}}active{{/ifEqual}}">
            <a 
                role="button" 
                tabindex="0" 
                data-action="selectConfig" 
                data-id="{{id}}"
            >
                {{#if iconClass}}
                    <span class="config-icon {{iconClass}}"{{#if color}} style="color: {{color}}"{{/if}}></span>
                {{else}}
                    <span class="config-icon fas fa-th-large"></span>
                {{/if}}
                <span class="config-name">{{name}}</span>
                {{#if isDefault}}
                    <span class="is-default-badge text-muted">({{translate 'defaultConfig' scope='navbarConfig'}})</span>
                {{/if}}
            </a>
        </li>
        {{/each}}
    </ul>
</div>
```

#### 8. `frontend/less/espo/elements/navbar-config-selector.less`
Selector component styles.

```less
// Navbar Config Selector Styles
// Uses verified CSS variables from root-variables.less

#navbar {
    .navbar-config-selector {
        padding: var(--8px) var(--12px);
        border-bottom: 1px solid var(--navbar-inverse-border);
        
        .dropdown-toggle {
            display: flex;
            align-items: center;
            width: 100%;
            padding: var(--8px);
            border-radius: var(--border-radius);
            background: transparent;
            border: none;
            color: inherit;
            cursor: pointer;
            
            &:hover {
                background-color: var(--navbar-inverse-link-hover-bg);
            }
            
            &:focus {
                outline: none;
                box-shadow: 0 0 0 2px var(--navbar-inverse-link-hover-bg);
            }
            
            .config-icon {
                margin-right: var(--8px);
                width: 16px;
                text-align: center;
            }
            
            .config-name {
                flex: 1;
                text-align: left;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            
            .caret {
                margin-left: var(--8px);
            }
        }
        
        .dropdown-menu {
            width: 100%;
            max-height: 300px;
            overflow-y: auto;
            
            > li > a {
                display: flex;
                align-items: center;
                padding: var(--8px) var(--12px);
                
                &:focus {
                    outline: none;
                    background-color: var(--dropdown-link-hover-bg);
                }
                
                .config-icon {
                    margin-right: var(--8px);
                    width: 16px;
                    text-align: center;
                }
                
                .config-name {
                    flex: 1;
                }
                
                .is-default-badge {
                    font-size: 0.75em;
                    opacity: 0.7;
                }
            }
            
            > li.active > a {
                background-color: var(--dropdown-link-hover-bg);
                color: var(--dropdown-link-hover-color);
            }
        }
    }
}

// Sidebar-specific styles
body[data-navbar="side"] {
    #navbar .navbar-config-selector {
        .dropdown-menu {
            position: fixed;
            left: var(--navbar-width);
            top: auto;
        }
    }
}

// Responsive - hide on mobile/XS screens
@media screen and (max-width: @screen-xs-max) {
    #navbar .navbar-config-selector {
        display: none;
    }
}
```

---

### Files to EDIT

#### 1. `application/Espo/Resources/metadata/entityDefs/Settings.json`
**Insert AFTER line 255** (after `tabList` field definition ends):

```json
        "navbarConfigList": {
            "type": "jsonArray",
            "view": "views/settings/fields/navbar-config-list"
        },
        "navbarConfigDisabled": {
            "type": "bool",
            "default": false,
            "tooltip": true
        },
        "navbarConfigSelectorDisabled": {
            "type": "bool",
            "default": false,
            "tooltip": true
        },
```

#### 2. `application/Espo/Resources/metadata/entityDefs/Preferences.json`
**Insert AFTER line 181** (after `tabList` field definition ends):

```json
        "navbarConfigList": {
            "type": "jsonArray",
            "view": "views/preferences/fields/navbar-config-list"
        },
        "useCustomNavbarConfig": {
            "type": "bool",
            "default": false,
            "tooltip": true
        },
        "activeNavbarConfigId": {
            "type": "varchar",
            "default": null,
            "tooltip": true
        },
```

#### 3. `client/src/helpers/site/tabs.js`
**MODIFY `getTabList()` at lines 67-80** to check navbar config system FIRST:

```javascript
    getTabList() {
        // NEW: Check navbar config system first
        if (this.hasNavbarConfigSystem()) {
            const activeConfig = this.getActiveNavbarConfig();
            
            if (activeConfig && activeConfig.tabList) {
                return Espo.Utils.cloneDeep(activeConfig.tabList);
            }
        }
        
        // Existing logic remains as fallback
        let tabList = this.preferences.get('useCustomTabList') && !this.preferences.get('addCustomTabs') ?
            this.preferences.get('tabList') :
            this.config.get('tabList');

        if (this.preferences.get('useCustomTabList') && this.preferences.get('addCustomTabs')) {
            tabList = [
                ...tabList,
                ...(this.preferences.get('tabList') || []),
            ];
        }

        return Espo.Utils.cloneDeep(tabList) || [];
    }
```

**Add after line 80** (after `getTabList()` method closes), add new methods:

```javascript
    hasNavbarConfigSystem() {
        const configList = this.getNavbarConfigList();
        return configList && configList.length > 0;
    }

    getNavbarConfigList() {
        if (this.config.get('navbarConfigDisabled')) {
            return this.config.get('navbarConfigList') || [];
        }
        
        if (this.preferences.get('useCustomNavbarConfig')) {
            return this.preferences.get('navbarConfigList') || [];
        }
        
        return this.config.get('navbarConfigList') || [];
    }

    getActiveNavbarConfig() {
        const configList = this.getNavbarConfigList();
        
        if (!configList || configList.length === 0) {
            return null;
        }
        
        const activeId = this.preferences.get('activeNavbarConfigId');
        
        if (activeId) {
            const found = configList.find(c => c.id === activeId);
            if (found) return found;
            
            console.warn('Active navbar config ID not found, falling back to default');
        }
        
        return configList.find(c => c.isDefault) || configList[0];
    }

    validateNavbarConfigList(configList) {
        if (!configList || configList.length === 0) return true;
        
        const ids = configList.map(c => c.id).filter(Boolean);
        
        if (new Set(ids).size !== ids.length) {
            throw new Error('Duplicate navbar config IDs detected');
        }
        
        return true;
    }
```

#### 4. `client/src/views/site/navbar.js`
**Multiple edits required:**

**A. Call setupNavbarConfigSelector() after line 461** (after `setup();`):

```javascript
        setup();

        // NEW: Setup navbar config selector
        this.setupNavbarConfigSelector();
```

**B. Update preferences listener at lines 466-478** to include new fields:

```javascript
        this.listenTo(this.getHelper().preferences, 'update', (/** string[] */attributeList) => {
            if (!attributeList) {
                return;
            }

            if (
                attributeList.includes('tabList') ||
                attributeList.includes('addCustomTabs') ||
                attributeList.includes('useCustomTabList') ||
                attributeList.includes('navbarConfigList') ||
                attributeList.includes('useCustomNavbarConfig') ||
                attributeList.includes('activeNavbarConfigId')
            ) {
                update();
            }
        });
```

**C. Add new class methods AFTER line 1045** (after `adjustAfterRender()` method closes, before `selectTab()`):

```javascript
    setupNavbarConfigSelector() {
        if (this.getConfig().get('navbarConfigSelectorDisabled')) {
            return;
        }
        
        const configList = this.tabsHelper.getNavbarConfigList();
        
        if (!configList || configList.length <= 1) {
            return;
        }
        
        this.createView('navbarConfigSelector', 'views/site/navbar-config-selector', {
            el: this.options.el + ' .navbar-config-selector-container',
            configList: configList,
            activeConfigId: this.getPreferences().get('activeNavbarConfigId'),
        }, view => {
            this.listenTo(view, 'select', id => this.switchNavbarConfig(id));
        });
    }

    async switchNavbarConfig(configId) {
        if (this._switchingConfig) {
            return;
        }
        this._switchingConfig = true;
        
        Espo.Ui.notifyWait();
        
        try {
            await Espo.Ajax.putRequest('Preferences/' + this.getUser().id, {
                activeNavbarConfigId: configId
            });
            
            this.getPreferences().set('activeNavbarConfigId', configId);
            this.getPreferences().trigger('update', ['activeNavbarConfigId']);
            
            this.trigger('navbar-config-changed');
            
            Espo.Ui.notify(false);
        } catch (error) {
            Espo.Ui.notify(false);
            Espo.Ui.error(this.translate('errorSavingPreference', 'messages'));
            console.error('Failed to switch navbar config:', error);
        } finally {
            this._switchingConfig = false;
        }
    }
```

#### 5. `client/res/templates/site/navbar.tpl`
**Add inside `.navbar-left-container`, AFTER line 15, BEFORE line 16**:

```html
        <div class="navbar-left-container">
            <div class="navbar-config-selector-container"></div>
            <ul class="nav navbar-nav tabs">
```

#### 6. `frontend/less/espo/elements/navbar.less`
**Add at TOP of file** (before any existing content):

```less
@import 'navbar-config-selector.less';
```

#### 7. `application/Espo/Resources/layouts/Settings/userInterface.json`
**Add new rows to Navbar tab section**. The Navbar tab section (lines 22-30) should become:

```json
    {
        "rows": [
            [{"name": "tabList"}, {"name": "quickCreateList"}],
            [{"name": "scopeColorsDisabled"}, {"name": "tabColorsDisabled"}],
            [{"name": "tabIconsDisabled"}, false],
            [{"name": "navbarConfigList", "fullWidth": true}],
            [{"name": "navbarConfigDisabled"}, {"name": "navbarConfigSelectorDisabled"}]
        ],
        "tabBreak": true,
        "tabLabel": "$label:Navbar"
    },
```

#### 8. `application/Espo/Resources/layouts/Preferences/detail.json`
**Add to User Interface tab's rows array** (after line 120, inside the second User Interface section):

```json
    {
        "rows": [
            [
                {"name": "useCustomTabList"},
                {"name": "addCustomTabs"}
            ],
            [
                {"name": "tabList"},
                false
            ],
            [
                {"name": "useCustomNavbarConfig"},
                false
            ],
            [
                {"name": "navbarConfigList", "fullWidth": true},
                false
            ],
            [
                {"name": "activeNavbarConfigId"},
                false
            ]
        ]
    },
```

#### 9. `application/Espo/Resources/i18n/en_US/Settings.json`
**Add to "fields" section**:

```json
        "navbarConfigList": "Navbar Configurations",
        "navbarConfigDisabled": "Disable User Customization",
        "navbarConfigSelectorDisabled": "Hide Selector Dropdown"
```

**Add to "tooltips" section**:

```json
        "navbarConfigList": "Create multiple navbar configurations that users can switch between. Each configuration has its own tab list.",
        "navbarConfigDisabled": "When enabled, users cannot create their own navbar configurations.",
        "navbarConfigSelectorDisabled": "Hide the dropdown selector from the navbar. Users will only see the default configuration."
```

#### 10. `application/Espo/Resources/i18n/en_US/Preferences.json`
**Add to "fields" section**:

```json
        "navbarConfigList": "My Navbar Configurations",
        "useCustomNavbarConfig": "Use Custom Configurations",
        "activeNavbarConfigId": "Active View"
```

**Add to "tooltips" section**:

```json
        "useCustomNavbarConfig": "Use your own navbar configurations instead of system defaults.",
        "navbarConfigList": "Define your own navbar configurations with custom tab lists.",
        "activeNavbarConfigId": "Select which navbar configuration to display."
```

#### 11. `application/Espo/Resources/i18n/en_US/Global.json`
**Add new `navbarConfig` section AFTER line 994** (after `navbarTabs` closing brace):

```json
    "navbarConfig": {
        "switchView": "Switch View",
        "defaultConfig": "Default",
        "customConfig": "Custom",
        "noConfigs": "No configurations available",
        "selectConfig": "Select a view..."
    },
```

**Add `errorSavingPreference` to "messages" section AFTER line 387** (after `resetPreferencesDone`):

```json
        "errorSavingPreference": "Failed to save preference. Please try again.",
```

#### 12. `client/src/views/preferences/record/edit.js`
**Add after line 146** (after `userThemesDisabled` check, inside `setup()` method):

```javascript
        if (this.getConfig().get('userThemesDisabled')) {
            this.hideField('theme');
        }

        // NEW: Hide navbar config fields if customization is disabled
        if (this.getConfig().get('navbarConfigDisabled')) {
            this.hideField('navbarConfigList');
            this.hideField('useCustomNavbarConfig');
            this.hideField('activeNavbarConfigId');
        }
```

---

### Files to DELETE

None.

---

### Files to CONSIDER

| File | Reason |
|------|--------|
| `application/Espo/Resources/metadata/entityDefs/Portal.json` | Portal has separate tabList system - explicitly **OUT OF SCOPE** for v8 |
| `client/src/views/portal/navbar.js` | Portal navbar - explicitly **OUT OF SCOPE** for v8 |

---

### Related Files (for reference only, no changes needed)

| File | Pattern Reference |
|------|-------------------|
| `client/src/views/settings/modals/edit-tab-group.js` | **CRITICAL REFERENCE** for modal model creation pattern (lines 94-117) |
| `client/src/views/settings/modals/tab-list-field-add.js` | Add item modal pattern - extends `ArrayFieldAddModalView` |
| `client/src/views/settings/fields/tab-list.js` | **PRIMARY PATTERN** for `navbar-config-list.js` - `generateItemId()` at line 54-55 |
| `client/src/views/preferences/fields/tab-list.js` | Preferences field extending Settings field pattern |
| `client/src/helpers/site/tabs.js` | Existing `getTabList()` method to modify |
| `client/src/views/site/navbar.js` | Existing navbar view with `tabsHelper` instantiation at line 429 |
| `client/res/templates/site/navbar.tpl` | Navbar template with `navbar-left-container` at line 15 |
| `frontend/less/espo/root-variables.less` | CSS variable definitions |
| `frontend/less/espo/bootstrap/variables.less` | Bootstrap variable `@screen-xs-max` at line 37 |
| `client/src/view-helper.js` | `ifEqual` Handlebars helper at line 386 |
| `application/Espo/Resources/i18n/en_US/Global.json` (lines 988-994) | Existing `navbarTabs` translation section pattern |
| `client/src/model.js` | Model class for `import Model from 'model'` |

---

## Error Handling

### Missing Config Fallback
The `getActiveNavbarConfig()` method handles:
- Empty config list → returns `null` → triggers legacy fallback
- Invalid `activeNavbarConfigId` → logs warning → falls back to default/first
- No default set → uses first config

### AJAX Error Handling
The `switchNavbarConfig()` method includes:
- Race condition prevention with `this._switchingConfig` flag
- Loading indicator with `Espo.Ui.notifyWait()`
- try/catch block for network failures
- User-facing error message with `Espo.Ui.error()`
- Console error logging for debugging

---

## No Migration Strategy Required

- Existing `tabList` preferences continue to work unchanged
- Navbar config system activates only when `navbarConfigList` is populated
- No automatic migration of existing `tabList` to navbar config format
- Users who want navbar configs must explicitly create them

---

## Implementation Order

1. **Phase 1: Data Model**
   - Add entity definitions (Settings.json, Preferences.json)
   - Add translations

2. **Phase 2: Helper Logic**
   - Modify TabsHelper with new methods
   - Add validation logic

3. **Phase 3: Admin UI**
   - Create field views for navbar config list
   - Create modal views for adding/editing configs
   - Update layout files

4. **Phase 4: Navbar UI**
   - Create navbar config selector component
   - Modify navbar view
   - Modify navbar template

5. **Phase 5: Styling**
   - Add CSS for selector component
   - Update navbar.less
   - Add responsive rules

6. **Phase 6: Testing**
   - Test backward compatibility with existing `useCustomTabList`
   - Test ID validation
   - Test missing config fallback
   - Test selector visibility logic
   - Test error handling for AJAX failures
   - Test keyboard navigation
   - Test responsive behavior on mobile

---

*Scope document v8 generated with all v7 audit corrections applied - READY FOR IMPLEMENTATION*