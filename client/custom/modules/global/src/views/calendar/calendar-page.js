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
 * Extended Calendar Page with Resource Calendar Support
 *
 * @module custom/modules/global/views/calendar/calendar-page
 */

import CalendarPageBase from 'crm:views/calendar/calendar-page';

class CalendarPage extends CalendarPageBase {

    resourceCalendarModeList = ['resourceDay', 'resourceWeek']

    setup() {
        this.mode = this.mode || this.options.mode || null;
        this.date = this.date || this.options.date || null;

        if (!this.mode) {
            this.mode = this.getStorage().get('state', 'calendarMode') || null;

            if (this.mode && this.mode.indexOf('view-') === 0) {
                const viewId = this.mode.slice(5);
                const calendarViewDataList = this.getPreferences().get('calendarViewDataList') || [];
                let isFound = false;

                calendarViewDataList.forEach(item => {
                    if (item.id === viewId) {
                        isFound = true;
                    }
                });

                if (!isFound) {
                    this.mode = null;
                }

                if (this.options.userId) {
                    this.mode = null;
                }
            }
        }

        // Check if mode is a resource calendar mode - handle before parent setup
        if (this.resourceCalendarModeList.includes(this.mode)) {
            // Manually setup shortcuts and websocket like parent does
            this.shortcutManager.add(this, this.shortcutKeys);

            this.on('remove', () => {
                this.shortcutManager.remove(this);
            });

            this.setupResourceCalendar();
            this.initWebSocket();
        } else {
            // Call parent setup for standard calendar modes
            super.setup();
        }
    }

    /**
     * Setup the resource calendar view
     * @private
     */
    setupResourceCalendar() {
        const viewName = this.getMetadata().get(['clientDefs', 'Calendar', 'resourceCalendarView']) ||
            'global:views/calendar/resource-calendar';

        // Map mode names to FullCalendar view names
        const modeMap = {
            'resourceDay': 'resourceTimeGridDay',
            'resourceWeek': 'resourceTimeGridWeek',
        };

        this.createView('calendar', viewName, {
            date: this.date,
            userId: this.options.userId,
            userName: this.options.userName,
            mode: modeMap[this.mode] || this.mode,
            fullSelector: '#main > .calendar-container',
            teamIdList: this.getTeamIdListForResourceCalendar(),
            onSave: () => this.onSave(),
        }, view => {
            let initial = true;

            this.listenTo(view, 'view', (date, mode) => {
                this.date = date;
                // Map back to our mode names
                const reverseModeMap = {
                    'resourceTimeGridDay': 'resourceDay',
                    'resourceTimeGridWeek': 'resourceWeek',
                };
                this.mode = reverseModeMap[mode] || mode;

                if (!initial) {
                    this.updateUrl();
                }

                initial = false;
            });

            this.listenTo(view, 'change:mode', (mode, refresh) => {
                const reverseModeMap = {
                    'resourceTimeGridDay': 'resourceDay',
                    'resourceTimeGridWeek': 'resourceWeek',
                };
                this.mode = reverseModeMap[mode] || mode;

                if (!this.options.userId) {
                    this.getStorage().set('state', 'calendarMode', this.mode);
                }

                if (refresh) {
                    this.updateUrl(true);
                    return;
                }

                // If switching to a mode not handled by resource calendar, refresh
                if (!this.resourceCalendarModeList.includes(this.mode)) {
                    this.updateUrl(true);
                }

                this.$el.focus();
            });
        });
    }

    /**
     * Get team ID list for resource calendar
     * Uses the Profissionais team by default
     * @private
     * @return {string[]}
     */
    getTeamIdListForResourceCalendar() {
        // Check if there are custom teams configured
        const storedTeams = this.getStorage().get('state', 'resourceCalendarTeams');

        if (storedTeams && storedTeams.length) {
            return storedTeams;
        }

        // Default: use Profissional team ID
        // This matches the ID from SeedTeams.php
        const toHash = this.getMetadata().get(['app', 'recordId', 'type']) === 'uuid4' ||
            this.getMetadata().get(['app', 'recordId', 'dbType']) === 'uuid';

        const teamId = toHash ? this.md5('cm-profissional') : 'cm-profissional';

        return [teamId];
    }

    /**
     * Simple MD5 hash (same as PHP md5)
     * @private
     * @param {string} str
     * @return {string}
     */
    md5(str) {
        // Use browser's crypto API if available, or fall back to simple hash
        // This is a simplified implementation - for production, use a proper MD5 library
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash;
        }
        // Convert to hex and ensure 32 characters
        const hex = Math.abs(hash).toString(16);
        return hex.padStart(32, '0').slice(0, 32);
    }
}

export default CalendarPage;
