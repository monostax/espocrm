/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Resource Calendar View - Shows events grouped by users (resources)
 * Uses FullCalendar Premium Scheduler for resourceTimeGridDay/Week views
 *
 * @module custom/modules/global/views/calendar/resource-calendar
 */

import View from "view";
import moment from "moment";
import * as FullCalendar from "fullcalendar";
import RecordModal from "helpers/record-modal";

class ResourceCalendarView extends View {
    template = "global:calendar/resource-calendar";

    eventAttributes = [];
    colors = {};

    /** @private @type {string[]} */
    allDayScopeList;

    /** @private @type {string[]} */
    scopeList = ["Meeting", "Call", "Task"];

    /** @private @type {string[]} */
    onlyDateScopeList;

    /** @private @type {string[]} */
    enabledScopeList;

    header = true;
    modeList = [];

    fullCalendarModeList = ["resourceTimeGridDay", "resourceTimeGridWeek"];

    defaultMode = "resourceTimeGridDay";
    slotDuration = 30;
    scrollToNowSlots = 6;
    scrollHour = 6;

    titleFormat = {
        day: "dddd, MMMM D, YYYY",
        week: "MMMM YYYY",
    };

    rangeSeparator = " – ";

    /** @private */
    fetching = false;

    modeViewMap = {
        resourceTimeGridDay: "resourceTimeGridDay",
        resourceTimeGridWeek: "resourceTimeGridWeek",
    };

    extendedProps = [
        "scope",
        "recordId",
        "dateStart",
        "dateEnd",
        "dateStartDate",
        "dateEndDate",
        "status",
        "originalColor",
        "duration",
        "allDayCopy",
        "resourceId",
    ];

    /** @type {FullCalendar.Calendar} */
    calendar;

    /** @private @type {Array<{id: string, name: string}>} */
    userList = [];

    /**
     * Currently enabled user IDs (visible in calendar)
     * @private @type {string[]}
     */
    enabledUserIdList = [];

    /**
     * User colors for event styling
     * @private @type {Object<string, string>}
     */
    userColors = {};

    /**
     * @private @type {import('./color-picker-popover').default|null}
     */
    colorPickerView = null;

    events = {
        /** @this ResourceCalendarView */
        'click button[data-action="prev"]': function () {
            this.actionPrevious();
        },
        /** @this ResourceCalendarView */
        'click button[data-action="next"]': function () {
            this.actionNext();
        },
        /** @this ResourceCalendarView */
        'click button[data-action="today"]': function () {
            this.actionToday();
        },
        /** @this ResourceCalendarView */
        'click [data-action="mode"]': function (e) {
            const mode = $(e.currentTarget).data("mode");
            this.selectMode(mode);
        },
        /** @this ResourceCalendarView */
        'click [data-action="refresh"]': function () {
            this.actionRefresh();
        },
        /** @this ResourceCalendarView */
        'click [data-action="toggleScopeFilter"]': function (e) {
            const $target = $(e.currentTarget);
            const filterName = $target.data("name");
            const $check = $target.find(".filter-check-icon");

            if ($check.hasClass("hidden")) {
                $check.removeClass("hidden");
            } else {
                $check.addClass("hidden");
            }

            e.stopPropagation(e);
            this.toggleScopeFilter(filterName);
        },
        // Note: toggleUserFilter, changeUserColor, and manageUsers are now handled
        // by the mode-buttons view which communicates via onUserFilterChange,
        // onUserColorChange callbacks and manageUsers event
    };

    /**
     * @param {{
     *     userId?: string,
     *     userName?: string|null,
     *     mode?: string|null,
     *     date?: string|null,
     *     scrollToNowSlots?: boolean,
     *     $container?: JQuery,
     *     suppressLoadingAlert?: boolean,
     *     slotDuration?: number,
     *     scrollHour?: number,
     *     teamIdList?: string[],
     *     userIdList?: string[],
     *     containerSelector?: string,
     *     height?: number,
     *     enabledScopeList?: string[],
     *     header?: boolean,
     *     onSave?: function(),
     * }} options
     */
    constructor(options) {
        super(options);
        this.options = options;
    }

    data() {
        return {
            mode: this.mode,
            header: this.header,
            isCustomViewAvailable: this.isCustomViewAvailable,
            isCustomView: this.isCustomView,
            todayLabel: this.translate("Today", "labels", "Calendar"),
            todayLabelShort: this.translate(
                "Today",
                "labels",
                "Calendar",
            ).slice(0, 2),
            userFilterDataList: this.getUserFilterDataList(),
            hasUserFilter: this.userList.length > 0,
            scopeFilterDataList: this.getScopeFilterDataList(),
        };
    }

    /**
     * Get user filter data for template
     * @private
     * @return {Array<{id: string, name: string, disabled: boolean, color: string}>}
     */
    getUserFilterDataList() {
        return this.userList.map((user) => ({
            id: user.id,
            name: user.name,
            disabled: !this.enabledUserIdList.includes(user.id),
            color: this.userColors[user.id] || this.getColorForUser(user.id),
        }));
    }

    /**
     * Get scope filter data for template
     * @private
     * @return {Array<{scope: string, disabled: boolean}>}
     */
    getScopeFilterDataList() {
        return this.scopeList.map((scope) => ({
            scope: scope,
            disabled: !this.enabledScopeList.includes(scope),
        }));
    }

