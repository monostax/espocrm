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
 * Manage Users Modal for Calendar
 *
 * Allows users to add/remove users from their calendar filter list
 * (Google Calendar style "Other calendars" management)
 */

import ModalView from 'views/modal';
import Model from 'model';
import EditForModalRecordView from 'views/record/edit-for-modal';
import CalendarUsersFieldView from 'crm:views/calendar/fields/users';

class ManageUsersModal extends ModalView {

    className = 'dialog dialog-record'

    templateContent = `
        <div class="record-container no-side-margin">{{{record}}}</div>
    `

    /**
     * @private
     * @type {EditForModalRecordView}
     */
    recordView

    /**
     * @param {{
     *     users: {id: string, name: string}[],
     * }} options
     */
    constructor(options) {
        super(options);
        this.options = options;
    }

    setup() {
        this.buttonList = [
            {
                name: 'save',
                label: 'Save',
                style: 'primary',
                onClick: () => this.actionSave(),
            },
            {
                name: 'cancel',
                label: 'Cancel',
                onClick: () => this.actionClose(),
            },
        ];

        this.headerText = this.translate('Manage Calendar Users', 'labels', 'Calendar');

        const users = this.options.users || [];

        const userIdList = [];
        const userNames = {};

        users.forEach(item => {
            userIdList.push(item.id);
            userNames[item.id] = item.name;
        });

        this.model = new Model({
            usersIds: userIdList,
            usersNames: userNames,
        });

        this.recordView = new EditForModalRecordView({
            model: this.model,
            detailLayout: [
                {
                    rows: [
                        [
                            {
                                view: new CalendarUsersFieldView({
                                    name: 'users',
                                }),
                            },
                            false
                        ]
                    ]
                }
            ]
        });

        this.assignView('record', this.recordView);
    }

    /**
     * @private
     */
    actionSave() {
        const data = this.recordView.processFetch();

        if (this.recordView.validate()) {
            return;
        }

        /** @type {{id: string, name: string}[]} */
        const users = [];

        const userIds = this.model.attributes.usersIds || [];

        userIds.forEach(id => {
            users.push({
                id: id,
                name: (data.usersNames || {})[id] || id
            });
        });

        this.trigger('apply', {users: users});

        this.close();
    }
}

export default ManageUsersModal;
