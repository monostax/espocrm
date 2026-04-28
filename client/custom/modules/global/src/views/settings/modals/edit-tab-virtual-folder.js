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
import SearchManager from 'search-manager';

export default class EditTabVirtualFolderModalView extends Modal {

    className = 'dialog dialog-record'

    templateContent = `
        <div class="record no-side-margin">{{{record}}}</div>
        <div class="panel panel-default margin-top">
            <div class="panel-heading">
                <span class="panel-title">{{translate 'Filter'}}</span>
            </div>
            <div class="panel-body">
                <div class="text-muted small margin-bottom">
                    {{translate 'Use the same filters available in list views. Access control is still enforced when the folder loads records.' scope='Global'}}
                </div>
                <div class="filter-builder-container"></div>
            </div>
        </div>
    `

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
                        false,
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
                filterData: {
                    type: 'jsonObject',
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

        this.listenTo(model, 'change:entityType', () => {
            model.set('filterName', null, {silent: true});
            model.set('filterData', null, {silent: true});

            if (this.isRendered()) {
                this.createFilterBuilder();
            }
        });

        this.listenTo(model, 'change:openMode', () => {
            if (model.get('openMode') === 'view') {
                model.set('relationshipLink', null, {silent: true});
            }
        });
    }

    afterRender() {
        super.afterRender();

        this.createFilterBuilder();
    }

    createFilterBuilder() {
        const entityType = this.model.get('entityType');

        this.clearView('filterBuilder');

        if (!entityType) {
            const container = this.element && this.element.querySelector('.filter-builder-container');

            if (container) {
                container.innerHTML = `<span class="text-muted">${this.translate('Select an entity type first.', 'messages', 'Global')}</span>`;
            }

            return;
        }

        this.getCollectionFactory().create(entityType, collection => {
            const searchManager = new SearchManager(collection, {
                defaultData: this.getSearchDefaultData(),
                emptyOnReset: true,
            });

            searchManager.scope = entityType;
            collection.where = searchManager.getWhere();

            this.filterSearchManager = searchManager;

            this.createView('filterBuilder', 'views/record/search', {
                collection: collection,
                selector: '.filter-builder-container',
                searchManager: searchManager,
                disableSavePreset: true,
                isWide: true,
            }, view => {
                view.render();
            });
        });
    }

    getSearchDefaultData() {
        const filterData = this.model.get('filterData') || {};

        return {
            textFilter: filterData.textFilter || '',
            bool: Espo.Utils.cloneDeep(filterData.bool || {}),
            advanced: this.sanitizeAdvancedFilterData(filterData.advanced || {}),
            primary: filterData.primary || null,
            presetName: null,
        };
    }

    sanitizeAdvancedFilterData(advanced) {
        const result = {};

        if (!advanced || typeof advanced !== 'object' || Array.isArray(advanced)) {
            return result;
        }

        Object.keys(advanced).forEach(field => {
            const defs = advanced[field];

            if (!defs || typeof defs !== 'object' || Array.isArray(defs) || defs.where) {
                return;
            }

            result[field] = Espo.Utils.cloneDeep(defs);
        });

        return result;
    }

    fetchFilterData() {
        const view = this.getView('filterBuilder');

        if (!view) {
            return null;
        }

        view.fetch();
        view.updateSearch();

        const data = this.filterSearchManager.get();

        return {
            textFilter: data.textFilter || '',
            bool: this.getActiveBoolFilterData(data.bool || {}),
            advanced: this.sanitizeAdvancedFilterData(data.advanced || {}),
            primary: data.primary || null,
            presetName: null,
        };
    }

    getActiveBoolFilterData(bool) {
        const result = {};

        Object.keys(bool || {}).forEach(name => {
            if (bool[name]) {
                result[name] = true;
            }
        });

        return result;
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
        const filterData = this.fetchFilterData();

        data.filterData = filterData;
        data.filterName = filterData ? filterData.primary : null;

        this.trigger('apply', data);
    }
}
