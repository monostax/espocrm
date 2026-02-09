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
 * Custom navbar view that:
 * 1. Filters out Conversas menu items for users without chatSsoUrl
 * 2. Injects starred Reports into a "Lists" divider
 * Uses appParams from the /api/v1/App/user response.
 */
class CustomNavbarSiteView extends NavbarSiteView {
    /**
     * Check if the current user has Chatwoot access (valid chatSsoUrl).
     * @private
     * @return {boolean}
     */
    hasChatwootAccess() {
        return !!this.getHelper().getAppParam("chatSsoUrl");
    }

    /**
     * Get starred reports from appParams (loaded with the initial App/user request).
     * @private
     * @return {Object[]}
     */
    getStarredReports() {
        return this.getHelper().getAppParam("starredReports") || [];
    }

    /**
     * Filter out Conversas menu items if user doesn't have chatSsoUrl.
     * Conversas items are identified by:
     * - Divider with text "$Conversations" (id: 853524)
     * - URL items with IDs matching pattern 8535xx
     * @private
     * @param {Array} tabList
     * @return {Array}
     */
    filterConversasItems(tabList) {
        if (this.hasChatwootAccess()) {
            return tabList;
        }

        return tabList.filter((item) => {
            // Keep non-object items (like scope strings)
            if (!item || typeof item !== "object") {
                return true;
            }

            // Remove Conversas divider
            if (item.type === "divider" && item.text === "$Conversations") {
                return false;
            }

            // Remove conversation URL items (by ID pattern 8535xx)
            if (item.type === "url" && item.id && /^8535\d{2}$/.test(item.id)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Override getTabList to:
     * 1. Filter Conversas items based on chatSsoUrl
     * 2. Inject starred reports into the "Lists" divider
     * @return {(Object|string)[]}
     */
    getTabList() {
        const tabList = super.getTabList();

        // First, filter out Conversas items if user doesn't have chatSsoUrl
        const filteredList = this.filterConversasItems(tabList);

        const starredReports = this.getStarredReports();

        if (starredReports.length === 0) {
            return filteredList;
        }

        // Find the "Lists" divider (looking for $Lists text)
        const listsIndex = filteredList.findIndex(
            (item) =>
                typeof item === "object" &&
                item.type === "divider" &&
                item.text === "$Lists",
        );

        if (listsIndex === -1) {
            // If no Lists divider exists, create one with the starred reports
            return this.injectListsDividerWithReports(filteredList);
        }

        // Inject starred reports after the Lists divider
        return this.injectReportsAfterDivider(filteredList, listsIndex);
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
                item.text === "$Records",
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
        return this.getStarredReports().map((report) => ({
            type: "url",
            text: report.name,
            url: report.url || "#Report/show/" + report.id,
            iconClass: "ti ti-list",
            color: null,
            aclScope: "Report",
            onlyAdmin: false,
            id: "starred-report-" + report.id,
        }));
    }
}

export default CustomNavbarSiteView;
