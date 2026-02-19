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
 * 3. Supports multi-sidenav sidebar via navbar config selector
 * Uses appParams from the /api/v1/App/user response.
 */
class CustomNavbarSiteView extends NavbarSiteView {
    /** @private */
    isMobileDrawerOpen = false;

    /** @private */
    _switchingConfig = false;

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
            if (!item || typeof item !== "object") {
                return true;
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
     * Override getTabList to use navbar config system, then filter Conversas items.
     * Resolution priority:
     *   1. Navbar config system (if any navbarConfigList exists)
     *   2. Legacy tab customization (existing useCustomTabList/addCustomTabs)
     *   3. System default tabList
     * @return {(Object|string)[]}
     */
    getTabList() {
        if (this.hasNavbarConfigSystem()) {
            const activeConfig = this.getActiveNavbarConfig();

            if (activeConfig && activeConfig.tabList) {
                let tabList = Espo.Utils.cloneDeep(activeConfig.tabList);

                if (this.isSide()) {
                    tabList.unshift('Home');
                }

                return this.filterConversasItems(tabList);
            }
        }

        const tabList = super.getTabList();

        return this.filterConversasItems(tabList);
    }

    /**
     * @return {boolean}
     */
    hasNavbarConfigSystem() {
        const configList = this.getNavbarConfigList();

        return configList && configList.length > 0;
    }

    /**
     * Get the resolved navbar config list based on admin and user settings.
     * @return {Object[]}
     */
    getNavbarConfigList() {
        if (this.getConfig().get('navbarConfigDisabled')) {
            return this.getConfig().get('navbarConfigList') || [];
        }

        if (this.getPreferences().get('useCustomNavbarConfig')) {
            return this.getPreferences().get('navbarConfigList') || [];
        }

        return this.getConfig().get('navbarConfigList') || [];
    }

    /**
     * Get the active navbar config from the resolved list.
     * @return {Object|null}
     */
    getActiveNavbarConfig() {
        const configList = this.getNavbarConfigList();

        if (!configList || configList.length === 0) {
            return null;
        }

        const activeId = this.getPreferences().get('activeNavbarConfigId');

        if (activeId) {
            const found = configList.find(c => c.id === activeId);

            if (found) {
                return found;
            }

            console.warn('Active navbar config ID not found, falling back to default');
        }

        return configList.find(c => c.isDefault) || configList[0];
    }

    /**
     * Override setup to add preference listeners for navbar config fields.
     */
    setup() {
        super.setup();

        const originalPreferencesListener = true;

        this.listenTo(this.getHelper().preferences, 'update', (attributeList) => {
            if (!attributeList) {
                return;
            }

            if (
                attributeList.includes('navbarConfigList') ||
                attributeList.includes('useCustomNavbarConfig') ||
                attributeList.includes('activeNavbarConfigId')
            ) {
                this.setupTabDefsList();
                this.reRender();
            }
        });
    }

    /**
     * Override afterRender to inject drawer styles, move header icons,
     * close-on-navigate, and set up the navbar config selector.
     */
    afterRender() {
        super.afterRender();

        this.injectMobileDrawerStyles();
        this.injectNavbarConfigSelectorStyles();
        this.setupMobileHeaderIcons();
        this.setupNavbarConfigSelector();

        this.listenTo(this.getRouter(), "routed", () => {
            if (this.isMobileDrawerOpen) {
                this.closeMobileDrawer();
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

        const leftContainer = this.element.querySelector('.navbar-left-container');
        const tabs = leftContainer ? leftContainer.querySelector('.tabs') : null;

        if (!leftContainer || !tabs) {
            return;
        }

        let container = leftContainer.querySelector('.navbar-config-selector-container');

        if (!container) {
            container = document.createElement('div');
            container.className = 'navbar-config-selector-container';
            leftContainer.insertBefore(container, tabs);
        }

        const configList = this.getNavbarConfigList();
        const activeConfig = this.getActiveNavbarConfig();

        this.createView(
            'navbarConfigSelector',
            'global:views/site/navbar-config-selector',
            {
                selector: '.navbar-config-selector-container',
                configList: configList,
                activeConfigId: activeConfig ? activeConfig.id : null,
            },
            (view) => {
                view.render();

                this.listenTo(view, 'switch', (id) => {
                    this.switchNavbarConfig(id);
                });
            }
        );
    }

    /**
     * @private
     * @return {boolean}
     */
    shouldShowConfigSelector() {
        if (this.getConfig().get('navbarConfigSelectorDisabled')) {
            return false;
        }

        if (!this.isSide()) {
            return false;
        }

        const configList = this.getNavbarConfigList();

        return configList && configList.length > 1;
    }

    /**
     * Switch the active navbar config and persist to preferences.
     * @param {string} configId
     */
    async switchNavbarConfig(configId) {
        if (this._switchingConfig) {
            return;
        }

        this._switchingConfig = true;

        Espo.Ui.notify(' ... ');

        try {
            await Espo.Ajax.putRequest('Preferences/' + this.getUser().id, {
                activeNavbarConfigId: configId,
            });

            this.getPreferences().set('activeNavbarConfigId', configId);
            this.getPreferences().trigger('update', ['activeNavbarConfigId']);

            this.setupTabDefsList();
            this.reRender();

            Espo.Ui.notify(false);
        } catch (e) {
            console.error('Error switching navbar config:', e);
            Espo.Ui.error(
                this.getLanguage().translate('errorSavingPreference', 'messages', 'Global')
            );
        } finally {
            this._switchingConfig = false;
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
        link.href = "client/custom/modules/global/css/navbar-config-selector.css";

        document.head.appendChild(link);
    }
}

export default CustomNavbarSiteView;
