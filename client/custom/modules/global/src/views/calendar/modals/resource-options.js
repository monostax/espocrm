/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Resource Options Modal - Select users to display as resources
 *
 * @module custom/modules/global/views/calendar/modals/resource-options
 */

import ModalView from 'views/modal';
import Model from 'model';

class ResourceOptionsModal extends ModalView {

    className = 'dialog dialog-record'

    templateContent = `
        <div class="record-container">{{{record}}}</div>
    `

    setup() {
        this.buttonList = [
            {
                name: 'apply',
                label: 'Apply',
                style: 'primary',
            },
            {
                name: 'cancel',
                label: 'Cancel',
            },
        ];

        this.headerText = this.translate('Select Resources', 'labels', 'Calendar');

        const model = this.model = new Model();

        model.name = 'ResourceOptions';

        const userIdList = [];
        const usersNames = {};

        (this.options.users || []).forEach(item => {
            userIdList.push(item.id);
            usersNames[item.id] = item.name;
        });

        model.set({
            usersIds: userIdList,
            usersNames: usersNames,
        });

        this.createView('record', 'views/record/edit-for-modal', {
            model: model,
            selector: '.record-container',
            detailLayout: [
                {
                    rows: [
                        [
                            {
                                name: 'users',
                                labelText: this.translate('Users'),
                            },
                        ],
                    ],
                },
            ],
        }, view => {
            view.setFieldReadOnly('users', false);
        });
    }

    // noinspection JSUnusedGlobalSymbols
    actionApply() {
        const usersIds = this.model.get('usersIds') || [];
        const usersNames = this.model.get('usersNames') || {};

        const users = [];

        usersIds.forEach(id => {
            users.push({
                id: id,
                name: usersNames[id] || id,
            });
        });

        this.trigger('apply', {users: users});

        this.close();
    }
}

export default ResourceOptionsModal;
