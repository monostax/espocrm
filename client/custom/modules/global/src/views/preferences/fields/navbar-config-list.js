/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import NavbarConfigListFieldView from 'global:views/settings/fields/navbar-config-list';

class PreferencesNavbarConfigListFieldView extends NavbarConfigListFieldView {

    setup() {
        super.setup();

        if (this.getConfig().get('navbarConfigDisabled')) {
            this.hide();
        }
    }
}

export default PreferencesNavbarConfigListFieldView;
