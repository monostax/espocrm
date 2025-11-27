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

import SearchView from "views/record/search";
import Model from "model";

/**
 * Custom search view for Activities (virtual entity combining Meeting, Call, Task)
 * This directly sets filter fields bypassing the layout manager
 */
class ActivitiesSearchView extends SearchView {
    setup() {
        const metadata = this.getMetadata();

        // Add prepareModel method to collection (MultiCollection doesn't have it)
        // This is needed for filter field views to work with the virtual Activities entity
        this.collection.prepareModel = () => {
            const fields =
                metadata.get(["entityDefs", "Activities", "fields"]) || {};

            const ActivityModel = Model.extend({
                entityType: "Activities",
                urlRoot: "Activities",
                defs: { fields: fields },
            });

            const model = new ActivityModel();
            model.entityType = "Activities";
            model.defs = { fields: fields };

            // Add required model methods that filters expect
            model.getFieldType = function (field) {
                return (
                    metadata.get([
                        "entityDefs",
                        "Activities",
                        "fields",
                        field,
                        "type",
                    ]) || this.defs?.fields?.[field]?.type
                );
            };

            model.getFieldParam = function (field, param) {
                return (
                    metadata.get([
                        "entityDefs",
                        "Activities",
                        "fields",
                        field,
                        param,
                    ]) || this.defs?.fields?.[field]?.[param]
                );
            };

            model.getLinkParam = function (link, param) {
                return metadata.get([
                    "entityDefs",
                    "Activities",
                    "links",
                    link,
                    param,
                ]);
            };

            model.hasLink = function (link) {
                return !!metadata.get([
                    "entityDefs",
                    "Activities",
                    "links",
                    link,
                ]);
            };

            return model;
        };

        // For virtual entities, layoutManager might not work correctly
        // Load filters from clientDefs (more reliable for virtual entities)
        const filterList =
            metadata.get(["clientDefs", "Activities", "filterList"]) || [];
        const forbiddenFieldList =
            this.getAcl().getScopeForbiddenFieldList(this.entityType) || [];

        console.log(
            "ActivitiesSearchView: filterList from clientDefs:",
            filterList
        );
        console.log(
            "ActivitiesSearchView: forbiddenFieldList:",
            forbiddenFieldList
        );

        this.fieldFilterList = filterList.filter(
            (field) => !forbiddenFieldList.includes(field)
        );

        console.log(
            "ActivitiesSearchView: final fieldFilterList:",
            this.fieldFilterList
        );

        this.fieldFilterTranslations = {};

        this.fieldFilterList.forEach((field) => {
            this.fieldFilterTranslations[field] = this.translate(
                field,
                "fields",
                this.entityType
            );
        });

        // Override the async layout loading method temporarily to prevent parent from overriding our filters
        const originalWait = this.wait.bind(this);
        this.wait = (condition) => {
            // Skip the layoutManager.get() wait - we already have filters
            if (condition && condition instanceof Promise) {
                return;
            }
            originalWait(condition);
        };

        super.setup();

        // Restore original wait
        this.wait = originalWait;
    }

    /**
     * Override fetch to handle cases where field views might not be ready
     */
    fetch() {
        console.log(
            "fetch() called, advanced fields:",
            Object.keys(this.advanced || {})
        );

        this.textFilter = (
            this.$el.find('input[data-name="textFilter"]').val() || ""
        ).trim();

        this.bool = {};

        this.boolFilterList.forEach((name) => {
            this.bool[name] = this.$el
                .find(
                    'input[data-name="' +
                        name +
                        '"][data-role="boolFilterCheckbox"]'
                )
                .prop("checked");
        });

        for (const field in this.advanced) {
            console.log(`Processing filter field: ${field}`);

            const filterView = this.getView("filter-" + field);

            if (!filterView) {
                console.warn("Filter view not found for field:", field);
                delete this.advanced[field];
                continue;
            }

            console.log(`Filter view found for ${field}:`, filterView);

            const fieldView = filterView.getView("field");

            if (!fieldView) {
                console.warn("Field view not found for field:", field);
                delete this.advanced[field];
                continue;
            }

            console.log(`Field view found for ${field}:`, fieldView);

            if (typeof fieldView.fetchSearch !== "function") {
                console.warn("fetchSearch not available for field:", field);
                delete this.advanced[field];
                continue;
            }

            const searchData = fieldView.fetchSearch();
            console.log(`fetchSearch result for ${field}:`, searchData);

            // Trust the field view - if fetchSearch returns false or an object without a type, remove it
            if (
                searchData === false ||
                searchData === null ||
                searchData === undefined
            ) {
                console.log(
                    `Removing ${field} - fetchSearch returned falsy value`
                );
                delete this.advanced[field];
                continue;
            }

            // If it's an object, check if it has a type
            if (
                typeof searchData === "object" &&
                (!searchData.type || searchData.type === "")
            ) {
                console.log(`Removing ${field} - no type in searchData`);
                delete this.advanced[field];
                continue;
            }

            // Keep the filter - the field view says it's valid
            this.advanced[field] = searchData;
            console.log(`Keeping ${field} with data:`, searchData);
        }

        console.log("fetch() complete, final advanced:", this.advanced);
    }

    /**
     * Override addFilter to handle field view creation issues
     */
    addFilter(name) {
        console.log(`addFilter called for: ${name}`);
        console.log("advanced before:", this.advanced);

        this.advanced = this.advanced || {};
        this.advanced[name] = {};

        console.log("advanced after adding field:", this.advanced);

        this.presetName = this.primary;

        this.createFilter(name, {}, (view) => {
            console.log(`createFilter callback for ${name}, view:`, view);

            view.populateDefaults();

            // Don't call fetch/updateSearch here - let the user select a value first
            // They will be called when the user actually triggers the search

            // Safe check for initialSearchIsNotIdle
            const fieldView = view.getFieldView();
            if (fieldView && fieldView.initialSearchIsNotIdle) {
                this.fetch();
                this.updateSearch();
            }
        });

        this.updateAddFilterButton();
        this.handleLeftDropdownVisibility();

        this.manageLabels();
        this.controlResetButtonVisibility();
    }

    /**
     * Override updateSearch to clean up empty filters before sending to search manager
     */
    updateSearch() {
        // Clean up invalid filters from advanced (only remove if no type)
        const cleanAdvanced = {};

        for (const field in this.advanced) {
            const data = this.advanced[field];

            // Only skip if the data is invalid (no type)
            if (
                !data ||
                typeof data !== "object" ||
                !data.type ||
                data.type === ""
            ) {
                console.log("Skipping filter with invalid data:", field, data);
                continue;
            }

            // Include this filter - trust that fetch() already validated it
            cleanAdvanced[field] = data;
        }

        console.log("updateSearch: cleanAdvanced:", cleanAdvanced);

        this.searchManager.set({
            textFilter: this.textFilter,
            advanced: cleanAdvanced,
            bool: this.bool,
            presetName: this.presetName,
            primary: this.primary,
        });
    }
}

export default ActivitiesSearchView;

