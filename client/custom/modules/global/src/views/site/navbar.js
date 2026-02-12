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
import $ from "jquery";

/**
 * Custom navbar view that:
 * 1. Filters out Conversas menu items for users without chatSsoUrl
 * 2. Implements Linear.app-style mobile drawer navigation
 * Uses appParams from the /api/v1/App/user response.
 */
class CustomNavbarSiteView extends NavbarSiteView {
    /** @private */
    isMobileDrawerOpen = false;

    /**
     * Check if the current user has Chatwoot access (valid chatSsoUrl).
     * @private
     * @return {boolean}
     */
    hasChatwootAccess() {
        return !!this.getHelper().getAppParam("chatSsoUrl");
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
     * Override getTabList to filter Conversas items based on chatSsoUrl.
     * @return {(Object|string)[]}
     */
    getTabList() {
        const tabList = super.getTabList();

        return this.filterConversasItems(tabList);
    }

    // =========================================================================
    // Mobile Drawer Navigation (Linear.app-style)
    // =========================================================================

    /**
     * @private
     * @return {boolean}
     */
    isMobileScreen() {
        const smallScreenWidth =
            this.getThemeManager().getParam("screenWidthXs") || 768;

        return window.innerWidth < smallScreenWidth;
    }

    /**
     * Override toggleCollapsable to use drawer on mobile.
     */
    toggleCollapsable() {
        if (this.isMobileScreen()) {
            if (this.isMobileDrawerOpen) {
                this.closeMobileDrawer();
            } else {
                this.openMobileDrawer();
            }

            return;
        }

        super.toggleCollapsable();
    }

    /**
     * Open the mobile drawer.
     * @private
     */
    openMobileDrawer() {
        this.isMobileDrawerOpen = true;

        document.body.classList.add("mobile-drawer-open");

        // Create backdrop
        this.$mobileDrawerBackdrop = $("<div>")
            .addClass("mobile-drawer-backdrop")
            .on("click", () => this.closeMobileDrawer())
            .appendTo(document.body);

        // Trigger the slide-in animation on the next frame
        requestAnimationFrame(() => {
            this.$mobileDrawerBackdrop.addClass("visible");
        });
    }

    /**
     * Close the mobile drawer.
     * @private
     */
    closeMobileDrawer() {
        if (!this.isMobileDrawerOpen) {
            return;
        }

        this.isMobileDrawerOpen = false;

        document.body.classList.remove("mobile-drawer-open");

        if (this.$mobileDrawerBackdrop) {
            this.$mobileDrawerBackdrop.remove();
            this.$mobileDrawerBackdrop = null;
        }
    }

    /**
     * Override xsCollapse to close drawer instead of just hiding collapsable.
     */
    xsCollapse() {
        if (this.isMobileDrawerOpen) {
            this.closeMobileDrawer();

            return;
        }

        super.xsCollapse();
    }

    /**
     * Override afterRender to inject drawer styles, move header icons, and close-on-navigate.
     */
    afterRender() {
        super.afterRender();

        this.injectMobileDrawerStyles();
        this.setupMobileHeaderIcons();

        // Close drawer on route change
        this.listenTo(this.getRouter(), "routed", () => {
            if (this.isMobileDrawerOpen) {
                this.closeMobileDrawer();
            }
        });
    }

    /**
     * Move notification bell and user menu into .navbar-header on mobile.
     * This avoids CSS inheritance issues from being inside .navbar-body/.navbar-collapse.
     * @private
     */
    setupMobileHeaderIcons() {
        if (!this.isMobileScreen()) {
            return;
        }

        // Avoid double-init
        if (this.element.querySelector(".navbar-header-right")) {
            return;
        }

        const navbarHeader = this.element.querySelector(".navbar-header");

        if (!navbarHeader) {
            return;
        }

        const search = this.element.querySelector(".global-search-container");
        const quickCreate = this.element.querySelector(
            ".quick-create-container",
        );
        const bell = this.element.querySelector(
            ".notifications-badge-container",
        );
        const menu = this.element.querySelector(".menu-container");

        // Create a right-aligned container inside the header
        const rightIcons = document.createElement("div");
        rightIcons.className = "navbar-header-right";

        // Move search — hide the full input, show only the icon button
        if (search) {
            search.classList.remove("navbar-form");
            rightIcons.appendChild(search);
        }

        // Move quick-create — remove hidden-xs so it shows on mobile
        if (quickCreate) {
            quickCreate.classList.remove("hidden-xs");
            rightIcons.appendChild(quickCreate);
        }

        if (bell) rightIcons.appendChild(bell);
        if (menu) rightIcons.appendChild(menu);

        navbarHeader.appendChild(rightIcons);
    }

    /**
     * Load mobile drawer CSS stylesheet.
     * Only loads once (idempotent).
     * @private
     */
    injectMobileDrawerStyles() {
        if (document.getElementById("mobile-drawer-styles")) {
            return;
        }

        const link = document.createElement("link");
        link.id = "mobile-drawer-styles";
        link.rel = "stylesheet";
        link.href = "client/custom/modules/global/css/mobile-drawer.css";

        document.head.appendChild(link);
    }
}

export default CustomNavbarSiteView;
