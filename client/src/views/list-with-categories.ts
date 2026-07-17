/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

import ListView from 'views/list';
import TreeCollection from 'collections/tree';
import type ListTreeRecordView from 'views/record/list-tree';
import type ListNestedCategoriesRecordView from 'views/record/list-nested-categories';
import Model from 'model';
import Ui from 'ui';

class ListWithCategories extends ListView {

    protected template = 'list-with-categories'

    protected quickCreate: boolean = true

    readonly storeViewAfterCreate: boolean = true

    readonly storeViewAfterUpdate: boolean = true

    protected currentCategoryId: string | null = null

    protected currentCategoryName: string | null = null

    protected categoryScope: string

    protected categoryField = 'category'

    protected categoryFilterType = 'inCategory'

    protected isExpanded: boolean = false

    protected hasExpandedToggler: boolean = true

    protected expandedTogglerDisabled: boolean = false

    protected keepCurrentRootUrl: boolean = true

    protected hasNavigationPanel: boolean = false

    private nestedCollectionIsBeingFetched = false

    private nestedCategoriesCollection: TreeCollection

    protected isCategoryMultiple: boolean

    private defaultMaxSize: number

    private showEditLink: boolean

    protected categoriesDisabled: boolean

    private $nestedCategoriesContainer: JQuery
    private $listContainer: JQuery

    protected data() {
        const data = {} as Record<string, any>;

        data.hasTree = (this.isExpanded || this.hasNavigationPanel) && !this.categoriesDisabled;
        data.hasNestedCategories = !this.categoriesDisabled;
        data.fallback = !data.hasTree && !data.hasNestedCategories;

        return data;
    }

    protected setup() {
        super.setup();

        this.addActionHandler('toggleExpandedFromNavigation', () => this.actionToggleExpandedFromNavigation());
        this.addActionHandler('manageCategories', () => this.actionManageCategories());

        this.defaultMaxSize = this.collection.maxSize;

        if (!this.categoryScope) {
            this.categoryScope = `${this.scope}Category`;
        }

        this.categoryField = this.getMetadata().get(`scopes.${this.categoryScope}.categoryField`) || this.categoryField;

        this.isCategoryMultiple = this.getMetadata()
            .get(`entityDefs.${this.scope}.fields.${this.categoryField}.type`) === 'linkMultiple';

        this.showEditLink = !!(
            this.getAcl().check(this.categoryScope, 'edit') ||
            this.getAcl().check(this.categoryScope, 'create')
        );

        const isExpandedByDefault = this.getMetadata()
            .get(['clientDefs', this.categoryScope, 'isExpandedByDefault']) || false;

        if (isExpandedByDefault) {
            this.isExpanded = true;
        }

        const isCollapsedByDefault = this.getMetadata()
            .get(['clientDefs', this.categoryScope, 'isCollapsedByDefault']) || false;

        if (isCollapsedByDefault) {
            this.isExpanded = false;
        }

        this.categoriesDisabled =
            this.categoriesDisabled ||
            this.getMetadata().get(['scopes', this.categoryScope, 'disabled']) ||
            !this.getAcl().checkScope(this.categoryScope, 'read');

        if (this.categoriesDisabled) {
            this.isExpanded = true;
            this.hasExpandedToggler = false;
            this.hasNavigationPanel = false;
        } else if (!this.expandedTogglerDisabled) {
            if (!this.getUser().isPortal() && this.hasIsExpandedStoredValue()) {
                this.isExpanded = this.getIsExpandedStoredValue();
            }

            if (this.getUser().isPortal()) {
                this.hasExpandedToggler = false;
                this.isExpanded = false;
            }
        }

        if (this.hasNavigationPanelStoredValue()) {
            this.hasNavigationPanel = this.getNavigationPanelStoredValue();
        } else {
            this.hasNavigationPanel =
                this.getMetadata().get(`scopes.${this.categoryScope}.showNavigationPanel`) ||
                this.hasNavigationPanel;
        }

        const params = this.options.params || {};

        if ('categoryId' in params) {
            this.currentCategoryId = params.categoryId;
        }

        this.applyCategoryToCollection();

        this.listenTo(this.collection, 'sync', (_c, _d, o) => {
            if (o && o.openCategory) {
                return;
            }

            this.controlListVisibility();
        });
    }

