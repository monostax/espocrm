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
 * Extended Calendar View with Multi-User Filter (Google Calendar style)
 *
 * Allows showing/hiding events from multiple users without changing the view mode.
 * Users can be toggled on/off via checkboxes in the dropdown menu.
 */

import CalendarView from 'crm:views/calendar/calendar';

class MultiUserCalendarView extends CalendarView {

    /**
     * List of users available for filtering
     * @type {Array<{id: string, name: string}>}
     */
    userFilterList = []

    /**
     * Currently enabled user IDs
     * @type {string[]}
     */
    enabledUserIdList = []

    /**
     * User colors for event styling
     * @type {Object<string, string>}
     */
    userColors = {}

    setup() {
        super.setup();

        // Load user filter list
        this.loadUserFilterList();

        // Load enabled users from storage
        this.enabledUserIdList = this.getStoredEnabledUserIdList();

        // Load user colors from preferences (persisted on server)
        this.userColors = this.getPreferences().get('calendarUserColors') || {};

        // Override mode buttons view
        if (this.header) {
            this.clearView('modeButtons');
            this.createView('modeButtons', 'global:views/calendar/mode-buttons', {
                selector: '.mode-buttons',
                isCustomViewAvailable: this.isCustomViewAvailable,
                modeList: this.modeList,
                scopeList: this.scopeList,
                mode: this.mode,
            }, view => {
                // Pass user filter list to mode buttons
                view.userFilterList = this.userFilterList;
                view.enabledUserIdList = this.enabledUserIdList;

                // Listen for manage users action
                this.listenTo(view, 'manageUsers', () => {
                    this.actionManageUsers();
                });
            });
        }
    }

    /**
     * Get mode buttons view
     * @return {import('./mode-buttons').default}
     */
    getModeButtonsView() {
        return this.getView('modeButtons');
    }

    /**
     * Load available users for filtering
     * @private
     */
    loadUserFilterList() {
        // Try to load from stored preferences first
        const stored = this.getStoredUserFilterList();

        if (stored && stored.length > 0) {
            this.userFilterList = stored;
            return;
        }

        // Default: current user only (will be expanded via Manage Users)
        this.userFilterList = [{
            id: this.getUser().id,
            name: this.getUser().get('name'),
        }];
    }

    /**
     * Get stored user filter list
     * @private
     * @return {Array<{id: string, name: string}>|null}
     */
    getStoredUserFilterList() {
        return this.getPreferences().get('calendarUserFilterList') || null;
    }

    /**
     * Store user filter list
     * @private
     * @param {Array<{id: string, name: string}>} list
     */
    storeUserFilterList(list) {
        this.getPreferences().save({
            'calendarUserFilterList': list,
        }, {patch: true});
    }

    /**
     * Get stored enabled user ID list
     * @private
     * @return {string[]}
     */
    getStoredEnabledUserIdList() {
        const stored = this.getStorage().get('state', 'calendarEnabledUserIdList');

        if (stored && stored.length) {
            // Filter to only include users in the filter list
            return stored.filter(id =>
                this.userFilterList.some(u => u.id === id)
            );
        }

        // Default: current user enabled
        return [this.getUser().id];
    }

    /**
     * Store enabled user ID list
     * @private
     * @param {string[]} list
     */
    storeEnabledUserIdList(list) {
        this.getStorage().set('state', 'calendarEnabledUserIdList', list);
    }

    /**
     * Toggle user filter
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

        // Refetch events
        this.calendar.refetchEvents();

        // Update mode buttons
        if (this.hasView('modeButtons')) {
            const modeButtons = this.getView('modeButtons');
            modeButtons.enabledUserIdList = this.enabledUserIdList;
            modeButtons.reRender();
        }
    }

    /**
     * Handle user filter change from mode buttons
     * @param {string[]} enabledUserIdList
     */
    onUserFilterChange(enabledUserIdList) {
        this.enabledUserIdList = enabledUserIdList;
        this.calendar.refetchEvents();
    }

