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

export default class VirtualFolderRelationshipLinkFieldView extends EnumFieldView {

    setup() {
        this.setupOptions();

        this.listenTo(this.model, 'change:entityType', () => {
            this.model.set('relationshipLink', null, {silent: true});
            this.setupOptions();
            this.reRender();
        });

        super.setup();
    }

    setupOptions() {
        const entityType = this.model.get('entityType');

        if (!entityType) {
            this.params.options = [''];
            this.translatedOptions = {'': this.translate('Select Entity First', 'labels', 'Global')};
            return;
        }

        const linkDefs = this.getMetadata().get(['entityDefs', entityType, 'links']) || {};

        const options = [''];

        for (const link in linkDefs) {
            const def = linkDefs[link];

            if (def.disabled || def.utility || def.layoutRelationshipsDisabled) {
                continue;
            }

            if (!['hasMany', 'hasChildren'].includes(def.type)) {
                continue;
            }

            options.push(link);
        }

        this.params.options = options;

        this.translatedOptions = {
            '': this.translate('No Relationship', 'labels', 'Global')
        };

        options.forEach(link => {
            if (link === '') return;

            this.translatedOptions[link] = this.translate(link, 'links', entityType);
        });

        const sortedOptions = options.slice(1).sort((a, b) => {
            const labelA = this.translatedOptions[a] || a;
            const labelB = this.translatedOptions[b] || b;
            return labelA.localeCompare(labelB);
        });

        this.params.options = ['', ...sortedOptions];
    }
}