    protected prepareCreateReturnDispatchParams(
        params: {
            controller?: string;
            action?: string| null;
            options?: Record<string, any> & {
                isReturn?: boolean;
                categoryId?: string,
                categoryName?: string | null,
            },
        }
    ) {
        if (this.currentCategoryId) {
            params.options ??= {};

            params.options.categoryId = this.currentCategoryId;
            params.options.categoryName = this.currentCategoryName;
        }
    }

    setupReuse(params: Record<string, unknown>) {
        super.setupReuse(params);

        this.applyRoutingParams(params);
    }

    private applyRoutingParams(params: Record<string, unknown>) {
        if ('categoryId' in params) {
            if (params.categoryId !== this.currentCategoryId) {
                this.openCategory(params.categoryId as string | null, params.categoryName as string | null);
            }
        }

        this.selectCurrentCategory();
    }

    private hasTextFilter(): boolean {
        return !!(
            this.collection.data.textFilter ||
            this.collection.where?.find(it => it.type === 'textFilter')
        );
    }

    private hasNavigationPanelStoredValue(): boolean {
        return this.getStorage().has('state', `categories-navigation-panel-${this.scope}`);
    }

    private getNavigationPanelStoredValue(): boolean {
        const value = this.getStorage().get('state', `categories-navigation-panel-${this.scope}`);

        return value === 'true' || value === true;
    }

    private setNavigationPanelStoredValue(value: boolean) {
        return this.getStorage().set('state', `categories-navigation-panel-${this.scope}`, value);
    }

    private hasIsExpandedStoredValue(): boolean {
        return this.getStorage().has('state', `categories-expanded-${this.scope}`);
    }

    private getIsExpandedStoredValue(): boolean {
        const value = this.getStorage().get('state', `categories-expanded-${this.scope}`);

        return value === 'true' || value === true ;
    }

    private setIsExpandedStoredValue(value: boolean) {
        return this.getStorage().set('state', `categories-expanded-${this.scope}`, value);
    }

    protected afterRender() {
        this.$nestedCategoriesContainer = this.$el.find('.nested-categories-container');
        this.$listContainer = this.$el.find('.list-container');

        if (!this.hasView('list')) {
            if (!this.isExpanded) {
                this.hideListContainer();
            }

            this.loadList();
        } else {
            this.controlListVisibility();
        }

        if (
            !this.categoriesDisabled &&
            (this.isExpanded || this.hasNavigationPanel) &&
            !this.hasView('categories')
        ) {
            this.loadCategories();
        }

        if (!this.hasView('nestedCategories') && !this.categoriesDisabled) {
            this.loadNestedCategories();
        }

        this.$el.focus();
    }

    private clearCategoryViews() {
        this.clearNestedCategoriesView();
        this.clearCategoriesView();
    }

    private clearCategoriesView() {
        this.clearView('categories');
    }

    private clearNestedCategoriesView() {
        this.clearView('nestedCategories');
    }

    private emptyListContainer() {
        this.$listContainer.empty();
    }

    // noinspection JSUnusedGlobalSymbols
    /**
     * @private
     */
    private async actionExpand() {
        this.isExpanded = true;
        this.setIsExpandedStoredValue(true);
        this.applyCategoryToCollection();
        this.clearNestedCategoriesView();

        if (this.getCategoriesView()) {
            this.getCategoriesView().isExpanded = true;
            this.getCategoriesView().expandToggleInactive = true;
        }

        this.reRender().then(() => {});

        this.emptyListContainer();

        await this.collection.fetch();

        if (this.getCategoriesView()) {
            this.getCategoriesView().expandToggleInactive = false;
            await this.getCategoriesView().reRender();
        }
    }

    // noinspection JSUnusedGlobalSymbols
    /**
     * @private
     */
    async actionCollapse() {
        this.isExpanded = false;
        this.setIsExpandedStoredValue(false);
        this.applyCategoryToCollection();
        this.applyCategoryToNestedCategoriesCollection();
        this.clearNestedCategoriesView();

        if (this.getCategoriesView()) {
            this.getCategoriesView().isExpanded = false;
            this.getCategoriesView().expandToggleInactive = true;
        }

        this.reRender().then(() => {});

        this.emptyListContainer();

        await this.collection.fetch();

        if (this.getCategoriesView()) {
            this.getCategoriesView().expandToggleInactive = false;
            await this.getCategoriesView().reRender();
        }
    }

    // noinspection JSUnusedGlobalSymbols
    protected actionOpenCategory(data: {id?: string, name?: string}) {
        this.openCategory(data.id || null, data.name);

        this.selectCurrentCategory();
        this.navigateToCurrentCategory();
    }