    setup() {
        // Load moment plugins (required for base calendar functionality)
        this.wait(
            Espo.loader
                .requirePromise("lib!@fullcalendar/moment")
                .catch((e) =>
                    console.warn("Failed to load @fullcalendar/moment:", e),
                ),
        );
        this.wait(
            Espo.loader
                .requirePromise("lib!@fullcalendar/moment-timezone")
                .catch((e) =>
                    console.warn(
                        "Failed to load @fullcalendar/moment-timezone:",
                        e,
                    ),
                ),
        );

        // Load resource plugins (optional - view will show fallback if unavailable)
        this.wait(this.loadResourcePlugins());

        this.suppressLoadingAlert = this.options.suppressLoadingAlert;
        this.date = this.options.date || null;
        this.mode = this.options.mode || this.defaultMode;
        this.header =
            "header" in this.options ? this.options.header : this.header;

        this.scrollToNowSlots =
            this.options.scrollToNowSlots !== undefined
                ? this.options.scrollToNowSlots
                : this.scrollToNowSlots;

        this.setupMode();

        this.$container = this.options.$container;

        this.colors = Espo.Utils.clone(
            this.getMetadata().get("clientDefs.Calendar.colors") || this.colors,
        );

        this.modeList =
            this.getMetadata().get("clientDefs.Calendar.modeList") ||
            this.modeList;

        this.scopeList =
            this.getConfig().get("calendarEntityList") ||
            Espo.Utils.clone(this.scopeList);

        this.allDayScopeList =
            this.getMetadata().get("clientDefs.Calendar.allDayScopeList") ?? [];

        this.scopeList.forEach((scope) => {
            if (
                this.getMetadata().get(`scopes.${scope}.calendarOneDay`) &&
                !this.allDayScopeList.includes(scope)
            ) {
                this.allDayScopeList.push(scope);
            }
        });

        this.onlyDateScopeList = this.scopeList.filter((scope) => {
            return (
                this.getMetadata().get(
                    `entityDefs.${scope}.fields.dateStart.type`,
                ) === "date"
            );
        });

        this.slotDuration =
            this.options.slotDuration ||
            this.getPreferences().get("calendarSlotDuration") ||
            this.getMetadata().get("clientDefs.Calendar.slotDuration") ||
            this.slotDuration;

        this.setupScrollHour();

        this.colors = {
            ...this.colors,
            ...this.getHelper().themeManager.getParam("calendarColors"),
        };

        this.isCustomViewAvailable =
            this.getAcl().getPermissionLevel("userCalendar") !== "no";

        if (this.options.userId) {
            this.isCustomViewAvailable = false;
        }

        const scopeList = [];

        this.scopeList.forEach((scope) => {
            if (this.getAcl().check(scope)) {
                scopeList.push(scope);
            }
        });

        this.scopeList = scopeList;

        if (this.header) {
            this.enabledScopeList =
                this.getStoredEnabledScopeList() ||
                Espo.Utils.clone(this.scopeList);
        } else {
            this.enabledScopeList =
                this.options.enabledScopeList ||
                Espo.Utils.clone(this.scopeList);
        }

        if (
            Object.prototype.toString.call(this.enabledScopeList) !==
            "[object Array]"
        ) {
            this.enabledScopeList = [];
        }

        this.enabledScopeList.forEach((item) => {
            const color = this.getMetadata().get(["clientDefs", item, "color"]);

            if (color) {
                this.colors[item] = color;
            }
        });

        // Initialize user list for resources
        this.initUserList();

        // Initialize enabled user list and colors
        this.enabledUserIdList = this.getStoredEnabledUserIdList();
        this.userColors = this.getUserColors();

        // Expose userFilterList for mode-buttons view (same pattern as agendaWeek calendar)
        this.userFilterList = this.userList;

        if (this.header) {
            this.createView(
                "modeButtons",
                "global:views/calendar/mode-buttons",
                {
                    selector: ".mode-buttons",
                    isCustomViewAvailable: this.isCustomViewAvailable,
                    modeList: this.modeList,
                    scopeList: this.scopeList,
                    mode: this.mode,
                },
                (view) => {
                    // Pass user filter list to mode buttons
                    view.userFilterList = this.userFilterList;
                    view.enabledUserIdList = this.enabledUserIdList;

                    // Listen for manage users action
                    this.listenTo(view, "manageUsers", () => {
                        this.actionShowResourceOptions();
                    });
                },
            );
        }
    }

    /**
     * Load FullCalendar resource plugins
     * @private
     * @return {Promise<void>}
     */
    async loadResourcePlugins() {
        this.resourcePluginsLoaded = false;
        this.pluginLoadError = null;

        try {
            // Ensure base FullCalendar is loaded and window.FullCalendar is set up
            // The scheduler plugins are IIFE builds that require window.FullCalendar.Internal
            await Espo.loader.requirePromise("lib!fullcalendar");

            // Verify FullCalendar is available with Internal API
            if (typeof window.FullCalendar === "undefined") {
                throw new Error("FullCalendar not loaded on window object");
            }

            if (!window.FullCalendar.Internal) {
                // Debug: list available properties on FullCalendar
                const props = Object.keys(window.FullCalendar).join(", ");
                throw new Error(
                    `FullCalendar.Internal not available. Available props: ${props}`,
                );
            }

            // Load the scheduler plugins bundle via EspoCRM loader
            // The bundle contains: premium-common, resource, resource-daygrid, resource-timegrid
            await Espo.loader.requirePromise("lib!fullcalendar-scheduler");

            // Verify plugins are working by checking for the view
            const testCal = document.createElement("div");
            try {
                const cal = new FullCalendar.Calendar(testCal, {
                    initialView: "resourceTimeGridDay",
                    resources: [],
                    schedulerLicenseKey: "GPL-My-Project-Is-Open-Source",
                });
                this.resourcePluginsLoaded = true;
                cal.destroy();
            } catch (e) {
                console.warn(
                    "FullCalendar Scheduler plugins loaded but not functional:",
                    e.message,
                );
                this.pluginLoadError = e.message;
            }
        } catch (e) {
            console.error(
                "FullCalendar Scheduler plugins not available:",
                e.message,
            );
            this.pluginLoadError = e.message;
        }
    }

    /**
     * Initialize user list for resources
     * @private
     */
    initUserList() {
        this.userList = [];

        if (this.options.userList && this.options.userList.length) {
            // Use provided user list directly
            this.userList = Espo.Utils.clone(this.options.userList);
        } else if (this.options.userIdList && this.options.userIdList.length) {
            // Use provided user ID list
            this.options.userIdList.forEach((userId, index) => {
                this.userList.push({
                    id: userId,
                    name: this.options.userNameList?.[index] || userId,
                });
            });
        } else if (this.options.teamIdList && this.options.teamIdList.length) {
            // Will be populated from API - use shared calendar user list
            this.teamIdList = this.options.teamIdList;
            this.userList = this.getSharedCalendarUserList();
        } else {
            // Try shared calendar user list first
            const sharedUserList = this.getSharedCalendarUserList();

            if (sharedUserList && sharedUserList.length > 1) {
                this.userList = sharedUserList;
            } else {
                // Default to current user
                this.userList.push({
                    id: this.getUser().id,
                    name: this.getUser().get("name"),
                });
            }
        }
    }