    /**
     * Handle user color change from mode buttons
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
     * Override fetchEvents to support multi-user
     * @private
     * @param {string} from
     * @param {string} to
     * @param {function} callback
     */
    fetchEvents(from, to, callback) {
        let url = `Activities?from=${from}&to=${to}`;

        // Use enabled users for filtering
        if (this.enabledUserIdList.length === 1) {
            url += '&userId=' + this.enabledUserIdList[0];
        } else if (this.enabledUserIdList.length > 1) {
            url += '&userIdList=' + encodeURIComponent(this.enabledUserIdList.join(','));
        } else if (this.options.userId) {
            url += '&userId=' + this.options.userId;
        }

        url += '&scopeList=' + encodeURIComponent(this.enabledScopeList.join(','));

        if (this.teamIdList && this.teamIdList.length) {
            url += '&teamIdList=' + encodeURIComponent(this.teamIdList.join(','));
        }

        const agenda = this.mode === 'agendaWeek' || this.mode === 'agendaDay';
        url += '&agenda=' + encodeURIComponent(agenda);

        if (!this.suppressLoadingAlert) {
            Espo.Ui.notifyWait();
        }

        Espo.Ajax.getRequest(url).then(data => {
            const events = this.convertToFcEvents(data);
            callback(events);
            Espo.Ui.notify(false);
        });

        this.fetching = true;
        this.suppressLoadingAlert = false;

        setTimeout(() => this.fetching = false, 50);
    }

    /**
     * Override convertToFcEvent to add user color coding
     * @private
     * @param {Object.<string, *>} o
     * @return {Object}
     */
    convertToFcEvent(o) {
        const event = super.convertToFcEvent(o);

        // Add user color if multi-user mode and event has userId
        if (this.enabledUserIdList.length > 1 && o.userId) {
            const userColor = this.getUserColor(o.userId);
            if (userColor && !event.originalColor) {
                event.color = userColor;
                event.borderColor = userColor;
            }

            // Store userId for display
            event.userId = o.userId;
            event.userName = this.getUserName(o.userId);
        }

        return event;
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

        // Generate color based on user ID
        const palette = [
            '#4285f4', '#0f9d58', '#f4b400', '#db4437',
            '#ab47bc', '#00acc1', '#ff7043', '#9e9e9e',
            '#5c6bc0', '#26a69a', '#ec407a', '#8d6e63',
        ];

        let hash = 0;
        for (let i = 0; i < userId.length; i++) {
            hash = ((hash << 5) - hash) + userId.charCodeAt(i);
            hash = hash & hash;
        }

        return palette[Math.abs(hash) % palette.length];
    }

    /**
     * Get user name by ID
     * @private
     * @param {string} userId
     * @return {string}
     */
    getUserName(userId) {
        const user = this.userFilterList.find(u => u.id === userId);
        return user ? user.name : '';
    }

    /**
     * Open manage users modal
     */
    async actionManageUsers() {
        const view = await this.createView('manageUsers', 'global:views/calendar/modals/manage-users', {
            users: this.userFilterList,
        });

        this.listenTo(view, 'apply', data => {
            this.userFilterList = data.users;
            this.storeUserFilterList(data.users);

            // Update enabled list to only include valid users
            this.enabledUserIdList = this.enabledUserIdList.filter(id =>
                data.users.some(u => u.id === id)
            );

            // Ensure at least one user is enabled
            if (this.enabledUserIdList.length === 0 && data.users.length > 0) {
                this.enabledUserIdList.push(data.users[0].id);
            }

            this.storeEnabledUserIdList(this.enabledUserIdList);

            // Update mode buttons
            if (this.hasView('modeButtons')) {
                const modeButtons = this.getView('modeButtons');
                modeButtons.updateUserFilterList(data.users);
            }

            // Refetch events
            this.calendar.refetchEvents();
        });

        await view.render();
    }

    afterRender() {
        super.afterRender();

        // Add custom event content renderer for multi-user mode
        if (this.enabledUserIdList.length > 1 && this.calendar) {
            this.setupMultiUserEventContent();
        }
    }

    /**
     * Setup event content renderer for multi-user mode
     * Shows user indicator on events
     * @private
     */
    setupMultiUserEventContent() {
        // Event content is handled via color coding
        // Optionally add user name to event title in future
    }
}

export default MultiUserCalendarView;
