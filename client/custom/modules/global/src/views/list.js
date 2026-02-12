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

import ListView from "views/list";

/**
 * Custom list view with mobile-optimized search
 */
class CustomListView extends ListView {
    /**
     * Default record view - can be overridden per entity in clientDefs
     */
    recordView = "global:views/record/list";

    /**
     * Get the search view name based on device width
     * @returns {string}
     */
    getSearchView() {
        const width = window.innerWidth || document.documentElement.clientWidth;

        if (width <= 767) {
            return "global:views/record/search-mobile";
        }

        return this.searchView;
    }

    /**
     * Override createSearchView to use dynamic search view
     * @return {Promise<module:view>}
     * @protected
     */
    createSearchView() {
        const searchView = this.getSearchView();

        return this.createView(
            "search",
            searchView,
            {
                collection: this.collection,
                fullSelector: "#main > .search-container",
                searchManager: this.searchManager,
                scope: this.scope,
                viewMode: this.viewMode,
                viewModeList: this.viewModeList,
                isWide: true,
                disableSavePreset: !!this._primaryFilter,
                primaryFiltersDisabled: !!this._primaryFilter,
            },
            (view) => {
                this.listenTo(view, "reset", () => this.resetSorting());

                if (this.viewModeList.length > 1) {
                    this.listenTo(view, "change-view-mode", (mode) =>
                        this.switchViewMode(mode)
                    );
                }
            }
        );
    }
}

export default CustomListView;