    /**
     * Get shared calendar user list from preferences
     * @private
     * @return {Array<{id: string, name: string}>}
     */
    getSharedCalendarUserList() {
        const list = Espo.Utils.clone(
            this.getPreferences().get("sharedCalendarUserList"),
        );

        if (list && list.length) {
            let isBad = false;

            list.forEach((item) => {
                if (typeof item !== "object" || !item.id || !item.name) {
                    isBad = true;
                }
            });

            if (!isBad) {
                return list;
            }
        }

        return [
            {
                id: this.getUser().id,
                name: this.getUser().get("name"),
            },
        ];
    }

    /**
     * Store user list in preferences
     * @private
     */
    storeUserList() {
        this.getPreferences().save(
            {
                sharedCalendarUserList: Espo.Utils.clone(this.userList),
            },
            { patch: true },
        );
    }

    /**
     * Get stored enabled user ID list from localStorage
     * @private
     * @return {string[]}
     */
    getStoredEnabledUserIdList() {
        const stored = this.getStorage().get(
            "state",
            "resourceCalendarEnabledUserIdList",
        );

        if (stored && stored.length) {
            // Filter to only include users in the user list
            return stored.filter((id) =>
                this.userList.some((u) => u.id === id),
            );
        }

        // Default: all users enabled
        return this.userList.map((u) => u.id);
    }

    /**
     * Store enabled user ID list in localStorage
     * @private
     * @param {string[]} list
     */
    storeEnabledUserIdList(list) {
        this.getStorage().set(
            "state",
            "resourceCalendarEnabledUserIdList",
            list,
        );
    }

    /**
     * Get user colors from preferences (persisted on server)
     * @private
     * @return {Object<string, string>}
     */
    getUserColors() {
        return this.getPreferences().get("resourceCalendarUserColors") || {};
    }

    /**
     * Store user colors in preferences (persisted on server)
     * @private
     * @param {Object<string, string>} colors
     */
    storeUserColors(colors) {
        this.getPreferences().save(
            {
                resourceCalendarUserColors: colors,
            },
            { patch: true },
        );
    }

    /**
     * Get color for a user
     * @private
     * @param {string} userId
     * @return {string}
     */
    getUserColor(userId) {
        if (this.userColors[userId]) {
            return this.userColors[userId];
        }

        return this.getColorForUser(userId);
    }

    /**
     * Generate a consistent color for a user based on their ID
     * @private
     * @param {string} userId
     * @return {string}
     */
    getColorForUser(userId) {
        // Predefined color palette (Google Calendar style)
        const palette = [
            "#4285f4", // Blue
            "#0f9d58", // Green
            "#f4b400", // Yellow
            "#db4437", // Red
            "#ab47bc", // Purple
            "#00acc1", // Cyan
            "#ff7043", // Orange
            "#9e9e9e", // Gray
            "#5c6bc0", // Indigo
            "#26a69a", // Teal
            "#ec407a", // Pink
            "#8d6e63", // Brown
        ];

        // Generate a consistent index based on user ID
        let hash = 0;
        for (let i = 0; i < userId.length; i++) {
            hash = (hash << 5) - hash + userId.charCodeAt(i);
            hash = hash & hash;
        }

        const index = Math.abs(hash) % palette.length;
        const color = palette[index];

        // Store for future use
        const colors = this.getUserColors();
        if (!colors[userId]) {
            colors[userId] = color;
            this.storeUserColors(colors);
        }

        return colors[userId] || color;
    }

    /** @private */
    setupScrollHour() {
        if (this.options.scrollHour !== undefined) {
            this.scrollHour = this.options.scrollHour;
            return;
        }

        const scrollHour = this.getPreferences().get("calendarScrollHour");

        if (scrollHour !== null) {
            this.scrollHour = scrollHour;
            return;
        }

        if (this.slotDuration < 30) {
            this.scrollHour = 8;
        }
    }

    setupMode() {
        this.viewMode = this.mode;
        this.isCustomView = false;
        this.teamIdList = this.options.teamIdList || null;

        if (this.teamIdList && !this.teamIdList.length) {
            this.teamIdList = null;
        }

        if (~this.mode.indexOf("view-")) {
            this.viewId = this.mode.slice(5);
            this.isCustomView = true;

            const calendarViewDataList =
                this.getPreferences().get("calendarViewDataList") || [];

            calendarViewDataList.forEach((item) => {
                if (item.id === this.viewId) {
                    this.viewMode = item.mode;
                    this.teamIdList = item.teamIdList;
                    this.viewName = item.name;
                }
            });
        }
    }

    /**
     * @param {string} mode
     */
    selectMode(mode) {
        // Map external mode names to FullCalendar view names
        const externalToFcModeMap = {
            resourceDay: "resourceTimeGridDay",
            resourceWeek: "resourceTimeGridWeek",
        };

        const fcMode = externalToFcModeMap[mode] || mode;

        if (
            this.fullCalendarModeList.includes(fcMode) ||
            mode.indexOf("view-") === 0
        ) {
            const previousMode = this.mode;

            if (
                mode.indexOf("view-") === 0 ||
                (mode.indexOf("view-") !== 0 &&
                    previousMode.indexOf("view-") === 0)
            ) {
                this.trigger("change:mode", mode, true);
                return;
            }

            // Store the FullCalendar mode name for internal use
            this.mode = fcMode;
            this.setupMode();

            if (this.isCustomView) {
                this.$el
                    .find('button[data-action="editCustomView"]')
                    .removeClass("hidden");
            } else {
                this.$el
                    .find('button[data-action="editCustomView"]')
                    .addClass("hidden");
            }

            this.$el.find('[data-action="mode"]').removeClass("active");
            // Highlight button using the original mode name (matches data-mode attribute)
            this.$el.find('[data-mode="' + mode + '"]').addClass("active");

            this.calendar.changeView(
                this.modeViewMap[this.viewMode] || this.viewMode,
            );

            if (!this.fetching) {
                this.calendar.refetchEvents();
            }

            this.updateDate();

            if (this.hasView("modeButtons")) {
                this.getModeButtonsView().mode = mode;
                this.getModeButtonsView().reRender();
            }
        }

        this.trigger("change:mode", mode);
    }

