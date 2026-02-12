/**
 * Custom detail view that adds entity icons to relationship tabs.
 */

import DetailRecordView from "views/record/detail";

class CustomDetailRecordView extends DetailRecordView {
    template = "global:record/detail";

    /**
     * Mobile tab grouping configuration
     */
    mobileTabBreakpoint = 767;
    tabDrawerOpen = false;
    drawerTabs = [];

    /**
     * @inheritDoc
     */
    events = {
        ...DetailRecordView.prototype.events,
        // Override parent's generic button click to skip the "More" button
        "click .middle-tabs > button": function (e) {
            const $btn = $(e.currentTarget);
            if ($btn.attr("data-role") === "tab-more-btn") {
                return;
            }
            const tab = parseInt($btn.attr("data-tab"));
            this.selectTab(tab);
        },
        'click [data-role="tab-more-btn"]': function () {
            this.toggleTabDrawer();
        },
        'click [data-role="tab-drawer-backdrop"]': function () {
            this.closeTabDrawer();
        },
        'click [data-role="tab-drawer-close"]': function () {
            this.closeTabDrawer();
        },
        'click [data-role="tab-drawer-item"]': function (e) {
            const tab = parseInt($(e.currentTarget).attr("data-tab"));
            this.closeTabDrawer();
            this.selectTab(tab);
        },
    };

    /**
     * @inheritDoc
     */
    afterRender() {
        super.afterRender();
        this.initMobileTabGrouping();
    }

    /**
     * Initialize mobile tab grouping functionality
     * @private
     */
    initMobileTabGrouping() {
        if (!this.hasTabs()) {
            return;
        }

        this.$tabContainer = this.$el.find('[data-role="middle-tabs"]');
        this.$tabMoreBtn = this.$el.find('[data-role="tab-more-btn"]');
        this.$tabDrawer = this.$el.find('[data-role="tab-drawer"]');
        this.$tabDrawerBackdrop = this.$el.find(
            '[data-role="tab-drawer-backdrop"]',
        );
        this.$tabDrawerContent = this.$el.find(
            '[data-role="tab-drawer-content"]',
        );

        // Wait for DOM to be fully rendered before calculating
        // Use multiple timeouts to handle different rendering phases
        setTimeout(() => this.calculateTabOverflow(), 0);
        setTimeout(() => this.calculateTabOverflow(), 100);
        setTimeout(() => this.calculateTabOverflow(), 500);

        // Recalculate on resize
        this.listenToResize();
    }

    /**
     * Listen to window resize events
     * @private
     */
    listenToResize() {
        const debouncedCalculate = _.debounce(() => {
            this.calculateTabOverflow();
        }, 150);

        $(window).on(`resize.mobile-tabs-${this.cid}`, debouncedCalculate);

        this.once("remove", () => {
            $(window).off(`resize.mobile-tabs-${this.cid}`);
        });
    }

