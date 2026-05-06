/**
 * Custom detail view that adds entity icons to relationship tabs
 * and supports flexible grid panel layouts via `gridRow` in detail.json.
 */

import DetailRecordView from "views/record/detail";

class CustomDetailRecordView extends DetailRecordView {
    template = "global:record/detail";
    middleView = "global:views/record/detail-middle";
    bottomView = "global:views/record/detail-bottom";

    /**
     * Grid layout: maps panel name -> gridRow number.
     * Populated from detail.json `gridRow` properties.
     * @private
     */
    _gridRowMap = null;

    /**
     * Grid layout: list of panel names that come from the bottom container.
     * @private
     */
    _bottomGridPanelNames = null;

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
        "click .button-container .action": function (e) {
            const target = e.currentTarget;

            let actionItems = undefined;

            if (target.classList.contains("detail-action-item")) {
                actionItems = [...this.buttonList, ...this.dropdownItemList];
            } else if (target.classList.contains("edit-action-item")) {
                actionItems = [...this.buttonEditList, ...this.dropdownEditItemList];
            }

            Espo.Utils.handleAction(this, e.originalEvent, target, {
                actionItems: actionItems,
            });
        },
        'click [data-action="showMoreDetailPanels"]': function () {
            this.showMoreDetailPanels();
        },
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
     * @override
     * Provide collapse data required by record layout template.
     */
    createMiddleView(callback) {
        const el = this.getSelector() || "#" + this.id;

        this.waitForView("middle");

        this.getGridLayout((layout) => {
            if (
                this.hasTabs() &&
                this.options.isReturn &&
                this.isStoredTabForThisRecord()
            ) {
                this.selectStoredTab();
            }

            this.createView(
                "middle",
                this.middleView,
                {
                    model: this.model,
                    scope: this.scope,
                    type: this.type,
                    layoutDefs: layout,
                    fullSelector: el + " .middle",
                    layoutData: {
                        model: this.model,
                        hiddenPanels: this.recordHelper.getHiddenPanels(),
                        collapsedPanels: {},
                    },
                    recordHelper: this.recordHelper,
                    recordViewObject: this,
                    panelFieldListMap: this.panelFieldListMap,
                },
                callback,
            );
        });
    }