    /** @return {import('modules/crm/views/calendar/mode-buttons').default} */
    getModeButtonsView() {
        return this.getView("modeButtons");
    }

    /** @private @param {string} name */
    toggleScopeFilter(name) {
        const index = this.enabledScopeList.indexOf(name);

        if (!~index) {
            this.enabledScopeList.push(name);
        } else {
            this.enabledScopeList.splice(index, 1);
        }

        this.storeEnabledScopeList(this.enabledScopeList);
        this.calendar.refetchEvents();
    }

    /** @private @return {string[]|null} */
    getStoredEnabledScopeList() {
        const key = "calendarEnabledScopeList";
        return this.getStorage().get("state", key) || null;
    }

    /** @private @param {string[]} enabledScopeList */
    storeEnabledScopeList(enabledScopeList) {
        const key = "calendarEnabledScopeList";
        this.getStorage().set("state", key, enabledScopeList);
    }

    /**
     * Toggle user filter visibility
     * @param {string} userId
     */
    toggleUserFilter(userId) {
        const index = this.enabledUserIdList.indexOf(userId);

        if (index === -1) {
            this.enabledUserIdList.push(userId);
        } else {
            // Don't allow disabling all users
            if (this.enabledUserIdList.length > 1) {
                this.enabledUserIdList.splice(index, 1);
            }
        }

        this.storeEnabledUserIdList(this.enabledUserIdList);

        // Update calendar resources
        if (this.calendar) {
            // Get current resources and remove them
            const currentResources = this.calendar.getResources();
            currentResources.forEach((r) => r.remove());

            // Add new resources (only enabled users)
            this.getResources().forEach((resource) => {
                this.calendar.addResource(resource);
            });

            // Refetch events
            this.calendar.refetchEvents();
        }
    }

    /**
     * Handle user filter change from mode buttons
     * Called by mode-buttons view when user toggles filters
     * @param {string[]} enabledUserIdList
     */
    onUserFilterChange(enabledUserIdList) {
        this.enabledUserIdList = enabledUserIdList;
        this.storeEnabledUserIdList(this.enabledUserIdList);

        // Update calendar resources
        if (this.calendar) {
            // Get current resources and remove them
            const currentResources = this.calendar.getResources();
            currentResources.forEach((r) => r.remove());

            // Add new resources (only enabled users)
            this.getResources().forEach((resource) => {
                this.calendar.addResource(resource);
            });

            // Refetch events
            this.calendar.refetchEvents();
        }
    }

    /**
     * Handle user color change from mode buttons
     * Called by mode-buttons view when user changes color
     * @param {string} userId
     * @param {string} color
     */
    onUserColorChange(userId, color) {
        // Update local cache
        this.userColors[userId] = color;

        // Refetch events to apply new color
        if (this.calendar) {
            this.calendar.refetchEvents();
        }
    }

    /**
     * Open color picker for a user
     * @param {Event} e
     */
    async actionChangeUserColor(e) {
        const $target = $(e.currentTarget);
        const userId = $target.data("user-id");
        const currentColor =
            this.userColors[userId] || this.getColorForUser(userId);

        // Close any existing color picker
        if (this.colorPickerView) {
            this.colorPickerView.close();
            this.colorPickerView = null;
        }

        // Create a container for the color picker in body
        const $container = $('<div class="color-picker-container">').appendTo(
            "body",
        );

        // Create and show color picker popover
        this.colorPickerView = await this.createView(
            "colorPicker",
            "global:views/calendar/color-picker-popover",
            {
                fullSelector: ".color-picker-container",
                userId: userId,
                currentColor: currentColor,
                targetEl: e.currentTarget,
            },
        );

        this.listenTo(
            this.colorPickerView,
            "select",
            (color, selectedUserId) => {
                this.setUserColor(selectedUserId, color);
            },
        );

        this.listenTo(this.colorPickerView, "close", () => {
            this.clearView("colorPicker");
            this.colorPickerView = null;
            $container.remove();
        });

        await this.colorPickerView.render();
    }

    /**
     * Set color for a user
     * @param {string} userId
     * @param {string} color
     */
    setUserColor(userId, color) {
        this.userColors[userId] = color;
        this.storeUserColors(this.userColors);

        // Update the color swatch in the UI
        this.$el
            .find(`[data-action="changeUserColor"][data-user-id="${userId}"]`)
            .css("background-color", color);

        // Refetch events to apply new color
        if (this.calendar) {
            this.calendar.refetchEvents();
        }
    }

    /** @private */
    updateDate() {
        if (!this.header) {
            return;
        }

        if (this.isToday()) {
            this.$el.find('button[data-action="today"]').addClass("active");
        } else {
            this.$el.find('button[data-action="today"]').removeClass("active");
        }

        const title = this.getTitle();
        this.$el.find(".date-title h4 span").text(title);
    }

    /** @private @return {boolean} */
    isToday() {
        const view = this.calendar.view;
        const todayUnix = moment().unix();
        const startUnix = moment(view.activeStart).unix();
        const endUnix = moment(view.activeEnd).unix();

        return startUnix <= todayUnix && todayUnix < endUnix;
    }

    /** @private @return {string} */
    getTitle() {
        const view = this.calendar.view;

        const map = {
            resourceTimeGridWeek: "week",
            resourceTimeGridDay: "day",
        };

        const viewName = map[view.type] || "day";

        let title;
        const format = this.titleFormat[viewName];

        if (viewName === "week") {
            const start = this.dateToMoment(view.currentStart).format(format);
            const end = this.dateToMoment(view.currentEnd)
                .subtract(1, "minute")
                .format(format);

            title = start !== end ? start + this.rangeSeparator + end : start;
        } else {
            title = this.dateToMoment(view.currentStart).format(format);
        }

        title = this.getHelper().escapeString(title);

        return title;
    }

