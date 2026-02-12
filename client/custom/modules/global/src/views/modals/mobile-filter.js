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

import ModalView from "views/modal";
import FilterView from "views/search/filter";

/**
 * Mobile filter modal - full-screen filter interface
 */
class MobileFilterModal extends ModalView {
    template = "global:modals/mobile-filter";

    className = "mobile-filter-modal-wrapper";

    backdrop = true;

    header = false;
    footer = false;

    events = {
        'click [data-action="selectPreset"]': function (e) {
            const name = $(e.currentTarget).data('name');
            this.actionSelectPreset({ name });
        },
        'click [data-action="toggleBoolFilter"]': function (e) {
            const name = $(e.currentTarget).data('name');
            this.actionToggleBoolFilter({ name });
        },
        'click [data-action="addFieldFilter"]': function (e) {
            const name = $(e.currentTarget).data('name');
            this.actionAddFieldFilter({ name });
        },
        'click [data-action="removeAdvancedFilter"]': function (e) {
            const name = $(e.currentTarget).data('name');
            this.actionRemoveAdvancedFilter({ name });
        },
        'click .remove-filter': function (e) {
            const name = $(e.currentTarget).data('name');
            this.actionRemoveAdvancedFilter({ name });
        },
        'click [data-action="resetFilters"]': function () {
            this.actionResetFilters();
        },
        'click [data-action="applyFilters"]': function () {
            this.actionApplyFilters();
        },
        'click [data-action="closeModal"]': function () {
            this.actionCloseModal();
        },
    };

    data() {
        return {
            entityType: this.options.entityType,
            presetFilterList: this.options.presetFilterList || [],
            boolFilterList: this.options.boolFilterList || [],
            bool: this.options.bool || {},
            presetName: this.options.presetName,
            primaryFiltersDisabled: this.options.primaryFiltersDisabled,
            fieldFilterDataList: this.getFieldFilterDataList(),
            hasAdvancedFilters: this.hasAdvancedFilters(),
            advancedFilterViews: this.getAdvancedFilterViews(),
        };
    }

    setup() {
        this.entityType = this.options.entityType;
        this.model = this.options.model;
        this.bool = { ...this.options.bool } || {};

        const rawAdvanced = { ...this.options.advanced } || {};
        this.advanced = {};
        for (const name in rawAdvanced) {
            if (rawAdvanced[name] && rawAdvanced[name].type) {
                this.advanced[name] = rawAdvanced[name];
            }
        }

        this.presetName = this.options.presetName || null;
        this.fieldFilterList = this.options.fieldFilterList || [];
        this.fieldFilterTranslations =
            this.options.fieldFilterTranslations || {};
    }

    getFieldFilterDataList() {
        return this.fieldFilterList.map((field) => {
            return {
                name: field,
                label: this.fieldFilterTranslations[field] || field,
                checked: !!this.advanced[field],
            };
        });
    }

    hasAdvancedFilters() {
        return Object.keys(this.advanced).length > 0;
    }

    getAdvancedFilterViews() {
        const views = [];

        for (const name in this.advanced) {
            views.push({
                name: name,
            });
        }

        return views;
    }

    async createFilterViews() {
        for (const name in this.advanced) {
            const filterData = this.advanced[name];
            if (filterData && filterData.type) {
                await this.createFilter(name, filterData);
            }
        }
    }

    async createFilter(name, value) {
        const key = "filter-" + name;

        if (this.getView(key)) {
            return;
        }

        const view = new FilterView({
            name: name,
            model: this.model,
            params: value || {},
        });

        await this.assignView(key, view, `.active-filter-body[data-name="${name}"]`);
        await view.render();
    }

    afterRender() {
        super.afterRender();

        this.createFilterViews();

        this.$el.find('[data-role="fieldQuickSearch"]').on("input", (e) => {
            const query = $(e.target).val().toLowerCase();

            this.$el.find(".field-option").each(function () {
                const label = $(this)
                    .find(".option-label")
                    .text()
                    .toLowerCase();
                $(this).toggleClass("hidden", !label.includes(query));
            });
        });
    }

