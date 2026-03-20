/**
 * A field view that displays a relationship list (panel) inside a detail layout.
 * This allows relationships to appear as tabs in the detail view instead of bottom panels.
 *
 * Usage in detail layout JSON:
 * {
 *     "tabBreak": true,
 *     "tabLabel": "Contacts",
 *     "name": "contactsPanel",
 *     "rows": [
 *         [
 *             {
 *                 "name": "contactsList",
 *                 "view": "global:views/fields/relationship-list",
 *                 "noLabel": true,
 *                 "span": 4,
 *                 "options": {
 *                     "link": "contacts",
 *                     "layout": "listSmall"
 *                 }
 *             }
 *         ]
 *     ]
 * }
 */

import BaseFieldView from "views/fields/base";

class RelationshipListFieldView extends BaseFieldView {
    // noinspection JSUnusedGlobalSymbols
    templateContent = `
        <div class="relationship-list-field">
            <div class="panel panel-default panel-condensed{{#if isCollapsed}} is-collapsed{{/if}}">
                <div class="panel-heading">
                    <div class="pull-right btn-group panel-actions-container">
                        {{#if showCreateButton}}
                        <button
                            type="button"
                            class="btn btn-default btn-sm panel-action action"
                            data-action="createRelated"
                            data-panel="{{link}}"
                            data-link="{{link}}"
                            title="{{translate 'Create'}}"
                        ><span class="fas fa-plus"></span></button>
                        {{/if}}
                        {{#if showSelectButton}}
                        <button
                            type="button"
                            class="btn btn-default btn-sm panel-action action"
                            data-action="selectRelated"
                            data-panel="{{link}}"
                            data-link="{{link}}"
                            title="{{translate 'Select'}}"
                        ><span class="fas fa-link"></span></button>
                        {{/if}}
                        {{#if showViewListButton}}
                        <button
                            type="button"
                            class="btn btn-default btn-sm panel-action action"
                            data-action="viewRelatedList"
                            data-panel="{{link}}"
                            data-link="{{link}}"
                            title="{{translate 'View List'}}"
                        ><span class="fas fa-list"></span></button>
                        {{/if}}
                    </div>
                    <h4 class="panel-title">
                        <span class="panel-collapse-chevron fas {{#if isCollapsed}}fa-chevron-right{{else}}fa-chevron-down{{/if}}"></span>
                        {{#if icon}}<span class="relationship-list-entity-icon {{icon}}"{{#if iconColor}} style="color: {{iconColor}}"{{/if}}></span> {{/if}}
                        <span class="relationship-list-title-text">{{title}}</span>
                    </h4>
                </div>
                <div class="panel-body{{#if isCollapsed}} hidden{{/if}}">
                    {{#if hasId}}
                    <div class="relationship-list-container"></div>
                    {{else}}
                    <div class="text-muted">
                        {{translate 'Save record first'}}
                    </div>
                    {{/if}}
                </div>
            </div>
        </div>
    `;

    /** @type {string} */
    link = null;

    /** @type {string|null} */
    layout = null;

    /** @type {number} */
    recordsPerPage = 10;

    /** @type {boolean} */
    createDisabled = false;

    /** @type {boolean} */
    selectDisabled = false;

    /** @type {boolean} */
    unlinkDisabled = false;

    /** @type {string|null} */
    orderBy = null;

    /** @type {string|null} */
    orderDirection = null;

    /** @type {string|null} */
    rowActionsView = "views/record/row-actions/relationship";

    /** @type {string} */
    foreignEntityType = null;

    /** @type {boolean} */
    isCollapsed = false;

    /** @type {string|null} */
    collapseStorageKey = null;

