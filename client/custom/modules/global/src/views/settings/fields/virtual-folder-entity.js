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

export default class VirtualFolderEntityFieldView extends EnumFieldView {

    setupOptions() {
        this.params.options = Object.keys(this.getMetadata().get('scopes'))
            .filter(scope => {
                if (this.getMetadata().get(`scopes.${scope}.disabled`)) {
                    return false;
                }

                if (!this.getAcl().checkScope(scope, 'read')) {
                    return false;
                }

                return this.getMetadata().get(`scopes.${scope}.tab`);
            })
            .sort((v1, v2) => {
                return this.translate(v1, 'scopeNamesPlural')
                    .localeCompare(this.translate(v2, 'scopeNamesPlural'));
            });

        this.translatedOptions = {};

        this.params.options.forEach(item => {
            this.translatedOptions[item] = this.translate(item, 'scopeNamesPlural');
        });
    }
}