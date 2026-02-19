/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import BaseFieldView from 'views/fields/base';
import $ from 'jquery';

class NavbarConfigListFieldView extends BaseFieldView {

    listTemplateContent = `
        {{#if isEditMode}}
        <div class="navbar-config-list-container">
            <div class="list-group drag-list navbar-config-items"></div>
            <div class="button-container" style="margin-top: var(--8px)">
                <button
                    class="btn btn-default btn-sm"
                    data-action="addConfig"
                    type="button"
                ><span class="fas fa-plus fa-sm"></span> {{translate 'Add Navbar Configuration' scope='Settings'}}</button>
            </div>
        </div>
        {{else}}
        <div class="navbar-config-list-detail">
            {{#each configListForDisplay}}
            <div class="multi-enum-item-container">
                {{#if iconClass}}<span class="{{iconClass}} text-muted"></span> {{/if}}
                {{name}}
                {{#if isDefault}} <span class="text-soft text-italic">({{translate 'defaultConfig' category='navbarConfig' scope='Global'}})</span>{{/if}}
            </div>
            {{/each}}
            {{#unless configListForDisplay.length}}
            <span class="text-soft">{{translate 'None'}}</span>
            {{/unless}}
        </div>
        {{/if}}
    `

    events = {
        'click [data-action="addConfig"]': function () {
            this.actionAddConfig();
        },
        'click [data-action="editConfig"]': function (e) {
            const id = $(e.currentTarget).data('id');
            this.actionEditConfig(id);
        },
        'click [data-action="removeConfig"]': function (e) {
            const id = $(e.currentTarget).data('id');
            this.actionRemoveConfig(id);
        },
    }

    data() {
        const configList = this.getConfigList();

        return {
            ...super.data(),
            isEditMode: this.isEditMode(),
            configListForDisplay: configList.map(item => ({
                ...item,
                name: item.name || '?',
            })),
        };
    }

    setup() {
        super.setup();
        this.templateContent = this.listTemplateContent;
    }

    afterRender() {
        super.afterRender();

        if (this.isEditMode()) {
            this.renderConfigItems();
            this.initSortable();
        }
    }

    isEditMode() {
        return this.mode === 'edit';
    }

    getConfigList() {
        return Espo.Utils.cloneDeep(this.model.get(this.name)) || [];
    }

    setConfigList(list) {
        this.model.set(this.name, list);
    }

    generateConfigId() {
        return 'navbar-config-' + Math.floor(Math.random() * 1000000 + 1).toString();
    }

    renderConfigItems() {
        const $list = this.$el.find('.navbar-config-items');
        $list.empty();

        const configList = this.getConfigList();

        configList.forEach(item => {
            const html = this.getConfigItemHtml(item);
            $list.append(html);
        });
    }

