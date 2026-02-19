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

export default class EditTabVirtualFolderModalView extends Modal {

    className = 'dialog dialog-record'

    templateContent = `<div class="record no-side-margin">{{{record}}}</div>`

    setup() {
        super.setup();

        this.headerText = this.translate('Edit Virtual Folder', 'labels', 'Global');

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
                            name: 'label',
                            labelText: this.translate('label', 'fields', 'Admin'),
                        },
                        {
                            name: 'entityType',
                            labelText: this.translate('entityType', 'fields', 'Global'),
                            view: 'global:views/settings/fields/virtual-folder-entity',
                        },
                        {
                            name: 'filterName',
                            labelText: this.translate('filterName', 'fields', 'Global'),
                            view: 'global:views/settings/fields/virtual-folder-filter',
                        },
                    ],
                    [
                        {
                            name: 'maxItems',
                            labelText: this.translate('maxItems', 'fields', 'Global'),
                        },
                        {
                            name: 'iconClass',
                            labelText: this.translate('iconClass', 'fields', 'EntityManager'),
                            view: 'views/admin/entity-manager/fields/icon-class',
                        },
                        {
                            name: 'color',
                            labelText: this.translate('color', 'fields', 'EntityManager'),
                            view: 'views/fields/colorpicker',
                        },
                    ],
                    [
                        {
                            name: 'orderBy',
                            labelText: this.translate('orderBy', 'fields', 'Global'),
                        },
                        {
                            name: 'order',
                            labelText: this.translate('order', 'fields', 'Global'),
                        },
                        false,
                    ],
                    [
                        {
                            name: 'openMode',
                            labelText: this.translate('openMode', 'fields', 'Global'),
                        },
                        {
                            name: 'relationshipLink',
                            labelText: this.translate('relationshipLink', 'fields', 'Global'),
                            view: 'global:views/settings/fields/virtual-folder-relationship-link',
                        },
                        false,
                    ],
                ]
            }
        ];

        const model = this.model = new Model();

        model.name = 'VirtualFolderTab';
        model.set(this.options.itemData);

        model.setDefs({
            fields: {
                label: {
                    type: 'varchar',
                },
                entityType: {
                    type: 'enum',
                    required: true,
                },
                filterName: {
                    type: 'enum',
                },
                maxItems: {
                    type: 'int',
                    default: 5,
                },
                iconClass: {
                    type: 'base',
                    view: 'views/admin/entity-manager/fields/icon-class',
                },
                color: {
                    type: 'base',
                    view: 'views/fields/colorpicker',
                },
                orderBy: {
                    type: 'varchar',
                },
                order: {
                    type: 'enum',
                    options: ['asc', 'desc'],
                    default: 'desc',
                },
                openMode: {
                    type: 'enum',
                    options: ['view', 'relationship'],
                    default: 'view',
                },
                relationshipLink: {
                    type: 'enum',
                },
            },
        });

        this.createView('record', 'views/record/edit-for-modal', {
            detailLayout: detailLayout,
            model: model,
            selector: '.record',
        });

        this.listenTo(model, 'change:openMode', () => {
            if (model.get('openMode') === 'view') {
                model.set('relationshipLink', null, {silent: true});
            }
        });
    }

    actionApply() {
        const recordView = this.getView('record');
        const model = this.model;

        if (model.get('openMode') === 'relationship' && !model.get('relationshipLink')) {
            const relationshipLinkField = recordView.getFieldView('relationshipLink');
            if (relationshipLinkField) {
                relationshipLinkField.showValidationMessage(
                    this.translate('relationshipLinkRequired', 'messages', 'Global'));
            } else {
                console.warn('relationshipLink field view not found for validation');
            }
            return;
        }

        if (recordView.validate()) {
            return;
        }

        const data = recordView.fetch();

        this.trigger('apply', data);
    }
}