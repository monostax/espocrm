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
import SearchManager from 'search-manager';

export default class VirtualFolderView extends View {

    template = 'global:site/navbar/virtual-folder'

    virtualFolderId = null
    entityType = null
    filterName = null
    filterData = null
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
        const iconClass = this.getIconClass();

        return {
            id: this.virtualFolderId,
            entityType: this.entityType,
            label: this.getLabel(),
            iconClass: iconClass,
            color: this.color,
            isCollapsed: this.isCollapsed,
            isLoading: this.isLoading,
            hasError: this.hasError,
            errorMessage: this.errorMessage,
            recordList: this.recordList.map(record => ({
                id: record.id,
                name: record.name,
                url: this.getRecordUrl(record.id),
                iconClass: iconClass,
                color: this.color,
                isActive: this.isRecordActive(record.id),
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
        this.filterData = config.filterData || null;
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

        this.listenTo(this.getRouter(), 'routed', () => {
            if (this.isBeingDisposed || !this.isRendered()) {
                return;
            }

            this.updateActiveState();
        });
    }

    afterRender() {
        if (this.element) {
            this.element.classList.add('virtual-folder');
            this.element.classList.remove('tab');
            this.element.classList.toggle('collapsed', this.isCollapsed);
        }

        this.updateActiveState();
    }

    updateActiveState() {
        if (!this.element || this.isBeingDisposed) {
            return;
        }

        const currentUrl = this.normalizeUrl(this.getRouter().getCurrentUrl());

        this.element.querySelectorAll('.virtual-folder-item').forEach(item => {
            const link = item.querySelector('a[href]');
            const href = link ? link.getAttribute('href') : null;

            item.classList.toggle('active', !!href && currentUrl === this.normalizeUrl(href));
        });
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

    applyInlineFilterData(collection, filterData) {
        const sanitizedFilterData = this.sanitizeFilterData(filterData);

        if (!sanitizedFilterData) {
            throw new Error('Invalid virtual folder filter data.');
        }

        const searchManager = new SearchManager(collection, {
            defaultData: sanitizedFilterData,
            emptyOnReset: true,
        });

        searchManager.scope = this.entityType;
        collection.where = searchManager.getWhere();
    }

    sanitizeFilterData(filterData) {
        if (!filterData || typeof filterData !== 'object' || Array.isArray(filterData)) {
            return null;
        }

        const bool = this.sanitizeBoolFilterData(filterData.bool);
        const advanced = this.sanitizeAdvancedFilterData(filterData.advanced);

        if (bool === null || advanced === null) {
            return null;
        }

        if (filterData.primary && !this.isSystemFilter(filterData.primary)) {
            return null;
        }

        return {
            textFilter: typeof filterData.textFilter === 'string' ? filterData.textFilter : '',
            bool: bool,
            advanced: advanced,
            primary: filterData.primary || null,
        };
    }

    sanitizeBoolFilterData(bool) {
        if (!bool || typeof bool !== 'object' || Array.isArray(bool)) {
            return {};
        }

        const allowedList = this.getMetadata().get(['clientDefs', this.entityType, 'boolFilterList']) || [];
        const allowedMap = {};

        allowedList.forEach(item => {
            const name = typeof item === 'string' ? item : item && item.name;

            if (name) {
                allowedMap[name] = true;
            }
        });

        if (this.getMetadata().get(['scopes', this.entityType, 'stream'])) {
            allowedMap.followed = true;
        }

        if (this.getMetadata().get(['scopes', this.entityType, 'collaborators'])) {
            allowedMap.shared = true;
        }

        const result = {};

        Object.keys(bool).forEach(name => {
            if (!bool[name]) {
                return;
            }

            if (!allowedMap[name]) {
                result.__invalid = true;
                return;
            }

            result[name] = true;
        });

        if (result.__invalid) {
            return null;
        }

        return result;
    }

    sanitizeAdvancedFilterData(advanced) {
        if (!advanced || typeof advanced !== 'object' || Array.isArray(advanced)) {
            return {};
        }

        const forbiddenFieldList = this.getAcl().getScopeForbiddenFieldList(this.entityType) || [];
        const result = {};

        Object.keys(advanced).forEach(field => {
            const defs = advanced[field];

            if (!this.getMetadata().get(['entityDefs', this.entityType, 'fields', field])) {
                result.__invalid = true;
                return;
            }

            if (forbiddenFieldList.includes(field)) {
                result.__invalid = true;
                return;
            }

            if (!this.isSafeAdvancedFilterDefs(defs)) {
                result.__invalid = true;
                return;
            }

            result[field] = Espo.Utils.cloneDeep(defs);
        });

        if (result.__invalid) {
            return null;
        }

        return result;
    }

    isSafeAdvancedFilterDefs(defs) {
        if (!defs || typeof defs !== 'object' || Array.isArray(defs)) {
            return false;
        }

        if (defs.where) {
            return false;
        }

        if (defs.attribute && typeof defs.attribute !== 'string') {
            return false;
        }

        if (defs.field && typeof defs.field !== 'string') {
            return false;
        }

        if ((defs.type === 'or' || defs.type === 'and') && defs.value) {
            if (typeof defs.value !== 'object' || Array.isArray(defs.value)) {
                return false;
            }

            return Object.keys(defs.value).every(key => this.isSafeAdvancedFilterDefs(defs.value[key]));
        }

        return typeof defs.type === 'string';
    }

    getLabel() {
        if (this.label) {
            if (this.label.startsWith('$')) {
                return this.translate(this.label.substring(1), 'navbarTabs', 'Global');
            }

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

            if (this.filterData) {
                this.applyInlineFilterData(collection, this.filterData);
            } else if (this.filterName) {
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

    isRecordActive(recordId) {
        return this.normalizeUrl(this.getRecordUrl(recordId)) ===
            this.normalizeUrl(this.getRouter().getCurrentUrl());
    }

    normalizeUrl(url) {
        if (!url || typeof url !== 'string') {
            return '';
        }

        return url.replace(/^#/, '').replace(/^\//, '');
    }
}
