/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import View from 'view';
import RecordModal from 'helpers/record-modal';

export default class VirtualFolderView extends View {

    template = 'global:site/navbar/virtual-folder'

    virtualFolderId = null
    entityType = null
    filterName = null
    maxItems = 5
    label = null
    iconClass = null
    color = null
    orderBy = null
    order = 'desc'
    openMode = 'view'
    relationshipLink = null
    isCollapsed = false
    isLoading = false
    hasError = false
    errorMessage = ''
    recordList = []
    totalCount = 0
    hasMore = false

    data() {
        return {
            id: this.virtualFolderId,
            entityType: this.entityType,
            label: this.getLabel(),
            iconClass: this.getIconClass(),
            color: this.color,
            isCollapsed: this.isCollapsed,
            isLoading: this.isLoading,
            hasError: this.hasError,
            errorMessage: this.errorMessage,
            recordList: this.recordList.map(record => ({
                id: record.id,
                name: record.name,
                url: this.getRecordUrl(record.id),
            })),
            totalCount: this.totalCount,
            hasMore: this.hasMore,
        };
    }

    setup() {
        const config = this.options.config || {};

        this.virtualFolderId = this.options.virtualFolderId || config.id ||
            ('vf-' + Math.random().toString(36).substr(2, 9));
        this.entityType = config.entityType;
        this.filterName = config.filterName || null;
        this.maxItems = config.maxItems || 5;
        this.label = config.label || null;
        this.iconClass = config.iconClass || null;
        this.color = config.color || null;
        this.orderBy = config.orderBy || null;
        this.order = config.order || 'desc';
        this.openMode = config.openMode || 'view';
        this.relationshipLink = config.relationshipLink || null;

        this.isCollapsed = this.getCollapseState();

        this.addActionHandler('toggleVirtualFolder', () => {
            this.toggleCollapse();
        });

        this.addActionHandler('quickCreate', () => {
            this.actionQuickCreate();
        });

        this.addActionHandler('refresh', () => {
            this.actionRefresh();
        });

        this.addActionHandler('viewAll', () => {
            this.actionViewAll();
        });
    }

    afterRender() {
        if (this.element) {
            this.element.classList.add('virtual-folder');
            this.element.classList.remove('tab');
            this.element.classList.toggle('collapsed', this.isCollapsed);
        }
    }

    isSystemFilter(filterName) {
        if (!filterName || !this.entityType) {
            return false;
        }

        const systemFilters = this.getMetadata()
            .get(['clientDefs', this.entityType, 'filterList']) || [];

        return systemFilters.some(item => {
            if (typeof item === 'string') {
                return item === filterName;
            }
            return item.name === filterName;
        });
    }

    getUserFilterData(filterName) {
        if (!filterName || !this.entityType) {
            return null;
        }

        const userFilters =
            (this.getPreferences().get('presetFilters') || {})[this.entityType] || [];

        return userFilters.find(item => item.name === filterName) || null;
    }

    applyUserFilter(collection, userFilter) {
        if (!userFilter) {
            return;
        }

        if (userFilter.primary) {
            collection.data = collection.data || {};
            collection.data.primaryFilter = userFilter.primary;
        }

        if (!userFilter.data || typeof userFilter.data !== 'object') {
            return;
        }

        collection.where = collection.where || [];

        for (const field in userFilter.data) {
            const defs = userFilter.data[field];

            if (defs === null || defs === undefined) {
                continue;
            }

            if (typeof defs === 'object' && !Array.isArray(defs) && defs.type) {
                collection.where.push({
                    type: defs.type,
                    attribute: defs.attribute || field,
                    value: defs.value,
                });

                continue;
            }

            if (Array.isArray(defs)) {
                collection.where.push({
                    type: 'in',
                    attribute: field,
                    value: defs,
                });

                continue;
            }

            if (defs !== '') {
                collection.where.push({
                    type: 'equals',
                    attribute: field,
                    value: defs,
                });
            }
        }
    }

