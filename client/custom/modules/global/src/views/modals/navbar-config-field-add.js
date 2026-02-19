/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import Modal from 'views/modal';
import Model from 'model';

class NavbarConfigFieldAddModalView extends Modal {

    className = 'dialog dialog-record'

    templateContent = `<div class="record no-side-margin">{{{record}}}</div>`

    setup() {
        super.setup();

        this.headerText = this.translate('Add Navbar Configuration', 'labels', 'Settings');

        this.buttonList.push({
            name: 'add',
            label: 'Add',
            style: 'danger',
        });

        this.buttonList.push({
            name: 'cancel',
            label: 'Cancel',
        });

        this.shortcutKeys = {
            'Control+Enter': () => this.actionAdd(),
        };

        const detailLayout = [
            {
                rows: [
                    [
                        {
                            name: 'name',
                            labelText: this.translate('name', 'fields'),
                        },
                        {
                            name: 'iconClass',
                            labelText: this.translate('iconClass', 'fields', 'EntityManager'),
                        },
                    ],
                    [
                        {
                            name: 'color',
                            labelText: this.translate('color', 'fields', 'EntityManager'),
                        },
                        false,
                    ],
                ],
            },
        ];

        const model = this.model = new Model();

        model.name = 'NavbarConfig';

        model.setDefs({
            fields: {
                name: {
                    type: 'varchar',
                    required: true,
                },
                iconClass: {
                    type: 'base',
                    view: 'views/admin/entity-manager/fields/icon-class',
                },
                color: {
                    type: 'base',
                    view: 'views/fields/colorpicker',
                },
            },
        });

        this.createView('record', 'views/record/edit-for-modal', {
            detailLayout: detailLayout,
            model: model,
            selector: '.record',
        });
    }

    actionAdd() {
        const recordView = this.getView('record');

        if (recordView.validate()) {
            return;
        }

        const data = recordView.fetch();

        this.trigger('add', data);
        this.close();
    }
}

export default NavbarConfigFieldAddModalView;
