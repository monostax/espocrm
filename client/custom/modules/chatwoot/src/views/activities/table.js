/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import ListRecordView from "views/record/list";
import Ui from "ui";

/** Table shared by the embedded planned and completed activity panels. */
class ActivitiesTableView extends ListRecordView {
    template = "chatwoot:activities/table";
    checkboxes = true;
    checkAllResultDisabled = true;
    stickyBarDisabled = true;
    massActionList = [];
    checkAllResultMassActionList = [];
    paginationDisabled = true;
    columnResize = false;

    init() {
        // The compact activity panel passes checkboxes: false and its own actions.
        this.options = {
            ...this.options,
            checkboxes: true,
            rowActionsView: "chatwoot:views/activities/row-actions",
        };

        super.init();
    }

    setup() {
        this.listLayout = [
            {
                name: "name",
                link: true,
                width: 25,
                view: "global:views/activities/fields/name-with-icon",
            },
            {
                name: "_scope",
                view: "global:views/activities/fields/entity-type",
            },
            { name: "status" },
            { name: "dateEnd" },
            { name: "assignedUser" },
            { name: "users" },
        ].map((column) => ({
            ...column,
            customLabel: this.translate(
                column.name,
                "fields",
                column.name === "_scope" ? "ChatwootActivities" : "Activities",
            ),
            // The combined Activities endpoint has a fixed chronological order.
            notSortable: true,
        }));

        // Keep each entity's native status, date and user field renderers.
        // A MultiCollection has no single entity type to derive these from.
        this.multiListLayout = Object.fromEntries(
            Object.entries(this.collection.seeds).map(([scope, seed]) => [
                scope,
                this.listLayout.map((column) => {
                    if (column.name !== "users") {
                        return { ...column };
                    }

                    const field = this.getParticipantsField(seed);

                    return field
                        ? {
                            ...column,
                            name: field,
                            view: "global:views/fields/link-multiple-with-icons",
                        }
                        : { ...column, view: "views/fields/base" };
                }),
            ]),
        );

        super.setup();

        this.displayTotalCount = false;
        // Use the CRM's fixed dropdown positioning outside the scroll container.
        this.on("after:render after:show-more", () => {
            this.$el.find(".list-row-buttons").parent().addClass("fix-position");
        });
        this.on("check after:render after:show-more", () => this.updateSelection());
        this.listenTo(this.collection, "sync", () => this.loadParticipants());
        this.wait(this.loadParticipants());
    }

    data() {
        const data = super.data();

        return {
            ...data,
            columnCount: data.headerDefs.length + (data.checkboxes ? 1 : 0),
        };
    }

    setupMassActionItems() {
        // Generic mass actions assume a single collection entity type.
        [...this.massActionList].forEach((action) => this.removeMassAction(action));

        for (const action of ["massUpdate", "remove"]) {
            if (Object.keys(this.collection.seeds).some((scope) => this.isBulkActionAllowed(scope, action))) {
                this.addMassAction(action);
            }
        }
    }

    isBulkActionAllowed(scope, action) {
        const defs = this.getMetadata().get(["clientDefs", scope]) || {};

        if (this.massActionsDisabled || this.options.massActionsDisabled) {
            return false;
        }

        if (action === "remove") {
            return !this.removeDisabled && !this.massActionRemoveDisabled &&
                !this.options.massActionRemoveDisabled && !defs.removeDisabled && !defs.massRemoveDisabled &&
                this.getAcl().checkScope(scope, "delete");
        }

        return !this.editDisabled && !this.massUpdateDisabled && !this.massActionMassUpdateDisabled &&
            !this.options.massActionMassUpdateDisabled && !defs.editDisabled && !defs.massUpdateDisabled &&
            this.getAcl().getPermissionLevel("massUpdatePermission") === "yes" &&
            this.getAcl().checkScope(scope, "edit");
    }

    getSelectedGroups(action) {
        const groups = new Map();

        for (const id of this.getCheckedIds()) {
            const model = this.collection.get(id);

            if (!model || !this.isBulkActionAllowed(model.entityType, action) ||
                !this.getAcl().checkModel(model, action === "remove" ? "delete" : "edit")) {
                return null;
            }

            if (!groups.has(model.entityType)) {
                groups.set(model.entityType, []);
            }

            groups.get(model.entityType).push(id);
        }

        return groups.size ? groups : null;
    }

    updateSelection() {
        const count = this.getCheckedIds().length;
        const total = this.collection.models.length;

        this.$el.find(".select-all")
            .prop("checked", total > 0 && count === total)
            .prop("indeterminate", count > 0 && count < total);
        this.$el.find(".selected-count").text(
            count ? this.translate("selectedCount", "labels", "ChatwootActivities")
                .replace("{count}", count) : "",
        );

        for (const action of this.massActionList) {
            const disabled = this.bulkActionBusy || !this.getSelectedGroups(action);

            this.$el.find(`.mass-action[data-action="${action}"]`)
                .attr("aria-disabled", disabled ? "true" : "false")
                .parent().toggleClass("disabled", !!disabled);
        }
    }