    events = {
        'click .panel-heading': function (e) {
            if (
                $(e.target).closest(
                    '.panel-actions-container, .action, a, button, input, textarea, select, .dropdown-menu'
                ).length
            ) {
                return;
            }

            this.toggleCollapsed();
        },
        'click [data-action="createRelated"]': function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.actionCreateRelated();
        },
        'click [data-action="selectRelated"]': function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.actionSelectRelated();
        },
        'click [data-action="viewRelatedList"]': function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.actionViewRelatedList();
        },
    };

    data() {
        const hasId = !!this.model.id;

        return {
            // Only show action buttons when we have an ID (existing record)
            showCreateButton: hasId && this.showCreateButton,
            showSelectButton: hasId && this.showSelectButton,
            showViewListButton: hasId,
            hasId: hasId,
            link: this.link,
            title: this.getTitle(),
            icon: this.getIcon(),
            iconColor: this.getIconColor(),
            isCollapsed: this.isCollapsed,
        };
    }

    /**
     * Get the translated title for this relationship.
     * @returns {string}
     */
    getTitle() {
        return this.translate(this.link, "links", this.model.entityType);
    }

    /**
     * Get the entity icon class for the foreign entity.
     * @returns {string|null}
     */
    getIcon() {
        if (!this.foreignEntityType) return null;

        return this.getMetadata().get([
            "clientDefs",
            this.foreignEntityType,
            "iconClass",
        ]) || null;
    }

    /**
     * Get the entity icon color for the foreign entity.
     * @returns {string|null}
     */
    getIconColor() {
        if (!this.foreignEntityType) return null;

        return this.getMetadata().get([
            "clientDefs",
            this.foreignEntityType,
            "color",
        ]) || null;
    }

    /**
     * Update the count badge in the header.
     * @param {number} count
     */
    updateCount(count) {
        const $badge = this.$el.find('[data-role="count"]');

        if (count > 0) {
            $badge.text(count).show();
        } else {
            $badge.hide();
        }
    }

    /**
     * Override fetch to return empty object.
     * This is a display-only view that shows relationship panels,
     * it doesn't have actual field data to save to the model.
     *
     * @returns {Object}
     */
    fetch() {
        return {};
    }

    /**
     * No attributes to track for this display-only view.
     *
     * @returns {string[]}
     */
    getAttributeList() {
        return [];
    }

    /**
     * This field is always valid (nothing to validate).
     *
     * @returns {boolean}
     */
    validate() {
        return false;
    }

    setup() {
        super.setup();

        this.link = this.options.link || this.options.defs?.params?.link;

        if (!this.link) {
            console.error(
                "RelationshipListFieldView: link parameter is required"
            );
            return;
        }

        this.layout =
            this.options.layout ||
            this.options.defs?.params?.layout ||
            "listSmall";
        this.recordsPerPage =
            this.options.recordsPerPage ||
            this.options.defs?.params?.recordsPerPage ||
            10;
        this.createDisabled =
            this.options.createDisabled ??
            this.options.defs?.params?.createDisabled ??
            false;
        this.selectDisabled =
            this.options.selectDisabled ??
            this.options.defs?.params?.selectDisabled ??
            false;
        this.unlinkDisabled =
            this.options.unlinkDisabled ??
            this.options.defs?.params?.unlinkDisabled ??
            false;
        this.orderBy =
            this.options.orderBy || this.options.defs?.params?.orderBy || null;
        this.orderDirection =
            this.options.orderDirection ||
            this.options.defs?.params?.orderDirection ||
            null;
        this.rowActionsView =
            this.options.rowActionsView ||
            this.options.defs?.params?.rowActionsView ||
            "views/record/row-actions/relationship";

        this.setupCollapsedState();

        const linkDefs = this.model.defs.links[this.link];

        if (!linkDefs) {
            console.error(
                `RelationshipListFieldView: link '${this.link}' not found in model`
            );
            return;
        }

        this.foreignEntityType = linkDefs.entity;

        // Check permissions for buttons
        const panelDefs =
            this.getMetadata().get([
                "clientDefs",
                this.model.entityType,
                "relationshipPanels",
                this.link,
            ]) || {};

        const noCreateScopeList = ["User", "Team", "Role", "Portal"];

        this.showCreateButton =
            !this.createDisabled &&
            panelDefs.create !== false &&
            !panelDefs.createDisabled &&
            this.getAcl().check(this.foreignEntityType, "create") &&
            !noCreateScopeList.includes(this.foreignEntityType);

        this.showSelectButton =
            !this.selectDisabled &&
            !panelDefs.selectDisabled &&
            this.getAcl().check(this.model.entityType, "edit");
    }

    setupCollapsedState() {
        const userId = this.getUser().id || "anonymous";
        const panelKey = this.name || this.options.defs?.name || this.link;

        this.collapseStorageKey =
            `record-panel-collapse:${userId}:${this.model.entityType}:relationship-list:${panelKey}`;

        this.isCollapsed = this.getCollapsedStored();
    }

    getCollapsedStored() {
        try {
            return localStorage.getItem(this.collapseStorageKey) === "true";
        } catch (e) {
            return false;
        }
    }

    setCollapsedStored(value) {
        try {
            localStorage.setItem(this.collapseStorageKey, value ? "true" : "false");
        } catch (e) {}
    }

    toggleCollapsed() {
        this.isCollapsed = !this.isCollapsed;

        this.setCollapsedStored(this.isCollapsed);
        this.applyCollapsedState();
    }

    applyCollapsedState() {
        if (!this.isRendered()) {
            return;
        }

        const $panel = this.$el.find('.panel').first();

        if (!$panel.length) {
            return;
        }

        $panel.toggleClass('is-collapsed', this.isCollapsed);
        $panel.find('> .panel-body').toggleClass('hidden', this.isCollapsed);

        const $icon = $panel.find('> .panel-heading .panel-collapse-chevron').first();

        if ($icon.length) {
            $icon
                .toggleClass('fa-chevron-down', !this.isCollapsed)
                .toggleClass('fa-chevron-right', this.isCollapsed);
        }
    }

    afterRender() {
        this.applyCollapsedState();

        // Only setup relationship panel if we have a link AND the model has an ID
        // (i.e., we're in detail/edit mode of an existing record, not create mode)
        if (this.link && this.model.id) {
            this.setupRelationshipPanel();
        }
    }

    /**
     * Listen to the list collection and update count badge.
     * @private
     */
    listenToListCollection() {
        const listView = this.getView("list");

        if (!listView) return;

        // Wait for the nested view to be ready and have a collection
        const tryListen = () => {
            if (listView.collection) {
                this.updateCount(listView.collection.total || listView.collection.length);

                this.listenTo(listView.collection, "sync", () => {
                    this.updateCount(listView.collection.total || listView.collection.length);
                });
            }
        };

        if (listView.isRendered()) {
            // The list view might create its own nested list view with the collection
            // We need to wait for that inner view
            setTimeout(() => {
                const innerList = listView.getView("list");
                if (innerList && innerList.collection) {
                    this.updateCount(innerList.collection.total || innerList.collection.length);

                    this.listenTo(innerList.collection, "sync", () => {
                        this.updateCount(innerList.collection.total || innerList.collection.length);
                    });
                } else {
                    tryListen();
                }
            }, 100);
        } else {
            this.listenToOnce(listView, "after:render", () => {
                setTimeout(() => {
                    const innerList = listView.getView("list");
                    if (innerList && innerList.collection) {
                        this.updateCount(innerList.collection.total || innerList.collection.length);

                        this.listenTo(innerList.collection, "sync", () => {
                            this.updateCount(innerList.collection.total || innerList.collection.length);
                        });
                    } else {
                        tryListen();
                    }
                }, 100);
            });
        }
    }

    setupRelationshipPanel() {
        const panelDefs =
            this.getMetadata().get([
                "clientDefs",
                this.model.entityType,
                "relationshipPanels",
                this.link,
            ]) || {};

        // Clear any existing view
        this.clearView("list");

        this.createView(
            "list",
            "views/record/panels/relationship",
            {
                selector: ".relationship-list-container",
                model: this.model,
                mode: "detail",
                link: this.link,
                defs: {
                    create: false, // We handle buttons ourselves
                    select: false, // We handle buttons ourselves
                    view: false, // We handle buttons ourselves
                    unlinkDisabled:
                        this.unlinkDisabled || panelDefs.unlinkDisabled,
                    layout: this.layout,
                    ...panelDefs,
                },
                recordsPerPage: this.recordsPerPage,
                rowActionsView: this.rowActionsView,
                panelName: this.link,
                readOnly: this.options.readOnly || false,
                recordHelper: this.options.recordHelper,
            },
            (view) => {
                view.render();

                this.listenToListCollection();
            }
        );
    }

    actionCreateRelated() {
        const link = this.link;
        const scope = this.foreignEntityType;
        const foreignLink = this.model.defs.links[link].foreign;

        Espo.Ui.notify(" ... ");

        const viewName =
            this.getMetadata().get([
                "clientDefs",
                scope,
                "modalViews",
                "edit",
            ]) || "views/modals/edit";

        const attributes = {};

        if (foreignLink && this.model.defs.links[link].type === "hasMany") {
            if (
                this.getMetadata().get([
                    "entityDefs",
                    scope,
                    "fields",
                    foreignLink,
                    "type",
                ]) === "link"
            ) {
                attributes[foreignLink + "Id"] = this.model.id;
                attributes[foreignLink + "Name"] = this.model.get("name");
            } else if (
                this.getMetadata().get([
                    "entityDefs",
                    scope,
                    "fields",
                    foreignLink,
                    "type",
                ]) === "linkMultiple"
            ) {
                attributes[foreignLink + "Ids"] = [this.model.id];
                attributes[foreignLink + "Names"] = {};
                attributes[foreignLink + "Names"][this.model.id] =
                    this.model.get("name");
            }
        }

        this.createView(
            "quickCreate",
            viewName,
            {
                scope: scope,
                relate: {
                    model: this.model,
                    link: foreignLink,
                },
                attributes: attributes,
            },
            (view) => {
                view.render();

                Espo.Ui.notify(false);

                this.listenToOnce(view, "after:save", () => {
                    this.refreshList();
                    this.model.trigger("after:relate", link);
                });
            }
        );
    }

    actionSelectRelated() {
        const link = this.link;
        const scope = this.foreignEntityType;

        Espo.Ui.notify(" ... ");

        const panelDefs =
            this.getMetadata().get([
                "clientDefs",
                this.model.entityType,
                "relationshipPanels",
                link,
            ]) || {};

        const viewName =
            this.getMetadata().get([
                "clientDefs",
                scope,
                "modalViews",
                "select",
            ]) || "views/modals/select-records";

        const filters = {};

        if (panelDefs.selectBoolFilterList) {
            filters.boolFilterList = panelDefs.selectBoolFilterList;
        }

        if (panelDefs.selectPrimaryFilterName) {
            filters.primaryFilterName = panelDefs.selectPrimaryFilterName;
        }

        this.createView(
            "dialog",
            viewName,
            {
                scope: scope,
                multiple: true,
                createButton: this.showCreateButton,
                triggerCreateEvent: true,
                ...filters,
            },
            (view) => {
                view.render();

                Espo.Ui.notify(false);

                this.listenToOnce(view, "select", (models) => {
                    if (!Array.isArray(models)) {
                        models = [models];
                    }

                    const ids = models.map((m) => m.id);

                    Espo.Ajax.postRequest(
                        `${this.model.entityType}/${this.model.id}/${link}`,
                        { ids: ids }
                    ).then(() => {
                        Espo.Ui.success(this.translate("Linked"));
                        this.refreshList();
                        this.model.trigger("after:relate", link);
                    });
                });

                this.listenToOnce(view, "create", () => {
                    view.close();
                    this.actionCreateRelated();
                });
            }
        );
    }

    actionViewRelatedList() {
        const link = this.link;
        const scope = this.foreignEntityType;

        const url = `${this.model.entityType}/${this.model.id}/${link}`;

        const viewName =
            this.getMetadata().get([
                "clientDefs",
                scope,
                "modalViews",
                "relatedList",
            ]) || "views/modals/related-list";

        Espo.Ui.notify(" ... ");

        this.createView(
            "dialog",
            viewName,
            {
                model: this.model,
                link: link,
                scope: scope,
                url: url,
                createDisabled: !this.showCreateButton,
                selectDisabled: !this.showSelectButton,
            },
            (view) => {
                view.render();
                Espo.Ui.notify(false);

                this.listenToOnce(view, "close", () => {
                    this.refreshList();
                });
            }
        );
    }

    refreshList() {
        const listView = this.getView("list");

        if (listView && listView.collection) {
            listView.collection.fetch();
        } else {
            // Try inner list view
            const innerList = listView && listView.getView("list");
            if (innerList && innerList.collection) {
                innerList.collection.fetch();
            }
        }
    }
}

export default RelationshipListFieldView;