    private navigateToCurrentCategory() {
        let url = `#${this.scope}`;

        if (this.currentCategoryId) {
            url += `/list/categoryId=${this.currentCategoryId}`;

            if (this._primaryFilter) {
                url += `&primaryFilter=${this.getHelper().escapeString(this._primaryFilter)}`;
            }
        } else {
            if (this._primaryFilter) {
                url += `/list/primaryFilter=${this.getHelper().escapeString(this._primaryFilter)}`;
            }
        }

        this.getRouter().navigate(url);
        this.updateLastUrl();
    }

    private selectCurrentCategory() {
        const categoriesView = this.getCategoriesView();

        if (categoriesView) {
            categoriesView.setSelected(this.currentCategoryId);
            categoriesView.reRender();
        }
    }

    private openCategory(id: string | null, name: string | null = null) {
        this.getNestedCategoriesView().isLoading = true;
        this.getNestedCategoriesView().reRender();
        this.getNestedCategoriesView().isLoading = false;

        this.nestedCategoriesCollection.reset();
        this.collection.reset();
        this.collection.offset = 0;
        this.collection.maxSize = this.defaultMaxSize;

        this.emptyListContainer();

        this.currentCategoryId = id;
        this.currentCategoryName = name || id;

        this.applyCategoryToNestedCategoriesCollection();
        this.applyCategoryToCollection();

        this.collection.abortLastFetch();

        if (this.nestedCategoriesCollection) {
            this.nestedCategoriesCollection.abortLastFetch();

            this.hideListContainer();
            this.$nestedCategoriesContainer.addClass('hidden');

            Espo.Ui.notifyWait();

            const promises = [
                this.nestedCategoriesCollection.fetch().then(() => this.updateHeader()),
                this.collection.fetch({openCategory: true})
            ];

            Promise.all(promises)
                .then(() => {
                    Espo.Ui.notify(false);

                    this.controlNestedCategoriesVisibility();
                    this.controlListVisibility();
                });

            return;
        }

        this.collection.fetch()
            .then(() => {
                Espo.Ui.notify(false);
            });
    }

    /**
     * @private
     */
    controlListVisibility() {
        if (this.isExpanded) {
            this.showListContainer();

            return;
        }

        if (this.nestedCollectionIsBeingFetched) {
            return;
        }

        if (
            !this.collection.models.length &&
            this.nestedCategoriesCollection &&
            this.nestedCategoriesCollection.models.length &&
            !this.hasTextFilter()
        ) {
            this.hideListContainer();

            return;
        }

        this.showListContainer();
    }

    private controlNestedCategoriesVisibility() {
        this.$nestedCategoriesContainer.removeClass('hidden');
    }

    private async getTreeCollection(): Promise<TreeCollection> {
        const collection = await this.getCollectionFactory().create(this.categoryScope);

        if (!(collection instanceof TreeCollection)) {
            throw new Error(`Non-tree collection.`);
        }

        collection.url = `${collection.entityType}/action/listTree`;
        collection.setOrder(null, null);

        // @todo Revise. To remove?
        //this.collection.treeCollection = collection;

        await collection.fetch();

        return collection;
    }

    applyCategoryToNestedCategoriesCollection() {
        if (!this.nestedCategoriesCollection) {
            return;
        }

        this.nestedCategoriesCollection.parentId = this.currentCategoryId;
        this.nestedCategoriesCollection.currentCategoryId = this.currentCategoryId;
        this.nestedCategoriesCollection.currentCategoryName = this.currentCategoryName || this.currentCategoryId;
        this.nestedCategoriesCollection.where = [];
    }

    private getNestedCategoriesCollection(callback: (collection: TreeCollection) => void)  {
        this.getCollectionFactory().create(this.categoryScope).then(async collection => {
            if (!(collection instanceof TreeCollection)) {
                throw new Error(`Non-tree collection.`);
            }

            this.nestedCategoriesCollection = collection;

            collection.setOrder(null, null);
            collection.url = `${collection.entityType}/action/listTree`;
            collection.data.checkIfEmpty = true;

            if (!this.getAcl().checkScope(this.scope, 'create')) {
                collection.data.onlyNotEmpty = true;
            }

            this.applyCategoryToNestedCategoriesCollection();

            this.nestedCollectionIsBeingFetched = true;

            // Needed even in expanded mode to display the header path.
            await collection.fetch();

            this.nestedCollectionIsBeingFetched = false;

            this.controlNestedCategoriesVisibility();
            this.controlListVisibility();

            this.updateHeader();

            callback.call(this, collection);
        });
    }