    /**
     * Convert raw event data to FullCalendar event format
     * @private
     * @param {Object.<string, *>} o
     * @return {Object}
     */
    convertToFcEvent(o) {
        const event = {
            title: o.name || "",
            scope: o.scope,
            id: o.scope + "-" + o.id,
            recordId: o.id,
            dateStart: o.dateStart,
            dateEnd: o.dateEnd,
            dateStartDate: o.dateStartDate,
            dateEndDate: o.dateEndDate,
            status: o.status,
            originalColor: o.color,
            display: "block",
            resourceId: o.userId || o.assignedUserId || this.getUser().id,
        };

        if (o.isWorkingRange) {
            event.display = "inverse-background";
            event.groupId = "nonWorking";
            event.color = this.colors["bg"];
        }

        this.eventAttributes.forEach((attr) => {
            event[attr] = o[attr];
        });

        let start;
        let end;

        if (o.dateStart || o.dateStartDate) {
            start = !o.dateStartDate
                ? this.getDateTime().toMoment(o.dateStart)
                : this.dateToMoment(o.dateStartDate);
        }

        if (o.dateEnd || o.dateEndDate) {
            end = !o.dateEndDate
                ? this.getDateTime().toMoment(o.dateEnd)
                : this.dateToMoment(o.dateEndDate);
        }

        if (end && start) {
            event.duration = end.unix() - start.unix();
        }

        if (start) {
            event.start = start.toISOString(true);
        }

        if (end) {
            event.end = end.toISOString(true);
        }

        event.allDay = false;

        if (!o.isWorkingRange) {
            this.handleAllDay(event);
            this.fillColor(event);
            this.handleStatus(event);
        }

        return event;
    }

    /** @private @param {string|Date} date @return {moment.Moment} */
    dateToMoment(date) {
        return moment.tz(date, this.getDateTime().getTimeZone());
    }

    /** @private @param {string} scope @return {string[]} */
    getEventTypeCompletedStatusList(scope) {
        return (
            this.getMetadata().get(["scopes", scope, "completedStatusList"]) ||
            []
        );
    }

    /** @private @param {string} scope @return {string[]} */
    getEventTypeCanceledStatusList(scope) {
        return (
            this.getMetadata().get(["scopes", scope, "canceledStatusList"]) ||
            []
        );
    }

    /** @private @param {Record} event */
    fillColor(event) {
        let color = this.colors[event.scope];

        // Apply user color if multiple users are displayed
        if (this.enabledUserIdList.length > 1 && event.resourceId) {
            const userColor =
                this.userColors[event.resourceId] ||
                this.getColorForUser(event.resourceId);
            if (userColor && !event.originalColor) {
                color = userColor;
            }
        }

        if (event.originalColor) {
            color = event.originalColor;
        }

        if (!color) {
            color = this.getColorFromScopeName(event.scope);
        }

        if (
            color &&
            (this.getEventTypeCompletedStatusList(event.scope).includes(
                event.status,
            ) ||
                this.getEventTypeCanceledStatusList(event.scope).includes(
                    event.status,
                ))
        ) {
            color = this.shadeColor(color, 0.4);
        }

        event.color = color;
    }

    /** @private @param {Object} event */
    handleStatus(event) {
        if (
            this.getEventTypeCanceledStatusList(event.scope).includes(
                event.status,
            )
        ) {
            event.className = ["event-canceled"];
        } else {
            event.className = [];
        }
    }

    /** @private @param {string} color @param {number} percent @return {string} */
    shadeColor(color, percent) {
        if (color === "transparent") {
            return color;
        }

        if (this.getThemeManager().getParam("isDark")) {
            percent *= -1;
        }

        const alpha = color.substring(7);
        const f = parseInt(color.slice(1, 7), 16),
            t = percent < 0 ? 0 : 255,
            p = percent < 0 ? percent * -1 : percent,
            R = f >> 16,
            G = (f >> 8) & 0x00ff,
            B = f & 0x0000ff;

        return (
            "#" +
            (
                0x1000000 +
                (Math.round((t - R) * p) + R) * 0x10000 +
                (Math.round((t - G) * p) + G) * 0x100 +
                (Math.round((t - B) * p) + B)
            )
                .toString(16)
                .slice(1) +
            alpha
        );
    }

    /** @private @param {EventImpl} event @param {boolean} [afterDrop] */
    handleAllDay(event, afterDrop) {
        let start = event.start ? this.dateToMoment(event.start) : null;
        const end = event.end ? this.dateToMoment(event.end) : null;

        if (this.allDayScopeList.includes(event.scope)) {
            event.allDay = event.allDayCopy = true;

            if (!afterDrop && end) {
                start = end.clone();

                if (
                    !event.dateEndDate &&
                    end.hours() === 0 &&
                    end.minutes() === 0
                ) {
                    start.add(-1, "days");
                }
            }

            if (start && end && start.isSame(end)) {
                end.add(1, "days");
            }

            if (start) {
                event.start = start.toDate();
            }

            if (end) {
                event.end = end.toDate();
            }

            return;
        }

        if (event.dateStartDate && event.dateEndDate) {
            event.allDay = true;
            event.allDayCopy = event.allDay;

            if (!afterDrop && end) {
                end.add(1, "days");
            }

            if (start) {
                event.start = start.toDate();
            }

            if (end) {
                event.end = end.toDate();
            }

            return;
        }

        if (!start || !end) {
            event.allDay = true;

            if (end) {
                start = end;
            }
        } else if (
            start.format("YYYY-DD") !== end.format("YYYY-DD") &&
            end.unix() - start.unix() >= 86400
        ) {
            event.allDay = true;

            if (end.hours() !== 0 || end.minutes() !== 0) {
                end.add(1, "days");
            }
        } else {
            event.allDay = false;
        }

        event.allDayCopy = event.allDay;

        if (start) {
            event.start = start.toDate();
        }

        if (end) {
            event.end = end.toDate();
        }
    }

    /** @private @param {Record[]} list @return {Record[]} */
    convertToFcEvents(list) {
        this.now = moment.tz(this.getDateTime().getTimeZone());

        const events = [];

        list.forEach((o) => {
            const event = this.convertToFcEvent(o);
            events.push(event);
        });

        return events;
    }

    /** @private @param {string} date @return {string} */
    convertDateTime(date) {
        const format = this.getDateTime().internalDateTimeFormat;
        const timeZone = this.getDateTime().timeZone;

        const m = timeZone
            ? moment.tz(date, null, timeZone).utc()
            : moment.utc(date, null);

        return m.format(format) + ":00";
    }

