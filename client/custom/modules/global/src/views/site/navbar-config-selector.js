/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import View from 'view';

class NavbarConfigSelectorView extends View {

    template = 'global:site/navbar-config-selector'

    events = {
        'click .navbar-config-option': function (e) {
            e.preventDefault();

            const id = e.currentTarget.dataset.id;

            this.trigger('switch', id);
        },
    }

    data() {
        const activeConfigId = this.activeConfigId;
        const configList = (this.options.configList || [])
            .filter(c => !c.hideOnDropdown || c.id === activeConfigId);

        const activeConfig = configList.find(c => c.id === activeConfigId)
            || configList.find(c => c.isDefault)
            || configList[0];

        return {
            configList: configList.map(c => ({
                ...c,
                isActive: c.id === (activeConfig ? activeConfig.id : null),
            })),
            activeConfig: activeConfig || null,
            hasMultiple: configList.length > 1,
        };
    }

    setup() {
        this.activeConfigId = this.options.activeConfigId || null;
    }

    setActiveConfig(id) {
        this.activeConfigId = id;
        this.reRender();
    }

    updateConfigList(configList) {
        this.options.configList = configList;
        this.reRender();
    }
}

export default NavbarConfigSelectorView;
