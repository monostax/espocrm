/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import TabListFieldView from 'views/settings/fields/tab-list';

export default class CustomTabListFieldView extends TabListFieldView {

    addItemModalView = 'global:views/settings/modals/tab-list-field-add'

    getGroupItemHtml(item) {
        if (item.type === 'virtualFolder') {
            return this.getVirtualFolderItemHtml(item);
        }

        return super.getGroupItemHtml(item);
    }

    getVirtualFolderItemHtml(item) {
        const labelElement = document.createElement('span');
        labelElement.textContent = item.label || item.entityType || 'Virtual Folder';

        const icon = document.createElement('span');
        icon.className = 'fas fa-folder text-muted';
        icon.style.marginRight = 'var(--4px)';

        const itemElement = document.createElement('span');
        itemElement.className = 'text';
        itemElement.append(icon, labelElement);

        const div = document.createElement('div');
        div.className = 'list-group-item';
        div.dataset.value = item.id;
        div.style.cursor = 'default';

        div.append(
            (() => {
                const span = document.createElement('span');
                span.className = 'drag-handle';
                span.append(
                    (() => {
                        const span = document.createElement('span');
                        span.className = 'fas fa-grip fa-sm';

                        return span;
                    })(),
                );

                return span;
            })(),
            (() => {
                const span = document.createElement('span');
                span.className = 'item-button'
                span.append(
                    (() => {
                        const a = document.createElement('a');
                        a.role = 'button';
                        a.tabIndex = 0;
                        a.dataset.value = item.id;
                        a.dataset.action = 'editGroup';
                        a.append(
                            (() => {
                                const span = document.createElement('span');
                                span.className = 'fas fa-pencil-alt fa-sm';

                                return span;
                            })(),
                        );

                        return a;
                    })(),
                )

                return span;
            })(),
            itemElement,
            (() => {
                const a = document.createElement('a');
                a.role = 'button';
                a.tabIndex = 0;
                a.classList.add('pull-right');
                a.dataset.value = item.id;
                a.dataset.action = 'removeValue';
                a.append(
                    (() => {
                        const span = document.createElement('span');
                        span.className = 'fas fa-times'

                        return span;
                    })(),
                );

                return a;
            })(),
        );

        return div.outerHTML;
    }

    editGroup(id) {
        const item = Espo.Utils.cloneDeep(this.getGroupValueById(id) || {});

        const index = this.getGroupIndexById(id);
        const tabList = Espo.Utils.cloneDeep(this.selected);

        const view = {
            divider: 'views/settings/modals/edit-tab-divider',
            url: 'views/settings/modals/edit-tab-url',
            virtualFolder: 'global:views/settings/modals/edit-tab-virtual-folder',
        }[item.type] || 'views/settings/modals/edit-tab-group';

        this.createView('dialog', view, {
            itemData: item,
            parentType: this.model.entityType,
        }, view => {
            view.render();

            this.listenToOnce(view, 'apply', itemData => {
                for (const a in itemData) {
                    tabList[index][a] = itemData[a];
                }

                this.model.set(this.name, tabList);

                view.close();
            });
        });
    }
}