    // Action handlers
    actionSelectPreset(data) {
        const name = data.name || "";

        // Update UI
        this.$el
            .find('.filter-option[data-action="selectPreset"]')
            .removeClass("active");
        this.$el
            .find('.filter-option[data-action="selectPreset"] .check-icon')
            .removeClass("visible");

        const $selected = this.$el.find(
            `.filter-option[data-action="selectPreset"][data-name="${name}"]`,
        );
        $selected.addClass("active");
        $selected.find(".check-icon").addClass("visible");

        this.presetName = name || null;
    }

    actionToggleBoolFilter(data) {
        const name = data.name;

        // Toggle value
        this.bool[name] = !this.bool[name];

        // Update UI
        const $option = this.$el.find(`.filter-option[data-name="${name}"]`);
        $option.find(".toggle-switch").toggleClass("active", this.bool[name]);
    }

    async actionAddFieldFilter(data) {
        const name = data.name;

        if (this.pendingFilters && this.pendingFilters[name]) {
            return;
        }

        if (!this.pendingFilters) {
            this.pendingFilters = {};
        }
        this.pendingFilters[name] = true;

        const $section = this.$el.find(".active-filters-section");
        const $list = this.$el.find(".active-filters-list");

        if ($section.hasClass("hidden")) {
            $section.removeClass("hidden");
        }

        const label = this.fieldFilterTranslations[name] || name;
        const itemHtml = `
            <div class="active-filter-item" data-name="${name}">
                <div class="active-filter-body" data-name="${name}"></div>
            </div>
        `;

        $list.append(itemHtml);

        await this.createFilter(name, {});

        this.$el
            .find(`.field-option[data-name="${name}"]`)
            .addClass("has-filter");
    }

    actionRemoveAdvancedFilter(data) {
        const name = data.name;

        delete this.advanced[name];
        
        // Also remove from pending filters
        if (this.pendingFilters) {
            delete this.pendingFilters[name];
        }

        // Remove view
        this.clearView("filter-" + name);

        // Remove from DOM
        this.$el.find(`.active-filter-item[data-name="${name}"]`).remove();

        // Update field option
        this.$el
            .find(`.field-option[data-name="${name}"]`)
            .removeClass("has-filter");

        // Hide section if empty (check both advanced and pending)
        const hasAdvanced = Object.keys(this.advanced).length > 0;
        const hasPending = this.pendingFilters && Object.keys(this.pendingFilters).length > 0;
        if (!hasAdvanced && !hasPending) {
            this.$el.find(".active-filters-section").addClass("hidden");
        }
    }

    actionResetFilters() {
        this.bool = {};
        this.advanced = {};
        this.presetName = null;

        // Clear all filter views
        for (const name in this.options.advanced) {
            this.clearView("filter-" + name);
        }

        // Trigger apply with empty filters
        this.trigger("apply", {
            bool: {},
            advanced: {},
            presetName: null,
        });

        this.close();
    }

    async actionApplyFilters() {
        const advanced = {};

        const allFilterNames = [
            ...Object.keys(this.advanced || {}),
            ...Object.keys(this.pendingFilters || {}),
        ];

        for (const name of allFilterNames) {
            if (advanced[name]) continue;

            const filterView = this.getView("filter-" + name);

            if (!filterView) {
                console.warn("Filter view not found for:", name);
                continue;
            }

            const fieldView = filterView.getFieldView ? filterView.getFieldView() : filterView.getView("field");

            if (!fieldView) {
                console.warn("Field view not found for:", name);
                continue;
            }

            if (typeof fieldView.fetchSearch !== "function") {
                console.warn("fetchSearch not available for:", name);
                continue;
            }

            const searchData = fieldView.fetchSearch();

            if (searchData) {
                advanced[name] = searchData;
            }
        }

        this.trigger("apply", {
            bool: this.bool,
            advanced: advanced,
            presetName: this.presetName,
        });

        this.close();
    }

    actionCloseModal() {
        this.close();
    }
}

export default MobileFilterModal;