    getLabel() {
        if (this.label) {
            return this.label;
        }

        if (this.entityType) {
            return this.translate(this.entityType, 'scopeNamesPlural');
        }

        return 'Virtual Folder';
    }

    getIconClass() {
        if (this.iconClass) {
            return this.iconClass;
        }

        if (this.entityType) {
            return this.getMetadata()
                .get(['clientDefs', this.entityType, 'iconClass']) || 'fas fa-folder';
        }

        return 'fas fa-folder';
    }

    getCollapseState(id) {
        const virtualFolderId = id || this.virtualFolderId;
        const key = `navbar-vf-${virtualFolderId}-collapsed`;

        return localStorage.getItem(key) === 'true';
    }

    setCollapseState(collapsed) {
        const key = `navbar-vf-${this.virtualFolderId}-collapsed`;

        localStorage.setItem(key, collapsed ? 'true' : 'false');
    }

    async fetchRecords() {
        if (!this.entityType) {
            return;
        }

        this.isLoading = true;
        this.hasError = false;

        if (this.isRendered()) {
            this.reRender();
        }

        try {
            const collection = await this.getCollectionFactory().create(this.entityType);

            collection.maxSize = this.maxItems > 0 ? this.maxItems : 50;

            if (this.filterName) {
                if (this.isSystemFilter(this.filterName)) {
                    collection.data = collection.data || {};
                    collection.data.primaryFilter = this.filterName;
                } else {
                    const userFilter = this.getUserFilterData(this.filterName);
                    this.applyUserFilter(collection, userFilter);
                }
            }

            if (this.orderBy) {
                collection.setOrder(this.orderBy, this.order || 'desc');
            }

            await collection.fetch();

            this.recordList = collection.models.map(model => ({
                id: model.id,
                name: model.get('name') || model.id,
            }));

            this.totalCount = collection.total || this.recordList.length;
            this.hasMore = this.maxItems > 0 && this.totalCount > this.maxItems;

        } catch (error) {
            this.hasError = true;
            this.errorMessage = this.translate('Failed to load', 'labels', 'Global');
            console.error('Virtual folder fetch error:', error);
        } finally {
            this.isLoading = false;
        }

        if (this.isRendered()) {
            this.reRender();
        }
    }

    toggleCollapse() {
        this.isCollapsed = !this.isCollapsed;
        this.setCollapseState(this.isCollapsed);
        this.reRender();
    }

    async actionQuickCreate() {
        const helper = new RecordModal();

        const modal = await helper.showCreate(this, {
            entityType: this.entityType,
        });

        this.listenToOnce(modal, 'after:save', () => {
            this.fetchRecords();
        });
    }

    actionRefresh() {
        this.fetchRecords();
    }

    actionViewAll() {
        let url = `#${this.entityType}`;

        if (this.filterName) {
            if (this.isSystemFilter(this.filterName)) {
                url += `/list?primaryFilter=${this.filterName}`;
            } else {
                url += `/list?presetFilter=${this.filterName}`;
            }
        } else {
            url += '/list';
        }

        this.getRouter().navigate(url, {trigger: true});
    }

    getRecordUrl(recordId) {
        if (this.openMode === 'relationship' && this.relationshipLink) {
            const linkDefs = this.getMetadata().get(['entityDefs', this.entityType, 'links']) || {};
            const linkDef = linkDefs[this.relationshipLink];

            if (!linkDef || linkDef.disabled || linkDef.utility || linkDef.layoutRelationshipsDisabled) {
                Espo.Ui.warning(this.translate('relationshipLinkInvalid', 'messages', 'Global'));
                console.warn(`Relationship link '${this.relationshipLink}' not found or invalid for entity '${this.entityType}'`);
                return `#${this.entityType}/view/${recordId}`;
            }

            return `#${this.entityType}/related/${recordId}/${this.relationshipLink}`;
        }

        return `#${this.entityType}/view/${recordId}`;
    }
}