    /**
     * Calculate which tabs fit and which should go to drawer
     * @private
     */
    calculateTabOverflow() {
        if (!this.$tabContainer || !this.$tabContainer.length) {
            return;
        }

        const windowWidth = $(window).width();
        const isMobile = windowWidth <= this.mobileTabBreakpoint;

        if (!isMobile) {
            // Reset all tabs to visible on desktop
            this.$tabContainer
                .find('[data-role="middle-tab"]')
                .removeClass("tab-in-drawer");
            this.$tabMoreBtn.addClass("hidden");
            this.closeTabDrawer();
            return;
        }

        const containerWidth = this.$tabContainer.width();
        const moreBtnWidth = 80; // Approximate width of "More" button
        const availableWidth = containerWidth - moreBtnWidth - 16; // 16px for gap/padding

        const $tabs = this.$tabContainer.find(
            '[data-role="middle-tab"]:not(.hidden)',
        );

        // Reset all tabs to visible so we can measure their real widths
        // (tabs with tab-in-drawer have display:none and report 0 width)
        $tabs.removeClass("tab-in-drawer");
        // Force synchronous reflow so outerWidth returns the correct value
        this.$tabContainer[0].offsetWidth;

        let currentWidth = 0;
        const drawerTabs = [];
        let activeTabInDrawer = false;

        $tabs.each((index, tab) => {
            const $tab = $(tab);
            const tabWidth = $tab.outerWidth(true);
            const tabIndex = parseInt($tab.attr("data-tab"));
            const isActive = $tab.hasClass("active");

            // Check if this tab would fit
            if (currentWidth + tabWidth <= availableWidth) {
                currentWidth += tabWidth;
            } else {
                // This tab needs to go in the drawer
                $tab.addClass("tab-in-drawer");
                drawerTabs.push({
                    index: tabIndex,
                    label: $tab.attr("data-label"),
                    icon: $tab.attr("data-icon"),
                    iconColor: $tab.attr("data-icon-color"),
                    isActive: isActive,
                });

                if (isActive) {
                    activeTabInDrawer = true;
                }
            }
        });

        // If active tab is in drawer, we need to show it and move another one
        if (activeTabInDrawer && drawerTabs.length > 0) {
            // Find the last visible tab and move it to drawer
            const $visibleTabs = $tabs.not(".tab-in-drawer");
            const $lastVisible = $visibleTabs.last();

            if ($lastVisible.length) {
                const lastIndex = parseInt($lastVisible.attr("data-tab"));
                $lastVisible.addClass("tab-in-drawer");

                // Remove the active tab from drawer and make it visible
                const activeTabIndex = drawerTabs.findIndex((t) => t.isActive);
                if (activeTabIndex !== -1) {
                    const activeTab = drawerTabs[activeTabIndex];
                    const $activeTabElement = $tabs.filter(
                        `[data-tab="${activeTab.index}"]`,
                    );
                    $activeTabElement.removeClass("tab-in-drawer");
                    drawerTabs.splice(activeTabIndex, 1);

                    // Add the last visible tab to drawer data
                    drawerTabs.unshift({
                        index: lastIndex,
                        label: $lastVisible.attr("data-label"),
                        icon: $lastVisible.attr("data-icon"),
                        iconColor: $lastVisible.attr("data-icon-color"),
                        isActive: false,
                    });
                }
            }
        }

        this.drawerTabs = drawerTabs;

        // Show/hide More button based on drawer tabs
        if (drawerTabs.length > 0) {
            this.$tabMoreBtn.removeClass("hidden");
        } else {
            this.$tabMoreBtn.addClass("hidden");
        }

        // Update drawer content
        this.renderDrawerContent();
    }

    /**
     * Render the drawer content with overflow tabs
     * @private
     */
    renderDrawerContent() {
        if (!this.$tabDrawerContent || !this.$tabDrawerContent.length) {
            return;
        }

        const html = this.drawerTabs
            .map((tab) => {
                const activeClass = tab.isActive ? "active" : "";
                const iconHtml = tab.icon
                    ? `<span class="icon ${tab.icon}"${tab.iconColor ? ` style="color: ${tab.iconColor}"` : ""}></span>`
                    : "";

                return `
                <button class="tab-drawer-item ${activeClass}" data-role="tab-drawer-item" data-tab="${tab.index}">
                    ${iconHtml}
                    <span>${tab.label}</span>
                </button>
            `;
            })
            .join("");

        this.$tabDrawerContent.html(html);
    }

    /**
     * Toggle the tab drawer open/closed
     * @private
     */
    toggleTabDrawer() {
        if (this.tabDrawerOpen) {
            this.closeTabDrawer();
        } else {
            this.openTabDrawer();
        }
    }

    /**
     * Open the tab drawer
     * @private
     */
    openTabDrawer() {
        if (!this.$tabDrawer || !this.$tabDrawer.length) {
            return;
        }

        this.tabDrawerOpen = true;
        this.$tabDrawer.addClass("open");
        this.$tabDrawerBackdrop.addClass("visible");
        $("body").addClass("tab-drawer-open");
    }

    /**
     * Close the tab drawer
     * @private
     */
    closeTabDrawer() {
        if (!this.$tabDrawer || !this.$tabDrawer.length) {
            return;
        }

        this.tabDrawerOpen = false;
        this.$tabDrawer.removeClass("open");
        this.$tabDrawerBackdrop.removeClass("visible");
        $("body").removeClass("tab-drawer-open");
    }

