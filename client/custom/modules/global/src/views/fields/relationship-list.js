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

import View from "view";

class RelationshipListFieldView extends View {
    template = "global:fields/relationship-list";

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

    events = {
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
        return {
            showCreateButton: this.showCreateButton,
            showSelectButton: this.showSelectButton,
            showViewListButton: true,
        };
    }

    setup() {
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
            !panelDefs.createDisabled &&
            this.getAcl().check(this.foreignEntityType, "create") &&
            !noCreateScopeList.includes(this.foreignEntityType);

        this.showSelectButton =
            !this.selectDisabled &&
            !panelDefs.selectDisabled &&
            this.getAcl().check(this.model.entityType, "edit");
    }

    afterRender() {
        if (this.link) {
            this.setupRelationshipPanel();
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
        }
    }
}

export default RelationshipListFieldView;