    async massActionRemove() {
        const groups = this.getSelectedGroups("remove");

        if (this.bulkActionBusy || !groups) {
            return;
        }

        this.bulkActionBusy = true;
        this.updateSelection();

        try {
            await this.confirm({
                message: this.translate("removeSelectedRecordsConfirmation", "messages"),
                confirmText: this.translate("Remove"),
            });

            Ui.notifyWait();

            const results = await Promise.allSettled([...groups].map(async ([entityType, ids]) => {
                const result = await Espo.Ajax.postRequest("MassAction", {
                    entityType,
                    action: "delete",
                    params: { ids },
                    idle: false,
                });

                // Only remove rows the server confirms it actually deleted.
                return ids.filter((id) => (result.ids || []).includes(id));
            }));
            const removed = results.flatMap((result) => result.status === "fulfilled" ? result.value : []);
            const selectedCount = [...groups.values()].reduce((count, ids) => count + ids.length, 0);

            removed.forEach((id) => {
                this.collection.trigger("model-removing", id);
                this.removeRecordFromList(id);
            });
            this.handleAfterCheck();

            if (removed.length) {
                this.collection.trigger("after:mass-remove");
                this.trigger("after:mass-remove");
            }

            if (removed.length < selectedCount) {
                Ui.warning(this.translate("bulkRemoveIncomplete", "messages", "ChatwootActivities")
                    .replace("{count}", removed.length).replace("{total}", selectedCount));
            } else {
                const key = removed.length === 1 ? "massRemoveResultSingle" : "massRemoveResult";
                Ui.success(this.translate(key, "messages").replace("{count}", removed.length));
            }
        } finally {
            this.bulkActionBusy = false;
            this.updateSelection();
        }
    }

    async massActionMassUpdate() {
        const groups = this.getSelectedGroups("massUpdate");

        if (this.bulkActionBusy || !groups) {
            return;
        }

        this.bulkActionBusy = true;
        this.updateSelection();
        let count = 0;
        let updated = false;

        try {
            // Each type has different fields/statuses. Reuse its native bulk editor.
            for (const [entityType, ids] of groups) {
                Ui.notifyWait();

                const viewName = this.getMetadata().get(["clientDefs", entityType, "modalViews", "massUpdate"]) ||
                    "views/modals/mass-update";
                const view = await this.createView("massUpdate", viewName, {
                    scope: entityType,
                    entityType,
                    ids,
                    byWhere: false,
                    totalCount: ids.length,
                });
                let result;
                const closed = new Promise((resolve) => this.listenToOnce(view, "close", resolve));

                this.listenToOnce(view, "after:update", (response) => {
                    result = response;
                    if (!response.idle) {
                        view.close();
                    }
                });

                await view.render();
                Ui.notify(false);
                await closed;

                // Cancel closes the remaining editors as well.
                if (!result) {
                    break;
                }

                updated = true;
                count += result.count || 0;
            }
        } finally {
            this.bulkActionBusy = false;
            this.updateSelection();

            if (updated) {
                this.trigger("after:mass-update");

                if (count) {
                    const key = count === 1 ? "massUpdateResultSingle" : "massUpdateResult";
                    Ui.success(this.translate(key, "messages").replace("{count}", count));
                } else {
                    Ui.warning(this.translate("noRecordsUpdated", "messages"));
                }
            }
        }
    }

    async actionQuickRemove(data) {
        const model = this.collection.get(data?.id);

        if (!model || this.removeDisabled ||
            this.getMetadata().get(["clientDefs", model.entityType, "removeDisabled"])) {
            return;
        }

        await super.actionQuickRemove(data);
        this.handleAfterCheck();
    }

    getModelScope(id) {
        return this.collection.get(id)?.entityType || null;
    }

    isInlineEditEnabledForField(model, field) {
        // A mixed activity list includes virtual and entity-specific columns.
        if (field === "_scope" || !model.getFieldType(field)) {
            return false;
        }

        const defs = this.getMetadata().get(["clientDefs", model.entityType]) || {};

        return !defs.inlineEditDisabled &&
            !defs.listInlineEditDisabled &&
            super.isInlineEditEnabledForField(model, field);
    }

    getParticipantsField(model) {
        return ["users", "assignedUsers"].find(
            (field) => model.getFieldType(field) === "linkMultiple",
        );
    }

    /**
     * The compact Activities response omits link-multiple fields. Load only
     * participants, in one ACL-protected list request per visible entity type.
     * Collection sync also covers refreshes and the standard Show more action.
     */
    async loadParticipants() {
        const requestId = this.participantsRequestId =
            (this.participantsRequestId || 0) + 1;

        await Promise.all(
            Object.entries(this.collection.seeds).map(async ([scope, seed]) => {
                const field = this.getParticipantsField(seed);
                const models = this.collection.models.filter(
                    (model) => model.entityType === scope,
                );

                if (
                    !field ||
                    !models.length ||
                    !this.getAcl().checkScope(scope, "read") ||
                    !this.getAcl().checkField(scope, field, "read")
                ) {
                    return;
                }

                const idsAttribute = field + "Ids";
                const namesAttribute = field + "Names";

                try {
                    const response = await Espo.Ajax.getRequest(scope, {
                        select: ["id", idsAttribute, namesAttribute].join(","),
                        where: [{
                            type: "in",
                            attribute: "id",
                            value: models.map((model) => model.id),
                        }],
                        maxSize: models.length,
                    });

                    if (requestId !== this.participantsRequestId) {
                        return;
                    }

                    for (const row of response.list || []) {
                        const model = this.collection.get(row.id);

                        if (model?.entityType !== scope) {
                            continue;
                        }

                        // Do not convert omitted/restricted data into an empty list.
                        const attributes = {};

                        for (const name of [idsAttribute, namesAttribute]) {
                            if (Object.hasOwn(row, name)) {
                                attributes[name] = row[name];
                            }
                        }

                        model.set(attributes);
                    }
                } catch (error) {
                    // A failed participant lookup must not hide the activities.
                    console.warn("ActivitiesTable: Failed to load participants:", error);
                }
            }),
        );
    }
}

export default ActivitiesTableView;
