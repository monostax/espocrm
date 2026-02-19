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

export default class VirtualFolderFilterFieldView extends EnumFieldView {

    setup() {
        this.setupOptions();

        this.listenTo(this.model, 'change:entityType', () => {
            this.model.set('filterName', null, {silent: true});
            this.setupOptions();
            this.reRender();
        });

        super.setup();
    }

    setupOptions() {
        const entityType = this.model.get('entityType');

        if (!entityType) {
            this.params.options = [''];
            this.translatedOptions = {'': this.translate('No Filter', 'labels', 'Global')};
            return;
        }

        const options = [''];

        const systemFilters = this.getMetadata().get(['clientDefs', entityType, 'filterList']) || [];
        
        systemFilters.forEach(item => {
            if (typeof item === 'object' && item.name) {
                if (item.aux) return;
                options.push(item.name);
            } else if (typeof item === 'string') {
                options.push(item);
            }
        });

        const userFilters = (this.getPreferences().get('presetFilters') || {})[entityType] || [];
        
        userFilters.forEach(item => {
            if (item.name) {
                options.push(item.name);
            }
        });

        this.params.options = options;

        this.translatedOptions = {
            '': this.translate('No Filter', 'labels', 'Global')
        };

        options.forEach(name => {
            if (name === '') return;

            const systemFilter = systemFilters.find(item => {
                if (typeof item === 'object') {
                    return item.name === name;
                }
                return item === name;
            });

            if (systemFilter) {
                if (typeof systemFilter === 'object' && systemFilter.label) {
                    this.translatedOptions[name] = systemFilter.label;
                } else {
                    this.translatedOptions[name] = this.translate(name, 'presetFilters', entityType);
                }
            } else {
                const userFilter = userFilters.find(item => item.name === name);
                if (userFilter && userFilter.label) {
                    this.translatedOptions[name] = userFilter.label;
                } else {
                    this.translatedOptions[name] = name;
                }
            }
        });
    }
}