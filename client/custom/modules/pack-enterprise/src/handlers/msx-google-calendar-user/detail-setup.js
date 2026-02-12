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
 * Setup handler for MsxGoogleCalendarUser detail/edit views.
 *
 * Fetches the user's Google Calendar list when an OAuthAccount is linked,
 * making it available to the calendarMainCalendar and calendarMonitoredCalendars
 * custom field views via model.calendarList.
 */
define('pack-enterprise:handlers/msx-google-calendar-user/detail-setup', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        process() {
            const view = this.view;
            const model = view.model;

            // Initialize calendarList on the model.
            model.calendarList = model.calendarList || {};

            // Fetch calendars if an OAuthAccount is already linked.
            if (model.get('oAuthAccountId')) {
                this.fetchCalendars(model.get('oAuthAccountId'));
            }

            // Re-fetch calendars when the OAuthAccount changes.
            view.listenTo(model, 'change:oAuthAccountId', () => {
                const oAuthAccountId = model.get('oAuthAccountId');

                if (oAuthAccountId) {
                    this.fetchCalendars(oAuthAccountId);
                } else {
                    model.calendarList = {};
                    this.clearCalendarFields();
                }
            });
        }

        /**
         * Fetch the list of Google Calendars for a given OAuthAccount.
         *
         * @param {string} oAuthAccountId
         */
        fetchCalendars(oAuthAccountId) {
            const view = this.view;
            const model = view.model;

            Espo.Ajax
                .getRequest('MsxGoogleCalendar/action/usersCalendars', {
                    oAuthAccountId: oAuthAccountId,
                })
                .then(calendars => {
                    model.calendarList = calendars || {};

                    // Re-render calendar field views so they pick up the new list.
                    this.rerenderCalendarFields();
                })
                .catch(xhr => {
                    xhr.errorIsHandled = true;
                    model.calendarList = {};
                });
        }

        /**
         * Re-render the calendar selection field views.
         */
        rerenderCalendarFields() {
            const view = this.view;

            const mainCalendarView = view.getFieldView('calendarMainCalendar');
            if (mainCalendarView) {
                mainCalendarView.reRender();
            }

            const monitoredView = view.getFieldView('calendarMonitoredCalendars');
            if (monitoredView) {
                monitoredView.reRender();
            }
        }

        /**
         * Clear calendar selection fields when OAuthAccount is removed.
         */
        clearCalendarFields() {
            const model = this.view.model;

            model.set('calendarMainCalendarId', null);
            model.set('calendarMainCalendarName', null);
            model.set('calendarMonitoredCalendarsIds', []);
            model.set('calendarMonitoredCalendarsNames', {});

            this.rerenderCalendarFields();
        }
    };
});
