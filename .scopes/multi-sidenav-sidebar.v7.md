# Multi-Sidenav Sidebar Mode - Implementation Plan v7

> **Version**: 7.0  
> **Based on**: `.scopes/multi-sidenav-sidebar.v6.md` and `.scopes/multi-sidenav-sidebar.v6.audit.md`  
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

## Audit Corrections Applied (v6 → v7)

| Issue | v6 Problem | v7 Correction |
|-------|-----------|---------------|
| navbar.tpl line reference | "Add at line 15" - ambiguous | Insert AFTER line 15 (inside navbar-left-container), BEFORE line 16 |
| Preferences/detail.json line reference | "Add after line 121" - outside array | Insert AFTER line 120, INSIDE the rows array |
| Missing backend validation | No validation for activeNavbarConfigId | Add ValidatorClassName or backend validation |
| setupNavbarConfigSelector() insertion | "Add after line 461" - inside setup() | Add as class method after adjustAfterRender() (after line 1000) |
| Global.json messages insertion | Not specified | Insert after line 387 (after resetPreferencesDone) |
| navbar-config.js handler | Unclear if needed | **REMOVED** - selector triggers events directly |
| Dynamic logic pattern | Not specified | Use `this.getConfig().get('navbarConfigDisabled')` with `this.hideField()` |

---

## Decisions Made

| Question | Decision | Rationale |
|----------|----------|-----------|
| Default Behavior | Keep existing `tabList` as fallback; first navbar config must be explicitly created | Backward compatible, no migration needed |
| Selector Visibility | Hidden when ≤1 navbar config exists | Cleaner UI when feature not actively used |
| Admin UI | Field on User Interface page (not separate page) | Simpler implementation, follows existing pattern |
| Portal Support | **Out of scope** for v7 | Portal has separate `tabList` system; can be added later |
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

        // Ensure each config has an ID
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
        return 'navbar-config-' + Date.now().toString(36) + '-' + 
               Math.floor(Math.random() * 1000000 + 1).toString();
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
        // Override to handle complex objects
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

    // Inherits all functionality from Settings version
    // Customization can be added here if needed

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
        // Get config list from system or user preferences
        let configList = [];
        
        if (this.getConfig().get('navbarConfigDisabled')) {
            // User customization disabled - use system configs
            configList = this.getConfig().get('navbarConfigList') || [];
        } else if (this.model.get('useCustomNavbarConfig')) {
            // User has custom configs - use theirs
            configList = this.model.get('navbarConfigList') || [];
        } else {
            // Default - use system configs
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
        
        // Add empty option
        if (this.params.options.length === 0) {
            this.params.options = [''];
            this.translatedOptions[''] = this.translate('noConfigs', 'navbarConfig');
        }
    }
    
    // Re-setup options when relevant fields change
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

import ModalView from 'views/modal';

class EditNavbarConfigModalView extends ModalView {

    template = 'modals/edit-navbar-config'
    
    className = 'dialog dialog-record'
    
    backdrop = true
    
    setup() {
        super.setup();
        
        this.headerText = this.translate('Edit Navbar Configuration', 'labels', 'Settings');
        
        const configData = this.options.configData || {};
        
        this.waitForView('edit');
        
        this.getModelFactory().create('NavbarConfig', model => {
            model.set({
                id: configData.id || null,
                name: configData.name || '',
                iconClass: configData.iconClass || 'fas fa-th-large',
                color: configData.color || '',
                tabList: configData.tabList || [],
                isDefault: configData.isDefault || false,
            });
            
            this.createView('edit', 'views/record/edit-for-modal', {
                selector: '.edit-container',
                model: model,
                detailLayout: this.getDetailLayout(),
            }, view => {
                this.listenTo(view, 'after:save', () => {
                    this.trigger('save', model.attributes);
                    this.close();
                });
            });
        });
    }
    
