/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import TabListFieldAddSettingsModalView from 'views/settings/modals/tab-list-field-add';

export default class CustomTabListFieldAddModal extends TabListFieldAddSettingsModalView {

    setup() {
        super.setup();

        this.addButton({
            name: 'addVirtualFolder',
            text: this.translate('Virtual Folder', 'labels', 'Settings'),
            onClick: () => this.actionAddVirtualFolder(),
            position: 'right',
            iconClass: 'fas fa-plus fa-sm',
        });
    }

    actionAddVirtualFolder() {
        this.trigger('add', {
            type: 'virtualFolder',
            id: 'vf-' + Math.floor(Math.random() * 1000000 + 1),
            label: null,
            entityType: null,
            filterName: null,
            maxItems: 5,
            iconClass: null,
            color: null,
            orderBy: null,
            order: 'desc',
            openMode: 'view',
            relationshipLink: null,
        });
    }
}