    /**
     * @inheritDoc
     */
    afterRender() {
        super.afterRender();
        this.initMobileTabGrouping();

        // Grid layout: read gridRow from layout and apply.
        setTimeout(() => {
            this._readGridRowFromLayout();
            this._injectGridStyles();
            this.applyGridLayout();
            this._applyPanelIcons();

            // If there are bottom panels to grid, wait and re-apply.
            if (this._bottomGridPanelNames && this._bottomGridPanelNames.length > 0) {
                this._waitForBottomGridPanels();
            }
        }, 0);
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
        // If grid layout is active, use broader selectors for tab switching
        // instead of super.selectTab which uses direct child selectors.
        if (this._gridRowMap && Object.keys(this._gridRowMap).length > 0) {
            this.currentTab = tab;
            $('.popover.in').removeClass('in');

            this.whenRendered().then(() => {
                this.$el.find('.middle-tabs > button').removeClass('active');
                this.$el.find(`.middle-tabs > button[data-tab="${tab}"]`).addClass('active');

                // Broad selector for nested panels in grid columns.
                this.$el.find('.middle .panel[data-tab]').addClass('tab-hidden');
                this.$el.find(`.middle .panel[data-tab="${tab}"]`).removeClass('tab-hidden');

                this._syncGridRowVisibility();
                this.adjustMiddlePanels();
                this._stripGridPanelClasses();
                this.recordHelper.trigger('panel-show');
            });

            this.storeTab();
        } else {
            super.selectTab(tab);
        }

        // Recalculate overflow after tab change (on mobile)
        if (this.hasTabs() && $(window).width() <= this.mobileTabBreakpoint) {
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

                if (item.tabIconClass) {
                    icon = item.tabIconClass;
                    iconColor = item.tabIconColor || null;
                } else {
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
    findLayoutItem(panelName) {
        if (!Array.isArray(this.detailLayout)) {
            return null;
        }

        for (const [index, item] of this.detailLayout.entries()) {
            const name = item.name || `panel-${index}`;

            if (name === panelName) {
                return item;
            }
        }

        return null;
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

        if (layoutItem.tabEntityType) {
            return layoutItem.tabEntityType;
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
     * Apply panel icons from layout `panelIconClass` property.
     * Injects an icon span into `.panel-title` after the existing collapse chevron,
     * using the same style as `relationship-list-entity-icon` / `scope-icon`.
     * @private
     */
    _applyPanelIcons() {
        if (!Array.isArray(this.detailLayout)) {
            return;
        }

        const $middle = this.$el.find('.middle').first();

        if (!$middle.length) {
            return;
        }

        this.detailLayout.forEach((item, index) => {
            if (!item || !item.panelIconClass) {
                return;
            }

            const name = item.name || `panel-${index}`;
            const $panel = $middle.find(`.panel[data-name="${name}"]`).first();

            if (!$panel.length) {
                return;
            }

            const $title = $panel.find('> .panel-heading > .panel-title').first();

            if (!$title.length || $title.find('> .panel-icon').length) {
                return;
            }

            const iconColor = item.panelIconColor
                ? ` style="color: ${item.panelIconColor}"`
                : '';

            const $icon = $(`<span class="panel-icon ${item.panelIconClass}"${iconColor}></span>`);

            // Insert icon right after the existing chevron.
            const $chevron = $title.find('> .panel-collapse-chevron').first();

            if ($chevron.length) {
                $chevron.after($icon);
            } else {
                $title.prepend($icon);
            }
        });
    }

    /**
     * Read gridRow definitions from middle layout and side panels metadata.
     * @private
     */
    _readGridRowFromLayout() {
        const gridRowMap = {};
        const gridColSpanMap = {};
        const bottomGridPanelNames = [];

        if (Array.isArray(this.detailLayout)) {
            this.detailLayout.forEach((item, index) => {
                if (!item || typeof item.gridRow === "undefined") {
                    return;
                }

                const row = Number(item.gridRow);

                if (!Number.isFinite(row)) {
                    return;
                }

                const name = item.name || `panel-${index}`;
                gridRowMap[name] = row;

                if (item.gridColSpan) {
                    gridColSpanMap[name] = Number(item.gridColSpan) || 1;
                }
            });
        }

        const sidePanelList =
            this.getMetadata().get([
                "clientDefs",
                this.scope,
                "sidePanels",
                this.type,
            ]) || [];

        if (Array.isArray(sidePanelList)) {
            sidePanelList.forEach((item) => {
                if (!item || !item.name || typeof item.gridRow === "undefined") {
                    return;
                }

                const row = Number(item.gridRow);

                if (!Number.isFinite(row)) {
                    return;
                }

                gridRowMap[item.name] = row;
                bottomGridPanelNames.push(item.name);

                if (item.gridColSpan) {
                    gridColSpanMap[item.name] = Number(item.gridColSpan) || 1;
                }
            });
        }

        this._gridRowMap = gridRowMap;
        this._gridColSpanMap = gridColSpanMap;
        this._bottomGridPanelNames = bottomGridPanelNames;
    }

    /**
     * Inject shared CSS rules for grid-row panel layout.
     * @private
     */
    _injectGridStyles() {
        const styleId = "global-detail-grid-row-style";

        if (document.getElementById(styleId)) {
            return;
        }

        const style = document.createElement("style");
        style.id = styleId;
        style.textContent = `
            .record .panels-grid-row {
                width: 100%;
            }

            .record .panels-grid-row .panels-grid-col {
                display: flex;
                flex-direction: column;
            }

            .record .panels-grid-row .panels-grid-col > .panel {
                margin-bottom: 0;
                flex: 1;
            }

            .record .panels-grid-row .panels-grid-col > .panel .relationship-list-field > .panel {
                display: flex;
                flex-direction: column;
            }

            .record .panels-grid-row .panels-grid-col > .panel .relationship-list-field > .panel > .panel-body {
                flex: 1;
            }

            .record .panels-grid-row .panels-grid-col > .panel.is-collapsed {
                flex: none;
            }

            /* Mirror framework chevron/heading styles for grid-nested panels. */
            .record .panels-grid-row .panels-grid-col > .panel > .panel-heading .panel-collapse-chevron {
                font-size: var(--10px);
                margin-right: var(--6px);
                opacity: 0.55;
                flex-shrink: 0;
                overflow: visible;
                text-overflow: clip;
            }

            @media (max-width: 991px) {
                .record .panels-grid-row {
                    flex-direction: column;
                }
                .record .panels-grid-row .panels-grid-col > .panel {
                    flex: none;
                }
            }
        `;

        document.head.appendChild(style);
    }

    /**
     * Retry grid application while bottom relationship panels are rendering.
     * @private
     */
    _waitForBottomGridPanels() {
        let attempt = 0;
        const maxAttempts = 20;
        const interval = 100;

        const timer = setInterval(() => {
            if (!this.isRendered()) {
                clearInterval(timer);
                return;
            }

            attempt++;
            this.applyGridLayout();

            const $bottom = this.$el.find(".bottom").first();
            const allFound = this._bottomGridPanelNames.every(
                (name) => $bottom.find(`> .panel[data-name="${name}"]`).length > 0,
            );

            if (allFound || attempt >= maxAttempts) {
                clearInterval(timer);
            }
        }, interval);

        this.once("remove", () => clearInterval(timer));
    }

    applyGridLayout() {
        const $middle = this.$el.find('.middle').first();

        if (!$middle.length || !this._gridRowMap) {
            return;
        }

        const gridRowMap = this._gridRowMap;
        const panelNames = Object.keys(gridRowMap);

        if (panelNames.length === 0) {
            return;
        }

        // Clean up any previously created grid rows (for re-render).
        $middle.find('.panels-grid-row').each(function () {
            const $row = $(this);
            $row.find('.panels-grid-col > .panel').each(function () {
                $row.before(this);
            });
            $row.remove();
        });

        // Group panel names by gridRow number.
        const rowGroups = {};

        panelNames.forEach(name => {
            const row = gridRowMap[name];
            if (!rowGroups[row]) rowGroups[row] = [];
            rowGroups[row].push(name);
        });

        // Sort row numbers.
        const rowNumbers = Object.keys(rowGroups).map(Number).sort((a, b) => a - b);

        // For each row group, wrap the panels in a flex row.
        rowNumbers.forEach(rowNum => {
            const names = rowGroups[rowNum];
            const $panels = [];
            let $firstPanel = null;

            names.forEach(name => {
                // Look in .middle first, then .bottom for relationship panels.
                let $panel = $middle.find(`> .panel[data-name="${name}"]`);

                if (!$panel.length && this._bottomGridPanelNames &&
                    this._bottomGridPanelNames.indexOf(name) !== -1) {
                    const $bottom = this.$el.find('.bottom').first();
                    $panel = $bottom.find(`> .panel[data-name="${name}"]`);

                    // Move bottom panel into .middle before the next grid row or at end.
                    if ($panel.length) {
                        $middle.append($panel);
                    }
                }

                if ($panel.length) {
                    $panels.push($panel);
                    if (!$firstPanel) $firstPanel = $panel;
                }
            });

            if ($panels.length <= 1) {
                // Single panel = full width, just apply card styling.
                if ($panels.length === 1) {
                    const $panel = $panels[0];

                    $panel.css({
                        'border-radius': 'var(--panel-border-radius)',
                        'margin-bottom': $panel.hasClass('headered') ? '10px' : '',
                    }).attr('data-grid-styled', '1');
                }
                return;
            }

            // Create flex row.
            const $row = $(`<div class="panels-grid-row" data-grid-row="${rowNum}"></div>`);

            $row.css({
                'display': 'flex',
                'gap': '10px',
                'align-items': 'stretch',
                'margin-bottom': '10px',
            });

            // Insert row where the first panel is.
            $firstPanel.before($row);

            // Move each panel into its own column container.
            $panels.forEach($panel => {
                const $col = $('<div class="panels-grid-col"></div>');
                const panelName = $panel.attr('data-name');
                const colSpan = (this._gridColSpanMap && this._gridColSpanMap[panelName]) || 1;

                $col.css({
                    'flex': String(colSpan),
                    'min-width': '0',
                });

                // Card-like styling.
                $panel.css({
                    'border-radius': 'var(--panel-border-radius)',
                });

                $col.append($panel);
                $row.append($col);
            });
        });

        // Sync tab visibility and strip stacking classes.
        this._syncGridRowVisibility();
        this._stripGridPanelClasses();
    }

    /**
     * Remove stacking classes from grid panels.
     * @private
     */
    _stripGridPanelClasses() {
        const $middle = this.$el.find('.middle').first();

        if (!$middle.length) return;

        $middle.find('.panels-grid-row .panel, .panel[data-grid-styled]').each(function () {
            $(this).removeClass('first in-middle last');
        });
    }

    /**
     * Sync visibility of grid rows based on panel tab-hidden state.
     * @private
     */
    _syncGridRowVisibility() {
        const $middle = this.$el.find('.middle').first();

        $middle.find('.panels-grid-row').each(function () {
            const $row = $(this);
            let allHidden = true;

            $row.find('.panel[data-tab]').each(function () {
                if (!$(this).hasClass('tab-hidden')) {
                    allHidden = false;
                }
            });

            if (allHidden) {
                $row.addClass('tab-hidden').hide();
            } else {
                $row.removeClass('tab-hidden').show();
            }
        });
    }

    /**
     * Override adjustMiddlePanels to use broader selectors
     * that work with panels nested inside grid column containers.
     */
    adjustMiddlePanels() {
        if (!this.isRendered() || !this.$middle || !this.$middle.length) {
            return;
        }

        // Use broader selector for grid-nested panels.
        const $panels = this.$middle.find('.panel[data-tab]');
        const $bottomPanels = this.$bottom ? this.$bottom.find('> .panel') : null;

        $panels
            .removeClass('first')
            .removeClass('last')
            .removeClass('in-middle');

        const $visiblePanels = $panels.filter(':not(.tab-hidden):not(.hidden)');

        $visiblePanels.each((i, el) => {
            const $el = $(el);

            if (i === $visiblePanels.length - 1) {
                if ($bottomPanels && $bottomPanels.first().hasClass('sticked')) {
                    if (i === 0) {
                        $el.addClass('first');
                        return;
                    }
                    $el.addClass('in-middle');
                    return;
                }
                if (i === 0) {
                    return;
                }
                $el.addClass('last');
                return;
            }

            if (i === 0) {
                $el.addClass('first');
            } else {
                $el.addClass('in-middle');
            }
        });
    }
}

export default CustomDetailRecordView;
