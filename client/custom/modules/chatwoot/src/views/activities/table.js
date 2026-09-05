/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import ListRecordView from "views/record/list";

/** Table shared by the embedded planned and completed activity panels. */
class ActivitiesTableView extends ListRecordView {
    template = "chatwoot:activities/table";
    checkboxes = false;
    massActionsDisabled = true;
    paginationDisabled = true;
    columnResize = false;

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
        this.listenTo(this.collection, "sync", () => this.loadParticipants());
        this.wait(this.loadParticipants());
    }

    data() {
        const data = super.data();

        return { ...data, columnCount: data.headerDefs.length };
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