    /** @private @return {number} */
    getCalculatedHeight() {
        if (this.$container && this.$container.length) {
            return this.$container.height();
        }

        return this.getHelper().calculateContentContainerHeight(
            this.$el.find(".calendar"),
        );
    }

    /** @private */
    adjustSize() {
        if (this.isRemoved()) {
            return;
        }

        const height = this.getCalculatedHeight();

        this.calendar.setOption("contentHeight", height);
        this.calendar.updateSize();
    }

    /**
     * Get resources (users) for the calendar
     * @private
     * @return {Array<{id: string, title: string}>}
     */
    getResources() {
        return this.userList
            .filter((user) => this.enabledUserIdList.includes(user.id))
            .map((user) => ({
                id: user.id,
                title: user.name,
            }));
    }

    afterRender() {
        if (this.options.containerSelector) {
            this.$container = $(this.options.containerSelector);
        }

        this.$calendar = this.$el.find("div.calendar");

        // Check if resource plugins are available
        if (!this.resourcePluginsLoaded) {
            const errorDetail = this.pluginLoadError
                ? `<p class="text-danger"><strong>Error:</strong> ${this.getHelper().escapeString(this.pluginLoadError)}</p>`
                : "";

            this.$calendar.html(`
                <div class="alert alert-warning" style="margin: 20px;">
                    <h4><span class="fas fa-exclamation-triangle"></span> Resource Calendar Not Available</h4>
                    <p>The Resource Calendar requires FullCalendar Scheduler plugins which are not installed or failed to load.</p>
                    ${errorDetail}
                    <p>Options:</p>
                    <ul>
                        <li>Use the <strong>Timeline</strong> view which provides similar resource grouping functionality</li>
                        <li>Clear cache and try again</li>
                        <li>Check browser console for detailed errors</li>
                    </ul>
                    <p><a href="#Calendar?mode=timeline" class="btn btn-default">Switch to Timeline View</a></p>
                </div>
            `);
            return;
        }

        const slotDuration = "00:" + this.slotDuration + ":00";
        const timeFormat = this.getDateTime().timeFormat;

        let slotLabelFormat = timeFormat;

        if (~timeFormat.indexOf("a")) {
            slotLabelFormat = "h:mma";
        } else if (~timeFormat.indexOf("A")) {
            slotLabelFormat = "h:mmA";
        }

        /** @type {CalendarOptions & Object.<string, *>} */
        const options = {
            schedulerLicenseKey: "GPL-My-Project-Is-Open-Source",
            scrollTime: this.scrollHour + ":00",
            headerToolbar: false,
            slotLabelFormat: slotLabelFormat,
            eventTimeFormat: timeFormat,
            initialView:
                this.modeViewMap[this.viewMode] || "resourceTimeGridDay",
            defaultRangeSeparator: this.rangeSeparator,
            weekNumbers: true,
            weekNumberCalculation: "ISO",
            editable: true,
            selectable: true,
            selectMirror: true,
            height: this.options.height || void 0,
            firstDay: this.getDateTime().weekStart,
            slotEventOverlap: true,
            slotDuration: slotDuration,
            slotLabelInterval: "01:00",
            snapDuration: this.slotDuration * 60 * 1000,
            timeZone: this.getDateTime().timeZone || undefined,
            longPressDelay: 300,
            eventColor: this.colors[""],
            nowIndicator: true,
            allDayText: "",
            weekText: "",
            resources: this.getResources(),
            resourceLabelContent: (arg) => {
                const resource = arg.resource;
                const avatarHtml =
                    this.getHelper().getAvatarHtml(resource.id, "small", 14) ||
                    "";

                return {
                    html: `<span class="resource-label">${avatarHtml} ${this.getHelper().escapeString(resource.title)}</span>`,
                };
            },
            views: {
                resourceTimeGridDay: {
                    dayHeaderFormat: "ddd DD",
                },
                resourceTimeGridWeek: {
                    dayHeaderFormat: "ddd DD",
                },
            },
            windowResize: () => {
                this.adjustSize();
            },
            select: (info) => {
                const start = info.startStr;
                const end = info.endStr;
                const allDay = info.allDay;
                const resourceId = info.resource?.id;

                let dateEndDate = null;
                let dateStartDate = null;

                const dateStart = this.convertDateTime(start);
                const dateEnd = this.convertDateTime(end);

                if (allDay) {
                    dateStartDate = moment(start).format("YYYY-MM-DD");
                    dateEndDate = moment(end)
                        .clone()
                        .add(-1, "days")
                        .format("YYYY-MM-DD");
                }

                this.createEvent({
                    dateStart: dateStart,
                    dateEnd: dateEnd,
                    allDay: allDay,
                    dateStartDate: dateStartDate,
                    dateEndDate: dateEndDate,
                    assignedUserId: resourceId,
                });

                this.calendar.unselect();
            },
            eventClick: async (info) => {
                const event = info.event;
                const scope = event.extendedProps.scope;
                const recordId = event.extendedProps.recordId;

                const helper = new RecordModal();

                let modalView;

                modalView = await helper.showDetail(this, {
                    entityType: scope,
                    id: recordId,
                    removeDisabled: false,
                    beforeSave: () => {
                        if (this.options.onSave) {
                            this.options.onSave();
                        }
                    },
                    beforeDestroy: () => {
                        if (this.options.onSave) {
                            this.options.onSave();
                        }
                    },
                    afterSave: (model, o) => {
                        if (!o.bypassClose) {
                            modalView.close();
                        }
                        this.updateModel(model);
                    },
                    afterDestroy: (model) => {
                        this.removeModel(model);
                    },
                });
            },
            datesSet: () => {
                this.date = this.dateToMoment(this.calendar.getDate()).format(
                    "YYYY-MM-DD",
                );
                this.trigger("view", this.date, this.mode);
            },
            events: (info, callback) => {
                const dateTimeFormat =
                    this.getDateTime().internalDateTimeFormat;

                const from = moment.tz(info.startStr, info.timeZone);
                const to = moment.tz(info.endStr, info.timeZone);

                const fromStr = from.utc().format(dateTimeFormat);
                const toStr = to.utc().format(dateTimeFormat);

                this.fetchEvents(fromStr, toStr, callback);
            },
            eventDrop: async (info) => {
                const event = info.event;
                const delta = info.delta;
                const newResource = info.newResource;

                const scope = event.extendedProps.scope;

                if (this.onlyDateScopeList.includes(scope)) {
                    info.revert();
                    return;
                }

                if (!event.allDay && event.extendedProps.allDayCopy) {
                    info.revert();
                    return;
                }

                if (event.allDay && !event.extendedProps.allDayCopy) {
                    info.revert();
                    return;
                }

                const dateStart = event.extendedProps.dateStart;
                const dateEnd = event.extendedProps.dateEnd;
                const dateStartDate = event.extendedProps.dateStartDate;
                const dateEndDate = event.extendedProps.dateEndDate;

                const attributes = {};

                if (dateStart) {
                    const dateString = this.getDateTime()
                        .toMoment(dateStart)
                        .add(delta)
                        .format(this.getDateTime().internalDateTimeFormat);

                    attributes.dateStart = this.convertDateTime(dateString);
                }

                if (dateEnd) {
                    const dateString = this.getDateTime()
                        .toMoment(dateEnd)
                        .add(delta)
                        .format(this.getDateTime().internalDateTimeFormat);

                    attributes.dateEnd = this.convertDateTime(dateString);
                }

                if (dateStartDate) {
                    const m = this.dateToMoment(dateStartDate).add(delta);
                    attributes.dateStartDate = m.format(
                        this.getDateTime().internalDateFormat,
                    );
                }

                if (dateEndDate) {
                    const m = this.dateToMoment(dateEndDate).add(delta);
                    attributes.dateEndDate = m.format(
                        this.getDateTime().internalDateFormat,
                    );
                }

                // Handle resource change (user reassignment)
                if (newResource) {
                    attributes.assignedUserId = newResource.id;
                }

                Espo.Ui.notify(this.translate("saving", "messages"));

                const model = await this.getModelFactory().create(scope);
                model.id = event.extendedProps.recordId;

                if (this.options.onSave) {
                    this.options.onSave();
                }

                try {
                    await model.save(attributes, { patch: true });
                } catch (e) {
                    info.revert();
                    return;
                }

                Espo.Ui.notify();

                // Update event properties
                event.setExtendedProp(
                    "dateStart",
                    attributes.dateStart || dateStart,
                );
                event.setExtendedProp("dateEnd", attributes.dateEnd || dateEnd);
            },
            eventResize: async (info) => {
                const event = info.event;

                const attributes = {
                    dateEnd: this.convertDateTime(event.endStr),
                };

                const duration =
                    moment(event.end).unix() - moment(event.start).unix();

                Espo.Ui.notify(this.translate("saving", "messages"));

                const model = await this.getModelFactory().create(
                    event.extendedProps.scope,
                );
                model.id = event.extendedProps.recordId;

                if (this.options.onSave) {
                    this.options.onSave();
                }

                try {
                    await model.save(attributes, { patch: true });
                } catch (e) {
                    info.revert();
                    return;
                }

                Espo.Ui.notify();

                event.setExtendedProp("dateEnd", attributes.dateEnd);
                event.setExtendedProp("duration", duration);
            },
            eventAllow: (info, event) => {
                if (event.allDay && !info.allDay) {
                    return false;
                }

                if (!event.allDay && info.allDay) {
                    return false;
                }

                return true;
            },
        };

        if (!this.options.height) {
            options.contentHeight = this.getCalculatedHeight();
        } else {
            options.aspectRatio = 1.62;
        }

        if (this.date) {
            options.initialDate = this.date;
        } else {
            this.$el.find('button[data-action="today"]').addClass("active");
        }

        setTimeout(() => {
            this.calendar = new FullCalendar.Calendar(
                this.$calendar.get(0),
                options,
            );
            this.calendar.render();

            this.handleScrollToNow();
            this.updateDate();

            if (this.$container && this.$container.length) {
                this.adjustSize();
            }
        }, 150);
    }

