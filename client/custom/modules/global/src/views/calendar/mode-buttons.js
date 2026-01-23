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
 * Extended Calendar Mode Buttons with User Filter (Google Calendar style)
 *
 * Adds user checkboxes in the dropdown to show/hide events from different users
 * without changing the calendar view mode.
 */

import ModeButtonsBase from 'crm:views/calendar/mode-buttons';

class ModeButtons extends ModeButtonsBase {

    template = 'global:calendar/mode-buttons'

    /**
     * @private
     * @type {Array<{id: string, name: string}>}
     */
    userFilterList = []

    /**
     * @private
     * @type {string[]}
     */
    enabledUserIdList = []

    /**
     * @private
     * @type {import('./color-picker-popover').default|null}
     */
    colorPickerView = null

    events = {
        ...ModeButtonsBase.prototype.events,
        /** @this ModeButtons */
        'click [data-action="toggleUserFilter"]': function (e) {
            const $target = $(e.currentTarget);
            const userId = $target.data('user-id');
            const $check = $target.find('.filter-check-icon');

            if ($check.hasClass('hidden')) {
                $check.removeClass('hidden');
            } else {
                // Don't hide if it's the last enabled user
                if (this.enabledUserIdList.length > 1) {
                    $check.addClass('hidden');
                }
            }

            e.stopPropagation();
            this.toggleUserFilter(userId);
        },
        /** @this ModeButtons */
        'click [data-action="manageUsers"]': function (e) {
            e.stopPropagation();
            this.trigger('manageUsers');
        },
        /** @this ModeButtons */
        'click [data-action="changeUserColor"]': function (e) {
            e.stopPropagation();
            e.preventDefault();
            this.actionChangeUserColor(e);
        },
    }

    data() {
        const parentData = super.data();

        return {
            ...parentData,
            userFilterDataList: this.getUserFilterDataList(),
            hasUserFilter: this.userFilterList.length > 0,
        };
    }

    setup() {
        super.setup();

        // Load available users for filtering
        this.loadUserFilterList();

        // Load enabled users from storage
        this.enabledUserIdList = this.getStoredEnabledUserIdList();
    }

    /**
     * Get user filter data for template
     * @private
     * @return {Array<{id: string, name: string, disabled: boolean, color: string}>}
     */
    getUserFilterDataList() {
        const colors = this.getUserColors();

        return this.userFilterList.map(user => ({
            id: user.id,
            name: user.name,
            disabled: !this.enabledUserIdList.includes(user.id),
            color: colors[user.id] || this.getColorForUser(user.id),
        }));
    }

    /**
     * Load users available for filtering
     * @private
     */
    loadUserFilterList() {
        // Get from parent calendar view if available
        const calendarView = this.getCalendarParentView();

        if (calendarView && calendarView.userFilterList) {
            this.userFilterList = calendarView.userFilterList;
            return;
        }

        // Default: just current user
        this.userFilterList = [{
            id: this.getUser().id,
            name: this.getUser().get('name'),
        }];
    }

    /**
     * Get stored enabled user ID list
     * @private
     * @return {string[]}
     */
    getStoredEnabledUserIdList() {
        const stored = this.getStorage().get('state', 'calendarEnabledUserIdList');

        if (stored && stored.length) {
            return stored;
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
     * Get user colors from preferences (persisted on server)
     * @private
     * @return {Object<string, string>}
     */
    getUserColors() {
        return this.getPreferences().get('calendarUserColors') || {};
    }

    /**
     * Store user colors in preferences (persisted on server)
     * @private
     * @param {Object<string, string>} colors
     */
    storeUserColors(colors) {
        this.getPreferences().save({
            'calendarUserColors': colors,
        }, {patch: true});
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
            '#4285f4', // Blue
            '#0f9d58', // Green
            '#f4b400', // Yellow
            '#db4437', // Red
            '#ab47bc', // Purple
            '#00acc1', // Cyan
            '#ff7043', // Orange
            '#9e9e9e', // Gray
            '#5c6bc0', // Indigo
            '#26a69a', // Teal
            '#ec407a', // Pink
            '#8d6e63', // Brown
        ];

        // Generate a consistent index based on user ID
        let hash = 0;
        for (let i = 0; i < userId.length; i++) {
            hash = ((hash << 5) - hash) + userId.charCodeAt(i);
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

        // Notify parent calendar to refetch events
        const calendarView = this.getCalendarParentView();
        if (calendarView && calendarView.onUserFilterChange) {
            calendarView.onUserFilterChange(this.enabledUserIdList);
        }

        this.reRender();
    }

    /**
     * Update user filter list (called from parent calendar)
     * @param {Array<{id: string, name: string}>} userList
     */
    updateUserFilterList(userList) {
        this.userFilterList = userList;

        // Ensure enabled list only contains valid users
        this.enabledUserIdList = this.enabledUserIdList.filter(id =>
            userList.some(u => u.id === id)
        );

        // If no users enabled, enable current user
        if (this.enabledUserIdList.length === 0 && userList.length > 0) {
            const currentUser = userList.find(u => u.id === this.getUser().id);
            if (currentUser) {
                this.enabledUserIdList.push(currentUser.id);
            } else {
                this.enabledUserIdList.push(userList[0].id);
            }
        }

        this.storeEnabledUserIdList(this.enabledUserIdList);
        this.reRender();
    }

    /**
     * Open color picker for a user
     * @param {Event} e
     */
    async actionChangeUserColor(e) {
        const $target = $(e.currentTarget);
        const userId = $target.data('user-id');
        const currentColor = this.getUserColors()[userId] || this.getColorForUser(userId);

        // Close any existing color picker
        if (this.colorPickerView) {
            this.colorPickerView.close();
            this.colorPickerView = null;
        }

        // Create a container for the color picker in body
        const $container = $('<div class="color-picker-container">').appendTo('body');

        // Create and show color picker popover using createView with fullSelector
        this.colorPickerView = await this.createView('colorPicker', 'global:views/calendar/color-picker-popover', {
            fullSelector: '.color-picker-container',
            userId: userId,
            currentColor: currentColor,
            targetEl: e.currentTarget,
        });

        this.listenTo(this.colorPickerView, 'select', (color, userId) => {
            this.setUserColor(userId, color);
        });

        this.listenTo(this.colorPickerView, 'close', () => {
            this.clearView('colorPicker');
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
        const colors = this.getUserColors();
        colors[userId] = color;
        this.storeUserColors(colors);

        // Update the color swatch in the UI
        this.$el.find(`[data-action="changeUserColor"][data-user-id="${userId}"]`)
            .css('background-color', color);

        // Notify parent calendar to update event colors
        const calendarView = this.getCalendarParentView();
        if (calendarView && calendarView.onUserColorChange) {
            calendarView.onUserColorChange(userId, color);
        }
    }
}

export default ModeButtons;
