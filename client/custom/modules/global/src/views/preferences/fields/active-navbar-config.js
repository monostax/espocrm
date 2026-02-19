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

const DEFAULT_TABLIST_ID = '__default_tablist__';

class ActiveNavbarConfigFieldView extends EnumFieldView {

    setup() {
        super.setup();

        this.setupOptions();
    }

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
}

export default ActiveNavbarConfigFieldView;
