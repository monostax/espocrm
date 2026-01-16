/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2025 EspoCRM, Inc.
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

import ListRecordView from "views/record/list";

class ActivitiesRecordListView extends ListRecordView {
    type = "list";
    checkboxes = true;
    massActionsDisabled = false;
    massActionList = ["remove"];
    rowActionsView = "views/record/row-actions/view-and-edit";

    setup() {
        super.setup();

        // Parent class loads layout dynamically from layouts/Activities/list.json
        // No need to hardcode - Layout Manager will handle customizations
    }

    getModelScope(id) {
        const model = this.collection.get(id);

        if (model) {
            return model.entityType;
        }

        return null;
    }

    /**
     * Custom mass remove for multi-collection (Activities).
     * Groups selected records by entity type and deletes them separately.
     */
    massActionRemove() {
        // Group selected IDs by entity type
        const idsByEntityType = {};

        this.checkedList.forEach((id) => {
            const model = this.collection.get(id);

            if (model) {
                const entityType = model.entityType;

                if (!idsByEntityType[entityType]) {
                    idsByEntityType[entityType] = [];
                }

                idsByEntityType[entityType].push(id);
            }
        });

        // Check if user has delete permission for all entity types
        for (const entityType of Object.keys(idsByEntityType)) {
            if (!this.getAcl().check(entityType, "delete")) {
                Espo.Ui.error(
                    this.translate("Access denied") + ": " + entityType
                );
                return false;
            }
        }

        this.confirm(
            {
                message: this.translate(
                    "removeSelectedRecordsConfirmation",
                    "messages"
                ),
                confirmText: this.translate("Remove"),
            },
            () => {
                Espo.Ui.notifyWait();

                // Create delete promises for each entity type
                const promises = Object.entries(idsByEntityType).map(
                    ([entityType, ids]) => {
                        return Espo.Ajax.postRequest("MassAction", {
                            entityType: entityType,
                            action: "delete",
                            params: {
                                ids: ids,
                            },
                        });
                    }
                );

                // Execute all delete requests
                Promise.all(promises)
                    .then((results) => {
                        // Sum up the total count of removed records
                        let totalCount = 0;
                        const allRemovedIds = [];

                        results.forEach((result) => {
                            if (result && result.count) {
                                totalCount += result.count;
                            }

                            if (result && result.ids) {
                                allRemovedIds.push(...result.ids);
                            }
                        });

                        if (!totalCount) {
                            Espo.Ui.warning(
                                this.translate("noRecordsRemoved", "messages")
                            );
                            return;
                        }

                        this.unselectAllResult();

                        // Remove deleted records from the list
                        allRemovedIds.forEach((id) => {
                            this.collection.trigger("model-removing", id);
                            this.removeRecordFromList(id);
                            this.uncheckRecord(id, null, true);
                        });

                        // Refresh the collection
                        this.collection.fetch().then(() => {
                            const msg =
                                totalCount === 1
                                    ? "massRemoveResultSingle"
                                    : "massRemoveResult";

                            Espo.Ui.success(
                                this.translate(msg, "messages").replace(
                                    "{count}",
                                    totalCount
                                )
                            );
                        });

                        this.collection.trigger("after:mass-remove");
                        Espo.Ui.notify(false);
                    })
                    .catch(() => {
                        Espo.Ui.error(this.translate("Error"));
                    });
            }
        );
    }
}

export default ActivitiesRecordListView;