    getConfigItemHtml(item) {
        const div = document.createElement('div');
        div.className = 'list-group-item';
        div.dataset.id = item.id;
        div.style.cursor = 'default';

        const dragHandle = document.createElement('span');
        dragHandle.className = 'drag-handle';
        const gripIcon = document.createElement('span');
        gripIcon.className = 'fas fa-grip fa-sm';
        dragHandle.append(gripIcon);

        const editBtn = document.createElement('span');
        editBtn.className = 'item-button';
        const editLink = document.createElement('a');
        editLink.role = 'button';
        editLink.tabIndex = 0;
        editLink.dataset.id = item.id;
        editLink.dataset.action = 'editConfig';
        const editIcon = document.createElement('span');
        editIcon.className = 'fas fa-pencil-alt fa-sm';
        editLink.append(editIcon);
        editBtn.append(editLink);

        const textSpan = document.createElement('span');
        textSpan.className = 'text';

        if (item.iconClass) {
            const icon = document.createElement('span');
            icon.className = item.iconClass + ' text-muted';
            icon.style.marginRight = 'var(--4px)';
            textSpan.append(icon);
        }

        if (item.color) {
            const colorDot = document.createElement('span');
            colorDot.className = 'fas fa-circle fa-sm';
            colorDot.style.color = item.color;
            colorDot.style.marginRight = 'var(--4px)';
            textSpan.append(colorDot);
        }

        const nameLabel = document.createElement('span');
        nameLabel.textContent = item.name || '?';
        textSpan.append(nameLabel);

        if (item.isDefault) {
            const defaultBadge = document.createElement('span');
            defaultBadge.className = 'text-soft text-italic';
            defaultBadge.style.marginLeft = 'var(--4px)';
            defaultBadge.textContent = '(default)';
            textSpan.append(defaultBadge);
        }

        const tabCount = document.createElement('span');
        tabCount.className = 'text-soft';
        tabCount.style.marginLeft = 'var(--4px)';
        const tabListLen = (item.tabList || []).length;
        tabCount.textContent = `[${tabListLen} tab${tabListLen !== 1 ? 's' : ''}]`;
        textSpan.append(tabCount);

        const removeLink = document.createElement('a');
        removeLink.role = 'button';
        removeLink.tabIndex = 0;
        removeLink.classList.add('pull-right');
        removeLink.dataset.id = item.id;
        removeLink.dataset.action = 'removeConfig';
        const removeIcon = document.createElement('span');
        removeIcon.className = 'fas fa-times';
        removeLink.append(removeIcon);

        div.append(dragHandle, editBtn, textSpan, removeLink);

        return div.outerHTML;
    }

    initSortable() {
        const $list = this.$el.find('.navbar-config-items');

        $list.sortable({
            handle: '.drag-handle',
            stop: () => {
                this.fetchFromDom();
                this.trigger('change');
            },
        });
    }

    fetchFromDom() {
        const configList = this.getConfigList();
        const ordered = [];

        this.$el.find('.navbar-config-items .list-group-item').each((i, el) => {
            const id = $(el).data('id').toString();
            const found = configList.find(c => c.id === id);

            if (found) {
                ordered.push(found);
            }
        });

        this.setConfigList(ordered);
    }

    fetch() {
        this.fetchFromDom();

        const data = {};
        data[this.name] = this.getConfigList();

        return data;
    }

    actionAddConfig() {
        const newConfig = {
            id: this.generateConfigId(),
            name: '',
            iconClass: null,
            color: null,
            tabList: [],
            isDefault: false,
        };

        this.createView('editModal', 'global:views/settings/modals/edit-navbar-config', {
            itemData: newConfig,
            isNew: true,
        }, view => {
            view.render();

            this.listenToOnce(view, 'apply', data => {
                const configList = this.getConfigList();

                data.id = data.id || newConfig.id;
                configList.push(data);

                if (configList.length === 1) {
                    data.isDefault = true;
                }

                this.setConfigList(configList);
                view.close();

                this.reRender();
                this.trigger('change');
            });
        });
    }

    actionEditConfig(id) {
        const configList = this.getConfigList();
        const item = configList.find(c => c.id === id);

        if (!item) return;

        const index = configList.indexOf(item);
        const itemData = Espo.Utils.cloneDeep(item);

        this.createView('editModal', 'global:views/settings/modals/edit-navbar-config', {
            itemData: itemData,
            isNew: false,
        }, view => {
            view.render();

            this.listenToOnce(view, 'apply', data => {
                for (const key in data) {
                    configList[index][key] = data[key];
                }

                this.setConfigList(configList);
                view.close();

                this.reRender();
                this.trigger('change');
            });
        });
    }

    actionRemoveConfig(id) {
        const configList = this.getConfigList().filter(c => c.id !== id);

        this.setConfigList(configList);
        this.$el.find(`.list-group-item[data-id="${id}"]`).remove();
        this.trigger('change');
    }

    getValueForDisplay() {
        const configList = this.getConfigList();

        if (!configList.length) {
            return this.translate('None');
        }

        return configList.map(item => {
            return $('<div>')
                .addClass('multi-enum-item-container')
                .text(item.name || '?')
                .get(0)
                .outerHTML;
        }).join('');
    }
}

export default NavbarConfigListFieldView;
