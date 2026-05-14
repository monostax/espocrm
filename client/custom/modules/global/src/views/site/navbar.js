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
import TabsHelper from "global:helpers/site/tabs";

const DEFAULT_TABLIST_ID = "__default_tablist__";
const HIDDEN_NAVBAR = {id: "__none__", hidden: true};

/**
 * Custom navbar view that:
 * 1. Filters out Conversas menu items for users without chatSsoUrl
 * 2. Implements Linear.app-style mobile drawer navigation
 * 3. Supports multi-sidenav sidebar via team-scoped SidenavConfig entities
 * Uses appParams from the /api/v1/App/user response.
 */
class CustomNavbarSiteView extends NavbarSiteView {
    /** @private */
    isMobileDrawerOpen = false;

    /** @private */
    _switchingConfig = false;

    /**
     * @private
     * @return {boolean}
     */
    hasChatwootAccess() {
        return !!this.getHelper().getAppParam("chatSsoUrl");
    }

    /**
     * Filter out Conversas menu items if user doesn't have chatSsoUrl.
     * @private
     * @param {Array} tabList
     * @return {Array}
     */
    filterConversasItems(tabList) {
        if (this.hasChatwootAccess()) {
            return tabList;
        }

        return tabList.filter((item) => {
            if (item === "ChatwootConversation") {
                return false;
            }

            if (!item || typeof item !== "object") {
                return true;
            }

            if (item.scope === "ChatwootConversation") {
                return false;
            }

            if (
                typeof item.url === "string" &&
                item.url.includes("ChatwootConversation")
            ) {
                return false;
            }

            if (item.type === "divider" && item.text === "$Conversations") {
                return false;
            }

            if (item.type === "url" && item.id && /^8535\d{2}$/.test(item.id)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Override getTabList to use team-scoped navbar config system.
     * Resolution priority:
     *   1. Team SidenavConfig (if any configs exist for user's teams)
     *   2. Legacy tab customization (existing useCustomTabList/addCustomTabs)
     *   3. System default tabList
     * @return {(Object|string)[]}
     */
    getTabList() {
        const activeConfig = this.getActiveNavbarConfig();

        if (activeConfig && activeConfig.hidden) {
            return [];
        }

        if (this.hasNavbarConfigSystem()) {
            if (activeConfig) {
                if (activeConfig.isDefaultTabList) {
                    return this.filterConversasItems(this.getLegacyTabList());
                }

                if (activeConfig.tabList) {
                    const tabList = Espo.Utils.cloneDeep(activeConfig.tabList);

                    return this.filterConversasItems(tabList);
                }
            }
        }

        return this.filterConversasItems(this.getLegacyTabList());
    }

    /**
     * Get the legacy tabList via parent's getTabList, filtered for Conversas.
     * @return {(Object|string)[]}
     */
    getLegacyTabList() {
        return super.getTabList();
    }

    /**
     * @return {boolean}
     */
    hasNavbarConfigSystem() {
        const configList = this.getNavbarConfigList();

        return configList && configList.length > 0;
    }

    /**
     * Get the navbar config list from team-scoped SidenavConfig entities.
     * Fetches from `teamSidenavConfigs` appParam (already filtered server-side).
     * Optionally adds a "Default" tabList option.
     * @return {Object[]}
     */
    getNavbarConfigList() {
        const configs = [
            ...(this.getHelper().getAppParam("teamSidenavConfigs") || []),
        ];

        if (this.getConfig().get("navbarConfigShowDefaultTabList")) {
            configs.push({
                id: DEFAULT_TABLIST_ID,
                name: this.getLanguage().translate(
                    "defaultConfig",
                    "navbarConfig",
                    "Global",
                ),
                isDefaultTabList: true,
            });
        }

        return configs;
    }

    /**
     * Get the active navbar config from the resolved list.
     *
     * Resolution priority:
     *   1. URL query parameter `?navbar=<id>` (read-only override)
     *   2. User preference `activeNavbarConfigId`
     *   3. Default/first config from the list
     *
     * @return {Object|null}
     */
    getActiveNavbarConfig() {
        // 0. ?navbar=none hides the navbar entirely (works even without configs)
        const urlNavbarId = this.getUrlNavbarOverride();

        if (urlNavbarId === "none") {
            return HIDDEN_NAVBAR;
        }

        const configList = this.getNavbarConfigList();

        if (!configList || configList.length === 0) {
            return null;
        }

        // 1. Check URL query parameter first (highest priority)
        if (urlNavbarId !== null) {
            const found = configList.find((c) => c.id === urlNavbarId);

            if (found) {
                return found;
            }

            console.warn(
                `Navbar config ID "${urlNavbarId}" from URL query param not found, falling back to preference`,
            );
        }

        // 2. Fall back to user preference
        const activeId = this.getPreferences().get("activeNavbarConfigId");

        if (activeId) {
            if (activeId === DEFAULT_TABLIST_ID) {
                const defaultOption = configList.find(
                    (c) => c.id === DEFAULT_TABLIST_ID,
                );

                if (defaultOption) {
                    return defaultOption;
                }

                console.warn(
                    "Default tabList option selected but setting is disabled, falling back",
                );
            } else {
                const found = configList.find((c) => c.id === activeId);

                if (found) {
                    return found;
                }

                console.warn(
                    "Active navbar config ID not found, falling back to default",
                );
            }
        }

        // 3. Default/first config
        return configList.find((c) => c.isDefault) || configList[0];
    }

    /**
     * Read the navbar config ID from the URL query parameter `?navbar=<id>`.
     * Returns the param value if present and non-empty, or null.
     * @private
     * @return {string|null}
     */
    getUrlNavbarOverride() {
        try {
            const params = new URLSearchParams(window.location.search);
            const value = params.get("navbar");

            if (value && value.trim() !== "") {
                return value.trim();
            }
        } catch (e) {
            // URLSearchParams not supported or other error
        }

        return null;
    }

    /**
     * Remove the one-time navbar URL override so preference changes can take over.
     * @private
     */
    stripUrlNavbarOverride() {
        try {
            const url = new URL(window.location.href);

            if (!url.searchParams.has("navbar")) {
                return;
            }

            if (url.searchParams.get("navbar") === "none") {
                return;
            }

            url.searchParams.delete("navbar");

            window.history.replaceState(
                window.history.state,
                document.title,
                url.pathname + url.search + url.hash,
            );
        } catch (e) {
            // Ignore URL API/history failures; switching still works without stripping.
        }
    }

    /**
     * Override setup to add preference listener for activeNavbarConfigId.
     */
    setup() {
        this.virtualFolderViewKeys = [];
        this.virtualFolderConfigs = [];

        this.tabsHelper = new TabsHelper(
            this.getConfig(),
            this.getPreferences(),
            this.getUser(),
            this.getAcl(),
            this.getMetadata(),
            this.getLanguage(),
        );

        super.setup();

        this.listenTo(this.getHelper().preferences, "update", (attributeList) => {
            if (!attributeList) {
                return;
            }

            if (attributeList.includes("activeNavbarConfigId")) {
                this.setupTabDefsList();
                this.reRender();
            }
        });

        window.addEventListener("monostax:navbar-change", () => {
            this.setupTabDefsList();
            this.reRender();
        });
    }

    /**
     * Override afterRender to inject drawer styles, move header icons,
     * close-on-navigate, and set up the navbar config selector.
     */
    afterRender() {
        super.afterRender();

        const activeConfig = this.getActiveNavbarConfig();

        const navbarInner = this.element.querySelector(".navbar.navbar-inverse");

        if (activeConfig && activeConfig.hidden) {
            // Collapse the inner navbar to zero width instead of hiding it,
            // so the header (logo + toggle) can still be accessed if needed
            // and layout flow stays intact.
            if (navbarInner) {
                navbarInner.style.width = "0";
                navbarInner.style.overflow = "hidden";
            }

            // `super.afterRender()` adds `has-navbar` to body, which triggers
            // `body[data-navbar=side].has-navbar > .content { padding-left: ... }`
            // and leaves a gap where the hidden navbar used to be. Drop it.
            document.body.classList.remove("has-navbar");

            return;
        }

        // Restore the inner navbar in case a previous render collapsed it
        // (e.g. switching from `?navbar=none` to `?navbar=<id>` via PARENT_NAVIGATE).
        // `super.afterRender()` already re-adds the `has-navbar` body class.
        if (navbarInner) {
            navbarInner.style.width = "";
            navbarInner.style.overflow = "";
        }

        this.injectMobileDrawerStyles();
        this.injectNavbarConfigSelectorStyles();
        this.injectVirtualFolderStyles();
        this.injectSideNavbarPillStyles();
        this.injectChatIconStyles();
        this.setupMobileHeaderIcons();
        this.setupNavbarConfigSelector();
        this.setupChatIcon();
        this.renderAndInjectVirtualFolderViews();

        this.listenTo(this.getRouter(), "routed", () => {
            if (this.isMobileDrawerOpen) {
                this.closeMobileDrawer();
            }

            // Track last visited route per config
            const activeConfig = this.getActiveNavbarConfig();

            if (activeConfig && !this._switchingConfig) {
                this._saveConfigRoute(activeConfig.id, this.getRouter().getCurrentUrl());
            }
        });
    }

    /**
     * Set up the navbar config selector in the sidebar.
     * @private
     */
    setupNavbarConfigSelector() {
        if (!this.shouldShowConfigSelector()) {
            return;
        }

        const leftContainer = this.element.querySelector(
            ".navbar-left-container",
        );
        const tabs = leftContainer
            ? leftContainer.querySelector(".tabs")
            : null;

        if (!leftContainer || !tabs) {
            return;
        }

        let container = leftContainer.querySelector(
            ".navbar-config-selector-container",
        );

        if (!container) {
            container = document.createElement("div");
            container.className = "navbar-config-selector-container";
            leftContainer.insertBefore(container, tabs);
        }

        const configList = this.getNavbarConfigList();
        const activeConfig = this.getActiveNavbarConfig();

        this.createView(
            "navbarConfigSelector",
            "global:views/site/navbar-config-selector",
            {
                selector: ".navbar-config-selector-container",
                configList: configList,
                activeConfigId: activeConfig ? activeConfig.id : null,
            },
            (view) => {
                view.render();

                this.listenTo(view, "switch", (id) => {
                    this.switchNavbarConfig(id);
                });
            },
        );
    }

    selectTab(name) {
        super.selectTab(name);
        this.updateVirtualFolderActiveItems();
    }

    updateVirtualFolderActiveItems() {
        if (!this.element) {
            return;
        }

        const currentUrl = this.normalizeNavUrl(this.getRouter().getCurrentUrl());

        this.element.querySelectorAll('li.virtual-folder .virtual-folder-item').forEach(item => {
            const link = item.querySelector('a[href]');
            const href = link ? link.getAttribute('href') : null;

            item.classList.toggle('active', !!href && currentUrl === this.normalizeNavUrl(href));
        });
    }

    normalizeNavUrl(url) {
        if (!url || typeof url !== "string") {
            return "";
        }

        return url.replace(/^#/, "").replace(/^\//, "");
    }

    /**
     * @private
     * @return {boolean}
     */
    shouldShowConfigSelector() {
        if (!this.isSide()) {
            return false;
        }

        const activeConfig = this.getActiveNavbarConfig();
        const activeConfigId = activeConfig ? activeConfig.id : null;
        const configList = this.getNavbarConfigList()
            .filter(c => !c.hideOnDropdown || c.id === activeConfigId);

        return configList && configList.length > 1;
    }

    /**
     * Switch the active navbar config and persist to preferences.
     * Saves the current route for the outgoing config and navigates to
     * the saved route (or first tab item) for the incoming config.
     * @param {string} configId
     */
    async switchNavbarConfig(configId) {
        if (this._switchingConfig) {
            return;
        }

        this._switchingConfig = true;

        Espo.Ui.notify(" ... ");

        try {
            const currentConfig = this.getActiveNavbarConfig();
            const currentConfigId = currentConfig ? currentConfig.id : null;

            // Save current route for the outgoing config
            if (currentConfigId) {
                this._saveConfigRoute(currentConfigId, this.getRouter().getCurrentUrl());
            }

            await Espo.Ajax.putRequest("Preferences/" + this.getUser().id, {
                activeNavbarConfigId: configId,
            });

            this.getPreferences().set("activeNavbarConfigId", configId);
            this.stripUrlNavbarOverride();
            this.getPreferences().trigger("update", ["activeNavbarConfigId"]);

            this.setupTabDefsList();
            this.reRender();

            // Navigate to saved route or first tab item for the incoming config
            const targetUrl = this._getConfigRoute(configId);

            if (targetUrl) {
                this.getRouter().navigate(targetUrl, {trigger: true});
            }

            Espo.Ui.notify(false);
        } catch (e) {
            console.error("Error switching navbar config:", e);
            Espo.Ui.error(
                this.getLanguage().translate(
                    "errorSavingPreference",
                    "messages",
                    "Global",
                ),
            );
        } finally {
            this._switchingConfig = false;
        }
    }

    /**
     * Get the saved route for a config, or the first navigable tab item's URL.
     * Strips ?navbar= query params since those are for deep-linking only.
     * @private
     * @param {string} configId
     * @return {string|null}
     */
    _getConfigRoute(configId) {
        // 1. Check saved routes in preferences
        const savedRoutes = this.getPreferences().get("sidenavConfigLastRoutes") || {};

        if (savedRoutes[configId]) {
            return this._stripNavbarParam(savedRoutes[configId]);
        }

        // 2. Fall back to first navigable tab item
        const configList = this.getNavbarConfigList();
        const config = configList.find((c) => c.id === configId);

        if (!config) {
            return null;
        }

        const tabList = config.isDefaultTabList
            ? this.getLegacyTabList()
            : (config.tabList || []);

        for (const item of tabList) {
            if (typeof item === "string") {
                return "#" + item;
            }

            if (item && typeof item === "object" && item.type === "url" && item.url) {
                return this._stripNavbarParam(item.url);
            }
        }

        return null;
    }

    /**
     * Strip ?navbar= query parameter from a URL.
     * @private
     * @param {string} url
     * @return {string}
     */
    _stripNavbarParam(url) {
        if (!url || typeof url !== "string") {
            return url;
        }

        try {
            // Handle URLs like ?navbar=activities#Activities
            if (url.startsWith("?")) {
                const hashIndex = url.indexOf("#");
                const hash = hashIndex >= 0 ? url.substring(hashIndex) : "";
                const query = hashIndex >= 0 ? url.substring(0, hashIndex) : url;
                const params = new URLSearchParams(query);

                params.delete("navbar");

                const remaining = params.toString();

                return (remaining ? "?" + remaining : "") + hash;
            }

            return url;
        } catch (e) {
            // Fallback: strip ?navbar=...& or ?navbar=...
            return url.replace(/[?&]navbar=[^&#]*/, "").replace(/^\?$/, "");
        }
    }

    /**
     * Save a route for a config to user preferences.
     * @private
     * @param {string} configId
     * @param {string} url
     */
    _saveConfigRoute(configId, url) {
        if (!configId || !url) {
            return;
        }

        const savedRoutes = this.getPreferences().get("sidenavConfigLastRoutes") || {};

        savedRoutes[configId] = url;

        this.getPreferences().set("sidenavConfigLastRoutes", savedRoutes);

        Espo.Ajax.putRequest("Preferences/" + this.getUser().id, {
            sidenavConfigLastRoutes: savedRoutes,
        }).catch((e) => {
            console.warn("Failed to save sidenav route preference:", e);
        });
    }

    prepareTabItemDefs(params, tab, i, vars) {
        const isTabVirtualFolder = (item) => {
            if (this.tabsHelper.isTabVirtualFolder) {
                return this.tabsHelper.isTabVirtualFolder(item);
            }
            return (
                typeof item === "object" &&
                item !== null &&
                item.type === "virtualFolder"
            );
        };

        if (isTabVirtualFolder(tab)) {
            return this.prepareVirtualFolderDefs(params, tab, i, vars);
        }

        return super.prepareTabItemDefs(params, tab, i, vars);
    }

    prepareVirtualFolderDefs(params, tab, i, vars) {
        return {
            name: `vf-${tab.id}`,
            isInMore: vars.moreIsMet,
            isVirtualFolder: true,
            virtualFolderId: tab.id,
            config: tab,
            isDivider: false,
            link: null,
            aClassName: "nav-link nav-virtual-folder-link",
            label: tab.label || tab.entityType || "Virtual Folder",
            shortLabel: (tab.label || tab.entityType || "VF").substring(0, 2),
            iconClass:
                tab.iconClass ||
                this.getMetadata().get([
                    "clientDefs",
                    tab.entityType,
                    "iconClass",
                ]) ||
                "fas fa-folder",
            color: tab.color || null,
        };
    }

    setupTabDefsList() {
        this.urlList = [];

        const allTabList = this.getTabList();
        const isTabVirtualFolder = (item) => {
            if (this.tabsHelper.isTabVirtualFolder) {
                return this.tabsHelper.isTabVirtualFolder(item);
            }
            return (
                typeof item === "object" &&
                item !== null &&
                item.type === "virtualFolder"
            );
        };

        this.tabList = allTabList.filter((item, i) => {
            if (!item) {
                return false;
            }

            if (typeof item !== "object") {
                return this.tabsHelper.checkTabAccess(item);
            }

            if (isTabVirtualFolder(item)) {
                return this.getAcl().checkScope(item.entityType, "read");
            }

            if (this.tabsHelper.isTabDivider(item)) {
                if (!this.isSide()) {
                    return false;
                }

                if (i === allTabList.length - 1) {
                    return false;
                }

                return true;
            }

            if (this.tabsHelper.isTabUrl(item)) {
                return this.tabsHelper.checkTabAccess(item);
            }

            let itemList = (item.itemList || []).filter((subItem) => {
                if (this.tabsHelper.isTabDivider(subItem)) {
                    return true;
                }

                return this.tabsHelper.checkTabAccess(subItem);
            });

            itemList = itemList.filter((subItem, j) => {
                if (!this.tabsHelper.isTabDivider(subItem)) {
                    return true;
                }

                const nextItem = itemList[j + 1];

                if (!nextItem) {
                    return true;
                }

                if (this.tabsHelper.isTabDivider(nextItem)) {
                    return false;
                }

                return true;
            });

            itemList = itemList.filter((subItem, j) => {
                if (!this.tabsHelper.isTabDivider(subItem)) {
                    return true;
                }

                if (j === 0 || j === itemList.length - 1) {
                    return false;
                }

                return true;
            });

            item.itemList = itemList;

            return !!itemList.length;
        });

        let moreIsMet = false;

        this.tabList = this.tabList.filter((item, i) => {
            const nextItem = this.tabList[i + 1];
            const prevItem = this.tabList[i - 1];

            if (this.tabsHelper.isTabMoreDelimiter(item)) {
                moreIsMet = true;
            }

            if (!this.tabsHelper.isTabDivider(item)) {
                return true;
            }

            if (isTabVirtualFolder(item)) {
                return true;
            }

            if (!nextItem) {
                return true;
            }

            if (this.tabsHelper.isTabDivider(nextItem)) {
                return false;
            }

            if (
                this.tabsHelper.isTabDivider(prevItem) &&
                this.tabsHelper.isTabMoreDelimiter(nextItem) &&
                moreIsMet
            ) {
                return false;
            }

            return true;
        });

        if (moreIsMet) {
            let end = this.tabList.length;

            for (let i = this.tabList.length - 1; i >= 0; i--) {
                const item = this.tabList[i];

                if (
                    !this.tabsHelper.isTabDivider(item) ||
                    isTabVirtualFolder(item)
                ) {
                    break;
                }

                end = this.tabList.length - 1;
            }

            this.tabList = this.tabList.slice(0, end);
        }

        const tabDefsList = [];

        const colorsDisabled =
            this.getConfig().get("scopeColorsDisabled") ||
            this.getConfig().get("tabColorsDisabled");

        const tabIconsDisabled = this.getConfig().get("tabIconsDisabled");

        const params = {
            colorsDisabled: colorsDisabled,
            tabIconsDisabled: tabIconsDisabled,
        };

        const vars = {
            moreIsMet: false,
            isHidden: false,
        };

        this.virtualFolderViewKeys = [];
        this.virtualFolderConfigs = [];

        this.tabList.forEach((tab, i) => {
            if (this.tabsHelper.isTabMoreDelimiter(tab)) {
                if (!vars.moreIsMet) {
                    vars.moreIsMet = true;

                    return;
                }

                if (i === this.tabList.length - 1) {
                    return;
                }

                vars.isHidden = true;

                tabDefsList.push({
                    name: "show-more",
                    isInMore: true,
                    className: "show-more",
                    html: '<span class="fas fa-ellipsis-h more-icon"></span>',
                });

                return;
            }

            const defs = this.prepareTabItemDefs(params, tab, i, vars);
            tabDefsList.push(defs);

            if (defs.isVirtualFolder) {
                this.virtualFolderConfigs.push(defs);
            }
        });

        this.tabDefsList = tabDefsList;
    }

    renderAndInjectVirtualFolderViews() {
        // Clean up previous virtual folder views
        if (this.virtualFolderViewKeys && this.virtualFolderViewKeys.length) {
            for (const key of this.virtualFolderViewKeys) {
                if (this.hasView(key)) {
                    this.clearView(key);
                }
            }
        }

        if (!this.virtualFolderConfigs || !this.virtualFolderConfigs.length) {
            return;
        }

        if (!this.element) {
            return;
        }

        for (const defs of this.virtualFolderConfigs) {
            if (defs.isInMore) {
                continue;
            }

            const key = "virtualFolder-" + defs.virtualFolderId;
            const li = this.element.querySelector(
                `li[data-name="vf-${defs.virtualFolderId}"]`,
            );

            if (!li) {
                console.warn(
                    `[VirtualFolder] placeholder <li> not found for ${defs.virtualFolderId}`,
                );

                continue;
            }

            this.virtualFolderViewKeys.push(key);

            const containerId = "vf-el-" + defs.virtualFolderId;

            li.id = containerId;
            li.innerHTML = "";
            li.classList.add("virtual-folder");
            li.classList.remove("tab");

            this.createView(
                key,
                "global:views/site/navbar/virtual-folder",
                {
                    el: "#" + containerId,
                    virtualFolderId: defs.virtualFolderId,
                    config: defs.config,
                },
                (view) => {
                    view.render().then(() => view.fetchRecords());
                },
            );
        }
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

        this.$mobileDrawerBackdrop = $("<div>")
            .addClass("mobile-drawer-backdrop")
            .on("click", () => this.closeMobileDrawer())
            .appendTo(document.body);

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
     * Move notification bell and user menu into .navbar-header on mobile.
     * @private
     */
    setupMobileHeaderIcons() {
        if (!this.isMobileScreen()) {
            return;
        }

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

        const rightIcons = document.createElement("div");
        rightIcons.className = "navbar-header-right";

        if (search) {
            search.classList.remove("navbar-form");
            rightIcons.appendChild(search);
        }

        if (quickCreate) {
            quickCreate.classList.remove("hidden-xs");
            rightIcons.appendChild(quickCreate);
        }

        if (bell) rightIcons.appendChild(bell);
        if (menu) rightIcons.appendChild(menu);

        navbarHeader.appendChild(rightIcons);
    }

    /**
     * Load mobile drawer CSS stylesheet (idempotent).
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

    /**
     * Load navbar config selector CSS stylesheet (idempotent).
     * @private
     */
    injectNavbarConfigSelectorStyles() {
        if (document.getElementById("navbar-config-selector-styles")) {
            return;
        }

        const link = document.createElement("link");
        link.id = "navbar-config-selector-styles";
        link.rel = "stylesheet";
        link.href =
            "client/custom/modules/global/css/navbar-config-selector.css";

        document.head.appendChild(link);
    }

    /**
     * Load virtual folder CSS stylesheet (idempotent).
     * @private
     */
    injectVirtualFolderStyles() {
        if (document.getElementById("virtual-folder-styles")) {
            return;
        }

        const link = document.createElement("link");
        link.id = "virtual-folder-styles";
        link.rel = "stylesheet";
        link.href = "client/custom/modules/global/css/virtual-folder.css";

        document.head.appendChild(link);
    }

    /**
     * Load side-navbar pill CSS stylesheet (idempotent).
     * @private
     */
    injectSideNavbarPillStyles() {
        if (document.getElementById("side-navbar-pill-styles")) {
            return;
        }

        const link = document.createElement("link");
        link.id = "side-navbar-pill-styles";
        link.rel = "stylesheet";
        link.href = "client/custom/modules/global/css/side-navbar-pill.css";

        document.head.appendChild(link);
    }

    /**
     * Inject a chat icon into the navbar-right bar when chatSsoUrl is available.
     * Opens the chat SSO URL in a new tab.
     * @private
     */
    setupChatIcon() {
        if (!this.hasChatwootAccess()) {
            return;
        }

        const chatSsoUrl = this.getHelper().getAppParam("chatSsoUrl");

        if (!chatSsoUrl) {
            return;
        }

        const navbarRight = this.element
            ? this.element.querySelector(".navbar-right")
            : null;

        if (!navbarRight) {
            return;
        }

        if (navbarRight.querySelector(".chat-icon-container")) {
            return;
        }

        const menuContainer = navbarRight.querySelector(".menu-container");

        const li = document.createElement("li");
        li.className = "chat-icon-container";

        const a = document.createElement("a");
        a.href = chatSsoUrl;
        a.target = "_blank";
        a.rel = "noopener noreferrer";
        a.className = "nav-link chat-icon-link";
        a.title =
            this.getLanguage().translate("Chat", "labels", "Global") || "Chat";

        const icon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        icon.setAttribute("class", "icon");
        icon.setAttribute("width", "15");
        icon.setAttribute("height", "15");
        icon.setAttribute("viewBox", "3 3 18 18");
        icon.setAttribute("fill", "currentColor");
        icon.style.setProperty("width", "15px", "important");
        icon.style.setProperty("height", "15px", "important");
        icon.style.setProperty("min-width", "15px", "important");
        icon.style.setProperty("min-height", "15px", "important");

        const backgroundPath = document.createElementNS("http://www.w3.org/2000/svg", "path");
        backgroundPath.setAttribute("d", "M0 0h24v24H0z");
        backgroundPath.setAttribute("fill", "none");

        const topMessagePath = document.createElementNS("http://www.w3.org/2000/svg", "path");
        topMessagePath.setAttribute("d", "M20.901 14.995l-.044 -.006a.4 .4 0 0 1 -.102 -.02l-.045 -.012l-.048 -.017l-.045 -.016l-.043 -.02l-.045 -.022l-.04 -.024l-.044 -.026l-.043 -.032l-.036 -.027a1 1 0 0 1 -.073 -.066l-2.707 -2.707h-6.586a2 2 0 0 1 -2 -2v-6a2 2 0 0 1 2 -2h9a2 2 0 0 1 2 2v10a1 1 0 0 1 -.076 .383l-.02 .043l-.022 .045l-.024 .04l-.026 .044l-.032 .043l-.027 .036a1 1 0 0 1 -.578 .347l-.052 .008l-.044 .006a1 1 0 0 1 -.198 0");

        const bottomMessagePath = document.createElementNS("http://www.w3.org/2000/svg", "path");
        bottomMessagePath.setAttribute("d", "M7 8.999v1.001a4 4 0 0 0 4 4h4v3a2 2 0 0 1 -2 2h-6.586l-2.707 2.707c-.63 .63 -1.707 .184 -1.707 -.707v-10a2 2 0 0 1 2 -2z");

        icon.appendChild(backgroundPath);
        icon.appendChild(topMessagePath);
        icon.appendChild(bottomMessagePath);

        a.appendChild(icon);
        li.appendChild(a);

        if (menuContainer) {
            navbarRight.insertBefore(li, menuContainer);
        } else {
            navbarRight.appendChild(li);
        }
    }

    /**
     * Load chat icon CSS styles (idempotent).
     * @private
     */
    injectChatIconStyles() {
        if (document.getElementById("chat-icon-styles")) {
            return;
        }

        const style = document.createElement("style");
        style.id = "chat-icon-styles";
        style.textContent = `
            .chat-icon-container {
                display: block !important;
            }
            .chat-icon-container .chat-icon-link {
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .chat-icon-container .chat-icon-link .icon {
                width: 15px;
                height: 15px;
                display: block;
            }
            .chat-icon-container .chat-icon-link:hover {
                opacity: 0.8;
            }
        `;

        document.head.appendChild(style);
    }
}

export default CustomNavbarSiteView;
