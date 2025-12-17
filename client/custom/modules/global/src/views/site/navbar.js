/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import NavbarSiteView from "views/site/navbar";

/**
 * Custom navbar view that injects starred Reports into a "Lists" divider.
 */
class CustomNavbarSiteView extends NavbarSiteView {
    /**
     * @private
     * @type {Object[]}
     */
    starredReports = [];

    /**
     * @private
     * @type {boolean}
     */
    starredReportsLoaded = false;

    setup() {
        super.setup();

        // Fetch starred reports asynchronously
        this.loadStarredReports();
    }

    /**
     * @private
     */
    async loadStarredReports() {
        try {
            // Check if Report entity exists and user has access
            if (!this.getMetadata().get(["scopes", "Report"])) {
                this.starredReportsLoaded = true;
                return;
            }

            if (!this.getAcl().check("Report", "read")) {
                this.starredReportsLoaded = true;
                return;
            }

            // Fetch starred reports using the primary filter
            const response = await Espo.Ajax.getRequest("Report", {
                select: ["id", "name"],
                primaryFilter: "starred",
                maxSize: 20,
                orderBy: "name",
                order: "asc",
            });

            this.starredReports = response.list || [];
            this.starredReportsLoaded = true;

            // Re-render if already rendered
            if (this.isRendered()) {
                this.setupTabDefsList();
                this.reRender();
            }
        } catch (e) {
            console.error("Failed to load starred reports:", e);
            this.starredReportsLoaded = true;
        }
    }

    /**
     * Override getTabList to inject starred reports into the "Lists" divider.
     * @return {(Object|string)[]}
     */
    getTabList() {
        const tabList = super.getTabList();

        if (!this.starredReportsLoaded || this.starredReports.length === 0) {
            return tabList;
        }

        // Find the "Lists" divider (looking for $Lists text)
        const listsIndex = tabList.findIndex(
            (item) =>
                typeof item === "object" &&
                item.type === "divider" &&
                item.text === "$Lists"
        );

        if (listsIndex === -1) {
            // If no Lists divider exists, create one with the starred reports
            return this.injectListsDividerWithReports(tabList);
        }

        // Inject starred reports after the Lists divider
        return this.injectReportsAfterDivider(tabList, listsIndex);
    }

    /**
     * @private
     * @param {Array} tabList
     * @return {Array}
     */
    injectListsDividerWithReports(tabList) {
        // Find the best position to insert - after Records divider or at the beginning
        let insertIndex = 0;

        const recordsIndex = tabList.findIndex(
            (item) =>
                typeof item === "object" &&
                item.type === "divider" &&
                item.text === "$Records"
        );

        if (recordsIndex !== -1) {
            // Find the end of the Records section (next divider or end)
            for (let i = recordsIndex + 1; i < tabList.length; i++) {
                const item = tabList[i];
                if (typeof item === "object" && item.type === "divider") {
                    insertIndex = i;
                    break;
                }
            }
            if (insertIndex === 0) {
                insertIndex = tabList.length;
            }
        }

        // Create Lists divider
        const listsDivider = {
            type: "divider",
            text: "$Lists",
            id: "starred-reports-divider",
        };

        // Create report URL items
        const reportItems = this.createReportUrlItems();

        // Insert divider and reports
        const newTabList = [...tabList];
        newTabList.splice(insertIndex, 0, listsDivider, ...reportItems);

        return newTabList;
    }

    /**
     * @private
     * @param {Array} tabList
     * @param {number} listsIndex
     * @return {Array}
     */
    injectReportsAfterDivider(tabList, listsIndex) {
        const reportItems = this.createReportUrlItems();

        const newTabList = [...tabList];
        newTabList.splice(listsIndex + 1, 0, ...reportItems);

        return newTabList;
    }

    /**
     * @private
     * @return {Object[]}
     */
    createReportUrlItems() {
        return this.starredReports.map((report, index) => ({
            type: "url",
            text: report.name,
            url: "#Report/show/" + report.id,
            iconClass: "fas fa-chart-bar",
            color: null,
            aclScope: "Report",
            onlyAdmin: false,
            id: "starred-report-" + report.id,
        }));
    }
}

export default CustomNavbarSiteView;

