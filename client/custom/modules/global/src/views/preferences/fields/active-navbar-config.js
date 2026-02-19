/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import EnumFieldView from 'views/fields/enum';

class ActiveNavbarConfigFieldView extends EnumFieldView {

    setup() {
        super.setup();

        this.setupOptions();
        this.listenTo(this.model, 'change:navbarConfigList', () => this.setupOptions());
        this.listenTo(this.model, 'change:useCustomNavbarConfig', () => this.setupOptions());

        if (this.getConfig().get('navbarConfigDisabled')) {
            this.hide();
        }
    }

    setupOptions() {
        const configList = this.getResolvedConfigList();

        this.params.options = ['', ...configList.map(c => c.id)];

        this.translatedOptions = { '': this.translate('Default') };

        configList.forEach(c => {
            this.translatedOptions[c.id] = c.name || c.id;
        });
    }

    getResolvedConfigList() {
        if (this.getConfig().get('navbarConfigDisabled')) {
            return this.getConfig().get('navbarConfigList') || [];
        }

        if (this.model.get('useCustomNavbarConfig')) {
            return this.model.get('navbarConfigList') || [];
        }

        return this.getConfig().get('navbarConfigList') || [];
    }
}

export default ActiveNavbarConfigFieldView;
