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

import SearchView from "views/record/search";

/**
 * Mobile-optimized search view with:
 * - Full-width search input
 * - Filter modal instead of dropdown
 * - Touch-friendly interactions
 */
class MobileSearchView extends SearchView {
    template = "global:record/search-mobile";

    // Mobile-specific settings
    isMobile = true;
    filterModalView = "views/modals/filter";

    // Event delegation for mobile actions
    events = {
        'click [data-action="showFilterModal"]': function () {
            this.actionShowFilterModal();
        },
        'click [data-action="clearSearch"]': function () {
            this.actionClearSearch();
        },
        ...SearchView.prototype.events
    };

    setup() {
        super.setup();

        // Clean up any advanced filters that don't have a type
        // This can happen if filters were saved without proper data
        if (this.advanced) {
            const cleanAdvanced = {};
            for (const name in this.advanced) {
                if (this.advanced[name] && this.advanced[name].type) {
                    cleanAdvanced[name] = this.advanced[name];
                }
            }
            this.advanced = cleanAdvanced;
        }

        // Detect if we're actually on mobile
        this.isMobile = this.detectMobile();

        // If not mobile, use parent template
        if (!this.isMobile) {
            this.template = "record/search";
        }

        // Additional mobile-specific setup
        if (this.isMobile) {
            this.setupMobileHandlers();
        }
    }

    detectMobile() {
        // Check screen width
        const width = window.innerWidth || document.documentElement.clientWidth;
        return width <= 767;
    }

    setupMobileHandlers() {
        // Handle window resize
        let resizeTimeout;
        $(window).on("resize.mobilesearch", () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                const wasMobile = this.isMobile;
                this.isMobile = this.detectMobile();

                if (wasMobile !== this.isMobile) {
                    this.template = this.isMobile
                        ? "global:record/search-mobile"
                        : "record/search";
                    this.reRender();
                }
            }, 250);
        });

        // Clean up on remove
        this.on("remove", () => {
            $(window).off("resize.mobilesearch");
        });
    }

    data() {
        const data = super.data();

        if (this.isMobile) {
            data.hasActiveFilters = this.hasActiveFilters();
            data.activeFilterCount = this.getActiveFilterCount();
        }

        return data;
    }

    /**
     * Check if there are any active filters
     */
    hasActiveFilters() {
        // Check preset filter
        if (this.presetName && this.presetName !== "") {
            return true;
        }

        // Check bool filters
        if (this.bool) {
            for (const key in this.bool) {
                if (this.bool[key]) {
                    return true;
                }
            }
        }

        // Check advanced filters
        if (this.advanced) {
            for (const key in this.advanced) {
                if (
                    this.advanced[key] &&
                    Object.keys(this.advanced[key]).length > 0
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get count of active filters
     */
    getActiveFilterCount() {
        let count = 0;

        // Preset filter
        if (this.presetName && this.presetName !== "") {
            count++;
        }

        // Bool filters
        if (this.bool) {
            for (const key in this.bool) {
                if (this.bool[key]) {
                    count++;
                }
            }
        }

        // Advanced filters
        if (this.advanced) {
            for (const key in this.advanced) {
                if (
                    this.advanced[key] &&
                    Object.keys(this.advanced[key]).length > 0
                ) {
                    count++;
                }
            }
        }

        return count;
    }

    /**
     * Show filter modal (mobile-specific)
     */
    actionShowFilterModal() {
        // Clean up advanced filters before passing to modal
        // Only include filters with valid type
        const cleanAdvanced = {};
        for (const name in this.advanced) {
            if (this.advanced[name] && this.advanced[name].type) {
                cleanAdvanced[name] = this.advanced[name];
            }
        }

        this.createView(
            "filterModal",
            "global:views/modals/mobile-filter",
            {
                entityType: this.entityType,
                model: this.model,
                presetFilterList: this.getPresetFilterList(),
                boolFilterList: this.boolFilterList,
                bool: this.bool,
                advanced: cleanAdvanced,
                presetName: this.presetName,
                fieldFilterList: this.fieldFilterList,
                fieldFilterTranslations: this.fieldFilterTranslations,
                primaryFiltersDisabled: this.options.primaryFiltersDisabled,
            },
            (view) => {
                view.render();

                this.listenToOnce(view, "apply", (data) => {
                    this.applyModalFilters(data);
                });
            },
        );
    }

    /**
     * Override fetch to handle mobile search without filter views
     */
    fetch() {
        // Get text filter from input
        this.textFilter = (this.$el.find('input[data-name="textFilter"]').val() || '').trim();
        
        // For mobile, we don't fetch from filter views - they're in the modal
        // Advanced filters are already set when modal applies them
    }

    /**
     * Override updateSearch to ensure all filters have type before sending
     */
    updateSearch() {
        // Clean up advanced filters one more time before sending to search manager
        const cleanAdvanced = {};
        for (const name in this.advanced) {
            if (this.advanced[name] && this.advanced[name].type) {
                cleanAdvanced[name] = this.advanced[name];
            }
        }

        this.searchManager.set({
            textFilter: this.textFilter,
            advanced: cleanAdvanced,
            bool: this.bool,
            presetName: this.presetName,
            primary: this.primary,
        });
    }

    search() {
        this.fetch();
        this.updateSearch();
        this.updateCollection();
        this.controlResetButtonVisibility();
        this.storeTextSearch();
    }

    /**
     * Apply filters from modal
     */
    applyModalFilters(data) {
        console.log("applyModalFilters received:", data);
        
        this.bool = data.bool || {};
        
        const rawAdvanced = data.advanced || {};
        this.advanced = {};
        for (const name in rawAdvanced) {
            if (rawAdvanced[name] && rawAdvanced[name].type) {
                this.advanced[name] = rawAdvanced[name];
            }
        }
        
        this.presetName = data.presetName || null;

        console.log("advanced filters:", this.advanced);
        
        this.fetch();
        this.updateSearch();
        this.updateCollection();
        this.reRender();
    }

    /**
     * Clear search text
     */
    actionClearSearch() {
        this.textFilter = "";
        this.$el.find('input[data-name="textFilter"]').val("");
        this.fetch();
        this.updateSearch();
        this.updateCollection();
        this.reRender();
    }

    afterRender() {
        super.afterRender();

        if (this.isMobile) {
            // Focus search input on render
            const $input = this.$el.find('input[data-name="textFilter"]');

            // Handle search on enter
            $input.on("keypress.mobilesearch", (e) => {
                if (e.which === 13) {
                    e.preventDefault();
                    this.textFilter = $input.val().trim();
                    this.fetch();
                    this.updateSearch();
                    this.updateCollection();
                }
            });

            // Handle search on input delay (live search)
            let inputTimeout;
            $input.on("input.mobilesearch", () => {
                clearTimeout(inputTimeout);
                inputTimeout = setTimeout(() => {
                    this.textFilter = $input.val().trim();
                    this.fetch();
                    this.updateSearch();
                }, 500);
            });
        }
    }
}

export default MobileSearchView;