    protected getNestedCategoriesView() {
        return this.getView('nestedCategories') as ListNestedCategoriesRecordView;
    }

    protected getCategoriesView(): ListTreeRecordView {
        return this.getView('categories') as ListTreeRecordView;
    }

    private loadNestedCategories() {
        this.getNestedCategoriesCollection(collection => {
            this.createView('nestedCategories', 'views/record/list-nested-categories', {
                collection: collection,
                itemCollection: this.collection,
                selector: '.nested-categories-container',
                showEditLink: this.showEditLink,
                isExpanded: this.isExpanded,
                hasExpandedToggler: this.hasExpandedToggler,
                hasNavigationPanel: this.hasNavigationPanel,
                subjectEntityType: this.collection.entityType,
                primaryFilter: this._primaryFilter,
            }).then(view => {
                view.render();
            });
        });
    }
    private async loadCategories() {
        const collection = await this.getTreeCollection();

        const view = await this.createView<ListTreeRecordView>('categories', 'views/record/list-tree', {
            collection: collection,
            selector: '.categories-container',
            selectable: true,
            showRoot: true,
            buttonsDisabled: true,
            checkboxes: false,
            showEditLink: this.showEditLink,
            isExpanded: this.isExpanded,
            hasExpandedToggler: this.hasExpandedToggler,
            readOnly: true,
        });

        if (this.currentCategoryId) {
            view.setSelected(this.currentCategoryId);
        }

        view.render().then(() => {});

        this.listenTo(view, 'select', (model: Model) => {
            if (!this.isExpanded) {
                let id = null;
                let name = null;

                if (model && model.id) {
                    id = model.id;
                    name = model.attributes.name;
                }

                this.openCategory(id, name);
                this.navigateToCurrentCategory();

                return;
            }

            this.currentCategoryId = null;
            this.currentCategoryName = '';

            if (model && model.id) {
                this.currentCategoryId = model.id;
                this.currentCategoryName = model.attributes.name;
            }

            this.collection.offset = 0;
            this.collection.maxSize = this.defaultMaxSize;
            this.collection.reset();

            this.applyCategoryToCollection();
            this.collection.abortLastFetch();

            this.openCategory(this.currentCategoryId, this.currentCategoryName);
            this.navigateToCurrentCategory();
        });
    }

    /**
     * @todo Move to helper. Together with select-records view.
     */
    private applyCategoryToCollection() {
        this.collection.whereFunction = () => {
            let filter: any;
            const isExpanded = this.isExpanded;

            if (!isExpanded && !this.hasTextFilter()) {
                if (this.isCategoryMultiple) {
                    if (this.currentCategoryId) {
                        filter = {
                            attribute: this.categoryField,
                            type: 'linkedWith',
                            value: [this.currentCategoryId]
                        };
                    } else {
                        filter = {
                            attribute: this.categoryField,
                            type: 'isNotLinked'
                        };
                    }
                } else {
                    if (this.currentCategoryId) {
                        filter = {
                            attribute: this.categoryField + 'Id',
                            type: 'equals',
                            value: this.currentCategoryId
                        };
                    } else {
                        filter = {
                            attribute: this.categoryField + 'Id',
                            type: 'isNull'
                        };
                    }
                }
            } else {
                if (this.currentCategoryId) {
                    filter = {
                        attribute: this.categoryField,
                        type: this.categoryFilterType,
                        value: this.currentCategoryId,
                    };
                }
            }

            if (filter) {
                return [filter];
            }

            return [];
        };
    }

    getCreateAttributes(): Record<string, unknown> | null {
        let data: Record<string, unknown>;

        if (this.isCategoryMultiple) {
            if (this.currentCategoryId) {
                const names = {} as Record<string, any>;

                names[this.currentCategoryId] = this.getCurrentCategoryName();

                data = {};

                const idsAttribute = this.categoryField + 'Ids';
                const namesAttribute = this.categoryField + 'Names';

                data[idsAttribute] = [this.currentCategoryId];
                data[namesAttribute] = names;

                return data;
            }

            return null;
        }

        const idAttribute = this.categoryField + 'Id';
        const nameAttribute = this.categoryField + 'Name';

        data = {};

        data[idAttribute] = this.currentCategoryId;
        data[nameAttribute] = this.getCurrentCategoryName();

        return data;
    }