    getDetailLayout() {
        return {
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
    }
    
    actionSave() {
        const view = this.getView('edit');
        
        if (view) {
            view.save();
        }
    }
}

export default EditNavbarConfigModalView;
```

#### 5. `client/src/views/modals/navbar-config-field-add.js`
Add item modal for navbar-config-list field.

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

import ModalView from 'views/modal';

class NavbarConfigFieldAddModalView extends ModalView {

    template = 'modals/navbar-config-field-add'
    
    className = 'dialog dialog-record'
    
    setup() {
        super.setup();
        
        this.headerText = this.translate('Add Navbar Configuration', 'labels', 'Settings');
        
        this.buttons = [
            {
                name: 'create',
                label: 'Create',
                style: 'primary',
                onClick: () => this.actionCreate(),
            },
            {
                name: 'cancel',
                label: 'Cancel',
                onClick: () => this.close(),
            }
        ];
        
        this.waitForView('edit');
        
        this.getModelFactory().create('NavbarConfig', model => {
            model.set({
                name: '',
                iconClass: 'fas fa-th-large',
                color: '',
                tabList: [],
                isDefault: false,
            });
            
            this.createView('edit', 'views/record/edit-for-modal', {
                selector: '.edit-container',
                model: model,
                detailLayout: {
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
                },
            });
        });
    }
    
    actionCreate() {
        const view = this.getView('edit');
        
        if (view) {
            const data = view.fetch();
            
            if (!data.name) {
                Espo.Ui.error(this.translate('fieldIsRequired', 'messages').replace('{field}', 'Name'));
                return;
            }
            
            const id = 'navbar-config-' + Date.now().toString(36) + '-' + 
                       Math.floor(Math.random() * 1000000 + 1).toString();
            
            this.trigger('add', { ...data, id: id });
            this.close();
        }
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
        
        // Find active config
        let activeConfig = configList.find(c => c.id === activeConfigId);
        
        // Fall back to default or first
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
        
        // Keyboard navigation support
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

**CRITICAL**: Uses `#ifEqual` (verified at view-helper.js:386), NOT `#ifEquals`.

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
**Add after line 80** (after `getTabList()` method closes), add new methods:

```javascript
    /**
     * Check if navbar config system is active.
     * @return {boolean}
     */
    hasNavbarConfigSystem() {
        const configList = this.getNavbarConfigList();
        return configList && configList.length > 0;
    }

    /**
     * Get navbar config list based on user/system settings.
     * @return {Object[]}
     */
    getNavbarConfigList() {
        if (this.config.get('navbarConfigDisabled')) {
            return this.config.get('navbarConfigList') || [];
        }
        
        if (this.preferences.get('useCustomNavbarConfig')) {
            return this.preferences.get('navbarConfigList') || [];
        }
        
        return this.config.get('navbarConfigList') || [];
    }

    /**
     * Get the active navbar configuration.
     * @return {Object|null}
     */
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

    /**
     * Validate navbar config list for ID uniqueness.
     * @param {Object[]} configList
     * @return {boolean}
     * @throws {Error} If duplicate IDs found
     */
    validateNavbarConfigList(configList) {
        if (!configList || configList.length === 0) return true;
        
        const ids = configList.map(c => c.id).filter(Boolean);
        
        if (new Set(ids).size !== ids.length) {
            throw new Error('Duplicate navbar config IDs detected');
        }
        
        return true;
    }
```

**MODIFY `getTabList()` at lines 67-80** to check navbar config system FIRST:

```javascript
    /**
     * Get the tab list.
     *
     * @return {(TabsHelper~item|string)[]}
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

#### 4. `client/src/views/site/navbar.js`
**Add as new class methods after line 1000** (after `adjustAfterRender()` method, before closing brace):

```javascript
    /**
     * Setup the navbar config selector.
     * @private
     */
    setupNavbarConfigSelector() {
        if (this.getConfig().get('navbarConfigSelectorDisabled')) {
            return;
        }
        
        const configList = this.tabsHelper.getNavbarConfigList();
        
        // Hide selector if only 0-1 configs
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

    /**
     * Switch to a different navbar configuration.
     * @param {string} configId
     * @private
     */
    async switchNavbarConfig(configId) {
        // Prevent race conditions
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
            
            // Trigger re-render of tabs
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

**Update preferences listener at lines 466-478** to include new fields:

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

**Call setupNavbarConfigSelector() in setup() method** after line 461:

```javascript
        setup();

        // NEW: Setup navbar config selector
        this.setupNavbarConfigSelector();
```

#### 5. `client/res/templates/site/navbar.tpl`
**CORRECTED**: Add inside `.navbar-left-container`, **AFTER line 15, BEFORE line 16**:

```html
        <div class="navbar-left-container">
            <div class="navbar-config-selector-container"></div>
            <ul class="nav navbar-nav tabs">
```

The selector container must be added as a new element inside the existing `navbar-left-container` div.

#### 6. `frontend/less/espo/elements/navbar.less`
**Add at end of file** (after line 387, before closing brace):

```less
@import 'navbar-config-selector.less';
```

#### 7. `application/Espo/Resources/layouts/Settings/userInterface.json`
**Add after line 26** (in the Navbar tab section, after `tabIconsDisabled` row):

```json
            [{"name": "navbarConfigList", "fullWidth": true}],
            [{"name": "navbarConfigDisabled"}, {"name": "navbarConfigSelectorDisabled"}]
```

Complete Navbar tab section:
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
**CORRECTED**: Add **after line 120** (after `tabList` row), **inside the User Interface tab's rows array**:

The User Interface tab section (lines 102-122) should become:

```json
    {
        "tabBreak": true,
        "tabLabel": "$label:User Interface",
        "rows": [
            [
                {"name": "theme"},
                {"name": "pageContentWidth"}
            ]
        ]
    },
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
Add to "fields" section:

```json
        "navbarConfigList": "Navbar Configurations",
        "navbarConfigDisabled": "Disable User Customization",
        "navbarConfigSelectorDisabled": "Hide Selector Dropdown"
```

Add to "tooltips" section:

```json
        "navbarConfigList": "Create multiple navbar configurations that users can switch between. Each configuration has its own tab list.",
        "navbarConfigDisabled": "When enabled, users cannot create their own navbar configurations.",
        "navbarConfigSelectorDisabled": "Hide the dropdown selector from the navbar. Users will only see the default configuration."
```

#### 10. `application/Espo/Resources/i18n/en_US/Preferences.json`
Add to "fields" section:

```json
        "navbarConfigList": "My Navbar Configurations",
        "useCustomNavbarConfig": "Use Custom Configurations",
        "activeNavbarConfigId": "Active View"
```

Add to "tooltips" section:

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
| `application/Espo/Resources/metadata/entityDefs/Portal.json` | Portal has separate tabList system - explicitly **OUT OF SCOPE** for v7 |
| `client/src/views/portal/navbar.js` | Portal navbar - explicitly **OUT OF SCOPE** for v7 |

---

### Related Files (for reference only, no changes needed)

| File | Pattern Reference |
|------|-------------------|
| `client/src/views/settings/modals/edit-tab-group.js` | Modal pattern for edit-navbar-config.js - 140 lines |
| `client/src/views/settings/modals/edit-tab-url.js` | Additional modal pattern reference |
| `client/src/views/settings/modals/tab-list-field-add.js` | Add item modal pattern for navbar-config-field-add.js - 94 lines |
| `client/src/views/settings/fields/tab-list.js` | **PRIMARY PATTERN** for navbar-config-list.js - `generateItemId()` at line 54-55 |
| `client/src/views/settings/fields/dashboard-layout.js` | Complex jsonArray field pattern - 581 lines |
| `client/src/views/preferences/fields/tab-list.js` | Preferences field extending Settings field - 65 lines |
| `client/src/views/fields/group-tab-list.js` | Simple extension pattern - 36 lines |
| `client/src/handlers/navbar-menu.js` | Handler pattern - 49 lines (if handler needed in future) |
| `client/src/views/fields/enum.js` | Base class for active-navbar-config.js |
| `frontend/less/espo/root-variables.less` | CSS variable definitions |
| `frontend/less/espo/elements/navbar.less` | Responsive patterns for mobile |
| `client/src/view-helper.js` | `ifEqual` Handlebars helper at line 386 |
| `application/Espo/Resources/i18n/en_US/Global.json` (lines 988-994) | Existing `navbarTabs` translation section pattern |

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

*Scope document v7 generated with all v6 audit corrections applied - READY FOR IMPLEMENTATION*