    /** @private */
    handleScrollToNow() {
        if (
            !(
                this.mode === "resourceTimeGridWeek" ||
                this.mode === "resourceTimeGridDay"
            )
        ) {
            return;
        }

        if (!this.isToday()) {
            return;
        }

        const scrollHour =
            this.getDateTime().getNowMoment().hours() -
            Math.floor((this.slotDuration * this.scrollToNowSlots) / 60);

        if (scrollHour < 0) {
            return;
        }

        this.calendar.scrollToTime(scrollHour + ":00");
    }

    /**
     * @param {{
     *   [allDay]: boolean,
     *   [dateStart]: string,
     *   [dateEnd]: string,
     *   [dateStartDate]: ?string,
     *   [dateEndDate]: ?string,
     *   [assignedUserId]: ?string,
     * }} [values]
     */
    async createEvent(values) {
        values = values || {};

        if (
            !values.dateStart &&
            this.date !== this.getDateTime().getToday() &&
            this.mode === "resourceTimeGridDay"
        ) {
            values.allDay = true;
            values.dateStartDate = this.date;
            values.dateEndDate = this.date;
        }

        const attributes = {};

        if (values.assignedUserId) {
            attributes.assignedUserId = values.assignedUserId;

            // Find user name from userList
            const user = this.userList.find(
                (u) => u.id === values.assignedUserId,
            );
            if (user) {
                attributes.assignedUserName = user.name;
            }
        } else if (this.options.userId) {
            attributes.assignedUserId = this.options.userId;
            attributes.assignedUserName =
                this.options.userName || this.options.userId;
        }

        const scopeList = this.enabledScopeList.filter(
            (it) => !this.onlyDateScopeList.includes(it),
        );

        Espo.Ui.notifyWait();

        const view = await this.createView(
            "dialog",
            "crm:views/calendar/modals/edit",
            {
                attributes: attributes,
                enabledScopeList: scopeList,
                scopeList: this.scopeList,
                allDay: values.allDay,
                dateStartDate: values.dateStartDate,
                dateEndDate: values.dateEndDate,
                dateStart: values.dateStart,
                dateEnd: values.dateEnd,
            },
        );

        let added = false;

        this.listenTo(view, "before:save", () => {
            if (this.options.onSave) {
                this.options.onSave();
            }
        });

        this.listenTo(view, "after:save", (model) => {
            if (!added) {
                this.addModel(model);
                added = true;
                return;
            }

            this.updateModel(model);
        });

        await view.render();

        Espo.Ui.notify();
    }