    /**
     * @override
     * Override selectTab to recalculate overflow after tab change
     */
    selectTab(tab) {
        super.selectTab(tab);

        // Recalculate overflow after tab change (on mobile)
        if (this.hasTabs() && $(window).width() <= this.mobileTabBreakpoint) {
            // Use setTimeout to wait for the DOM to update
            setTimeout(() => {
                this.calculateTabOverflow();
            }, 0);
        }
    }

    /**
     * @override
     * @return {{label: string, icon?: string, iconColor?: string}[]}
     */
    getMiddleTabDataList() {
        const currentTab = this.currentTab;
        const panelDataList = this.middlePanelDefsList;

        return panelDataList
            .filter((item, i) => i === 0 || item.tabBreak)
            .map((item, i) => {
                let label = item.tabLabel;
                let hidden = false;
                let icon = null;
                let iconColor = null;

                if (i > 0) {
                    hidden =
                        panelDataList
                            .filter((panel) => panel.tabNumber === i)
                            .findIndex(
                                (panel) =>
                                    !this.recordHelper.getPanelStateParam(
                                        panel.name,
                                        "hidden",
                                    ),
                            ) === -1;
                }

                if (!label) {
                    label =
                        i === 0
                            ? this.translate("Overview")
                            : (i + 1).toString();
                } else if (label.substring(0, 7) === "$label:") {
                    label = this.translate(
                        label.substring(7),
                        "labels",
                        this.scope,
                    );
                } else if (label[0] === "$") {
                    label = this.translate(
                        label.substring(1),
                        "tabs",
                        this.scope,
                    );
                }

                // Try to get entity icon for relationship tabs
                const entityType = this.getTabEntityType(item);
                if (entityType) {
                    icon = this.getMetadata().get([
                        "clientDefs",
                        entityType,
                        "iconClass",
                    ]);
                    iconColor = this.getMetadata().get([
                        "clientDefs",
                        entityType,
                        "color",
                    ]);
                }

                return {
                    label: label,
                    isActive: currentTab === i,
                    hidden: hidden,
                    icon: icon,
                    iconColor: iconColor,
                };
            });
    }

    /**
     * Get the entity type for a tab panel if it contains a relationship-list field.
     *
     * @param {Object} panel The panel definition from middlePanelDefsList
     * @return {string|null} The entity type or null
     */
    getTabEntityType(panel) {
        // Check if panel has a tabEntityType explicitly defined
        if (panel.tabEntityType) {
            return panel.tabEntityType;
        }

        // Find the original layout item by name to get rows
        const layoutItem = this.findLayoutItem(panel.name);
        if (!layoutItem) {
            return null;
        }

        // Look for relationship-list field in rows
        if (!layoutItem.rows || !Array.isArray(layoutItem.rows)) {
            return null;
        }

        for (const row of layoutItem.rows) {
            if (!Array.isArray(row)) continue;

            for (const cell of row) {
                if (!cell) continue;

                // Check if it's a relationship-list view
                const viewName = cell.view || "";
                if (
                    viewName.includes("relationship-list") ||
                    viewName.includes("views/fields/relationship-list")
                ) {
                    // Get the link from options
                    const link = cell.options?.link || cell.link;
                    if (link) {
                        // Get the foreign entity type from link definition
                        const linkDefs = this.model.defs?.links?.[link];
                        if (linkDefs?.entity) {
                            return linkDefs.entity;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find a layout item by panel name in the original detailLayout.
     *
     * @param {string} panelName The panel name
     * @return {Object|null} The layout item or null
     */
    findLayoutItem(panelName) {
        if (!this.detailLayout || !Array.isArray(this.detailLayout)) {
            return null;
        }

        for (let i = 0; i < this.detailLayout.length; i++) {
            const item = this.detailLayout[i];
            const itemName = item.name || "panel-" + i.toString();

            if (itemName === panelName) {
                return item;
            }
        }

        return null;
    }
}

export default CustomDetailRecordView;