    private getCurrentCategoryName(): string | null {
        if (this.currentCategoryName) {
            return this.currentCategoryName;
        }

        if (
            this.nestedCategoriesCollection &&
            this.nestedCategoriesCollection.categoryData &&
            this.nestedCategoriesCollection.categoryData.name
        ) {
            return this.nestedCategoriesCollection.categoryData.name;
        }

        return this.currentCategoryId;
    }

    private actionManageCategories() {
        this.clearCategoryViews();

        const url = `#${this.categoryScope}`;

        const options = {} as Record<string, any>;

        if (this.currentCategoryId) {
            options.currentId = this.currentCategoryId;
        }

        this.getRouter().navigate(url, {trigger: false});
        this.getRouter().dispatch(this.categoryScope, 'listTree', options);
    }

    getHeader(): string {
        if (!this.nestedCategoriesCollection) {
            return super.getHeader();
        }

        const path = this.nestedCategoriesCollection.path;

        if (!path || path.length === 0) {
            return super.getHeader();
        }

        let rootUrl = `#${this.scope}`;

        if (this._primaryFilter) {
            const filterPart = this.getHelper().escapeString(this._primaryFilter);

            rootUrl += `/list/primaryFilter=${filterPart}`;
        }

        const root = document.createElement('span');
        root.style.userSelect = 'none';

        const a = document.createElement('a');
        a.href = rootUrl;
        a.textContent = this.translate(this.scope, 'scopeNamesPlural');
        a.dataset.action = 'openCategory';
        a.classList.add('action');
        a.style.userSelect = 'none';

        root.append(a);

        const iconHtml = this.getHeaderIconHtml();

        if (iconHtml) {
            root.insertAdjacentHTML('afterbegin', iconHtml);
        }

        const list = [root] as any[];

        const currentName = this.nestedCategoriesCollection.categoryData?.name;
        const upperId = this.nestedCategoriesCollection.categoryData?.upperId;
        const upperName = this.nestedCategoriesCollection.categoryData?.upperName;

        if (path.length > 2) {
            list.push('...');
        }

        if (upperId) {
            const upperIdPart = this.getHelper().escapeString(upperId);

            let url = `${rootUrl}/list/categoryId=${upperIdPart}`;

            if (this._primaryFilter) {
                const filterPart = this.getHelper().escapeString(this._primaryFilter);

                url += `&primaryFilter=${filterPart}`;
            }

            const folder = document.createElement('a');
            folder.href = url;
            folder.textContent = upperName ?? null;
            folder.classList.add('action');
            folder.dataset.action = 'openCategory';
            folder.dataset.id = upperId;
            folder.dataset.name = upperName;
            folder.style.userSelect = 'none';

            list.push(folder);
        }

        const last = document.createElement('span');
        last.textContent = currentName ?? null;
        last.dataset.action = 'fullRefresh';
        last.style.cursor = 'pointer';
        last.style.userSelect = 'none';

        list.push(last);

        return this.buildHeaderHtml(list);
    }

    protected updateHeader() {
        this.getHeaderView()?.reRender();
    }

    protected hideListContainer() {
        this.$listContainer.addClass('hidden');
    }

    protected showListContainer() {
        this.$listContainer.removeClass('hidden');
    }

    // noinspection JSUnusedGlobalSymbols
    protected async actionToggleNavigationPanel(): Promise<void> {
        this.hasNavigationPanel = !this.hasNavigationPanel;

        this.setNavigationPanelStoredValue(this.hasNavigationPanel);

        await this.reRender();

        this.loadNestedCategories();
    }

    prepareRecordViewOptions(options: Record<string, any>) {
        super.prepareRecordViewOptions(options);

        options.forceDisplayTopBar = false;
    }

    private async actionToggleExpandedFromNavigation() {
        this.isExpanded = !this.isExpanded;

        this.hasNavigationPanel = true;
        this.setNavigationPanelStoredValue(this.hasNavigationPanel);

        const a = this.element.querySelector<HTMLAnchorElement>('a[data-role="expandButtonContainer"]');

        if (a) {
            a.classList.add('disabled');
        }

        Ui.notifyWait();

        if (this.isExpanded) {
            await this.actionExpand();
        } else {
            await this.actionCollapse();
        }

        Ui.notify();
    }

    protected async actionFullRefresh() {
        await Promise.all([
            super.actionFullRefresh(),
            this.nestedCategoriesCollection?.fetch(),
        ]);
    }
}

export default ListWithCategories;