    /**
     * Fetch events from the API
     * @private
     * @param {string} from
     * @param {string} to
     * @param {function} callback
     */
    fetchEvents(from, to, callback) {
        let url = `Activities?from=${from}&to=${to}`;

        // Build user ID list for fetching - only enabled users
        const userIdList = this.enabledUserIdList;

        if (userIdList.length === 1) {
            url += "&userId=" + userIdList[0];
        } else if (userIdList.length > 1) {
            url += "&userIdList=" + encodeURIComponent(userIdList.join(","));
        }

        url +=
            "&scopeList=" + encodeURIComponent(this.enabledScopeList.join(","));

        // Note: Don't send teamIdList when userIdList is present, as the backend
        // prioritizes teamIdList and ignores userIdList when both are provided.
        // The resource calendar needs specific users, not all team members.

        url += "&agenda=true";

        if (!this.suppressLoadingAlert) {
            Espo.Ui.notifyWait();
        }

        Espo.Ajax.getRequest(url).then((data) => {
            const events = this.convertToFcEvents(data);

            callback(events);

            Espo.Ui.notify(false);
        });

        this.fetching = true;
        this.suppressLoadingAlert = false;

        setTimeout(() => (this.fetching = false), 50);
    }

    /** @private @param {import('model').default} model */
    addModel(model) {
        const attributes = model.getClonedAttributes();

        attributes.scope = model.entityType;

        const event = this.convertToFcEvent(attributes);

        this.calendar.addEvent(event, true);
    }

    /** @private @param {import('model').default} model */
    updateModel(model) {
        const eventId = model.entityType + "-" + model.id;

        const event = this.calendar.getEventById(eventId);

        if (!event) {
            return;
        }

        const attributes = model.getClonedAttributes();

        attributes.scope = model.entityType;

        const data = this.convertToFcEvent(attributes);

        // Update event properties
        for (const key in data) {
            if (this.extendedProps.includes(key)) {
                event.setExtendedProp(key, data[key]);
            } else if (key === "start" || key === "end") {
                // Handle start/end separately
            } else if (key === "className") {
                event.setProp("classNames", data[key]);
            } else if (key === "resourceId") {
                // Handle resource change
                const resources = event.getResources();
                if (resources.length && resources[0].id !== data[key]) {
                    event.setResources([data[key]]);
                }
            } else {
                try {
                    event.setProp(key, data[key]);
                } catch (e) {
                    // Some props can't be set
                }
            }
        }

        if (data.start || data.end) {
            event.setDates(data.start, data.end, { allDay: data.allDay });
        }
    }

    /** @private @param {import('model').default} model */
    removeModel(model) {
        const event = this.calendar.getEventById(
            model.entityType + "-" + model.id,
        );

        if (!event) {
            return;
        }

        event.remove();
    }

    /** @param {{suppressLoadingAlert: boolean}} [options] */
    actionRefresh(options) {
        if (options && options.suppressLoadingAlert) {
            this.suppressLoadingAlert = true;
        }

        this.calendar.refetchEvents();
    }

    actionPrevious() {
        this.calendar.prev();
        this.handleScrollToNow();
        this.updateDate();
    }

    actionNext() {
        this.calendar.next();
        this.handleScrollToNow();
        this.updateDate();
    }

    /** @private @param {string} scope @return {string|undefined} */
    getColorFromScopeName(scope) {
        const additionalColorList =
            this.getMetadata().get("clientDefs.Calendar.additionalColorList") ||
            [];

        if (!additionalColorList.length) {
            return;
        }

        const colors =
            this.getMetadata().get("clientDefs.Calendar.colors") || {};
        const scopeList = this.getConfig().get("calendarEntityList") || [];

        let index = 0;
        let j = 0;

        for (let i = 0; i < scopeList.length; i++) {
            if (scopeList[i] in colors) {
                continue;
            }

            if (scopeList[i] === scope) {
                index = j;
                break;
            }

            j++;
        }

        index = index % additionalColorList.length;
        this.colors[scope] = additionalColorList[index];

        return this.colors[scope];
    }

    actionToday() {
        if (this.isToday()) {
            this.actionRefresh();
            return;
        }

        this.calendar.today();
        this.handleScrollToNow();
        this.updateDate();
    }

    /**
     * Show manage users modal to select users (same as agendaWeek)
     */
    async actionShowResourceOptions() {
        const viewName = "global:views/calendar/modals/manage-users";

        const view = await this.createView("manageUsers", viewName, {
            users: this.userList,
        });

        this.listenTo(view, "apply", (data) => {
            this.userList = data.users;
            this.userFilterList = data.users;

            this.storeUserList();

            // Update enabled user list - only keep users that are still in the list
            this.enabledUserIdList = this.enabledUserIdList.filter((id) =>
                data.users.some((u) => u.id === id),
            );

            // Add new users to enabled list
            data.users.forEach((user) => {
                if (!this.enabledUserIdList.includes(user.id)) {
                    this.enabledUserIdList.push(user.id);
                }
            });

            // Ensure at least one user is enabled
            if (this.enabledUserIdList.length === 0 && data.users.length > 0) {
                this.enabledUserIdList.push(data.users[0].id);
            }

            this.storeEnabledUserIdList(this.enabledUserIdList);

            // Update calendar resources
            if (this.calendar) {
                // Get current resources and remove them
                const currentResources = this.calendar.getResources();
                currentResources.forEach((r) => r.remove());

                // Add new resources
                this.getResources().forEach((resource) => {
                    this.calendar.addResource(resource);
                });

                // Refetch events for new resources
                this.calendar.refetchEvents();
            }

            // Update mode buttons with new user list
            if (this.hasView("modeButtons")) {
                const modeButtons = this.getModeButtonsView();
                modeButtons.updateUserFilterList(data.users);
            }
        });

        await view.render();
    }
}

export default ResourceCalendarView;

