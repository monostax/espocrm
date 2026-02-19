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

class EditNavbarConfigModalView extends Modal {

    className = 'dialog dialog-record'

    templateContent = `<div class="record no-side-margin">{{{record}}}</div>`

    setup() {
        super.setup();

        const isNew = this.options.isNew;

        this.headerText = isNew
            ? this.translate('Add Navbar Configuration', 'labels', 'Settings')
            : this.translate('Edit Navbar Configuration', 'labels', 'Settings');

        this.buttonList.push({
            name: 'apply',
            label: 'Apply',
            style: 'danger',
        });

        this.buttonList.push({
            name: 'cancel',
            label: 'Cancel',
        });

        this.shortcutKeys = {
            'Control+Enter': () => this.actionApply(),
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
                        {
                            name: 'isDefault',
                            labelText: this.translate('defaultConfig', 'navbarConfig', 'Global'),
                        },
                    ],
                    [
                        {
                            name: 'tabList',
                            labelText: this.translate('tabList', 'fields', 'Settings'),
                        },
                        false,
                    ],
                ],
            },
        ];

        const model = this.model = new Model();

        model.name = 'NavbarConfig';
        model.set(this.options.itemData);

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
                isDefault: {
                    type: 'bool',
                },
                tabList: {
                    type: 'array',
                    view: 'views/settings/fields/tab-list',
                },
            },
        });

        this.createView('record', 'views/record/edit-for-modal', {
            detailLayout: detailLayout,
            model: model,
            selector: '.record',
        });
    }

    actionApply() {
        const recordView = this.getView('record');

        if (recordView.validate()) {
            return;
        }

        const data = recordView.fetch();

        data.id = this.options.itemData.id;

        this.trigger('apply', data);
    }
}

export default EditNavbarConfigModalView;
