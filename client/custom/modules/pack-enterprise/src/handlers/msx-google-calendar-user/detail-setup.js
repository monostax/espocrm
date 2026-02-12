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
define("pack-enterprise:handlers/msx-google-calendar-user/detail-setup", [], function () {
    return class {
        constructor(view) {
            this.view = view;
        }

        process() {
            const view = this.view;
            const model = view.model;

            // Initialize calendarList on the model.
            model.calendarList = model.calendarList || {};

            // Dynamically compute available activity/calendar entity types from metadata.
            this.setupEntityTypeOptions();

            // Fetch calendars if an OAuthAccount is already linked.
            if (model.get("oAuthAccountId")) {
                this.fetchCalendars(model.get("oAuthAccountId"));
            }

            // Re-fetch calendars when the OAuthAccount changes.
            view.listenTo(model, "change:oAuthAccountId", () => {
                const oAuthAccountId = model.get("oAuthAccountId");

                if (oAuthAccountId) {
                    this.fetchCalendars(oAuthAccountId);
                    // Auto-populate calendarEntityTypes if empty when OAuth is linked
                    this.autoPopulateEntityTypesIfNeeded();
                } else {
                    model.calendarList = {};
                    this.clearCalendarFields();
                }
            });
        }

        /**
         * Compute available entity types from scopes metadata (those with activity + calendar flags)
         * and set them as options on the calendarEntityTypes field.
         * Also auto-populates the field if empty when OAuth is linked.
         */
        setupEntityTypeOptions() {
            const view = this.view;
            const model = view.model;
            const metadata = view.getMetadata();
            const scopes = metadata.get("scopes") || {};

            const eventOptions = Object.keys(scopes)
                .filter((scope) => {
                    if (scope === "Email") return false;
                    if (scopes[scope].disabled) return false;
                    if (!scopes[scope].object) return false;
                    if (!scopes[scope].entity) return false;
                    if (!scopes[scope].activity || !scopes[scope].calendar)
                        return false;

                    return true;
                })
                .sort((a, b) => {
                    return view
                        .translate(a, "scopeNames")
                        .localeCompare(view.translate(b, "scopeNames"));
                });

            if (eventOptions.length > 0) {
                // Update the field params so the labeled-array view has options to display.
                const fieldView = view.getFieldView("calendarEntityTypes");

                if (fieldView) {
                    fieldView.params.options = eventOptions;
                    fieldView.translatedOptions = {};

                    eventOptions.forEach((scope) => {
                        fieldView.translatedOptions[scope] = view.translate(
                            scope,
                            "scopeNamesPlural",
                        );
                    });
                }

                // Auto-populate calendarEntityTypes if empty and OAuthAccount is linked
                const currentEntityTypes = model.get("calendarEntityTypes");
                if (
                    (!currentEntityTypes || currentEntityTypes.length === 0) &&
                    model.get("oAuthAccountId")
                ) {
                    model.set("calendarEntityTypes", eventOptions);

                    // Set default identification labels (first letter of each scope)
                    eventOptions.forEach((scope) => {
                        const label = scope.substring(0, 1);
                        model.set(scope + "IdentificationLabel", label);
                    });
                }
            }
        }

        /**
         * Fetch the list of Google Calendars for a given OAuthAccount.
         *
         * @param {string} oAuthAccountId
         */
        fetchCalendars(oAuthAccountId) {
            const view = this.view;
            const model = view.model;

            Espo.Ajax.getRequest("MsxGoogleCalendar/action/usersCalendars", {
                oAuthAccountId: oAuthAccountId,
            })
                .then((calendars) => {
                    model.calendarList = calendars || {};

                    // Re-render calendar field views so they pick up the new list.
                    this.rerenderCalendarFields();
                })
                .catch((xhr) => {
                    xhr.errorIsHandled = true;
                    model.calendarList = {};
                });
        }

        /**
         * Re-render the calendar selection field views.
         */
        rerenderCalendarFields() {
            const view = this.view;

            const mainCalendarView = view.getFieldView("calendarMainCalendar");
            if (mainCalendarView) {
                mainCalendarView.reRender();
            }

            const monitoredView = view.getFieldView(
                "calendarMonitoredCalendars",
            );
            if (monitoredView) {
                monitoredView.reRender();
            }
        }

        /**
         * Clear calendar selection fields when OAuthAccount is removed.
         */
        clearCalendarFields() {
            const model = this.view.model;

            model.set("calendarMainCalendarId", null);
            model.set("calendarMainCalendarName", null);
            model.set("calendarMonitoredCalendarsIds", []);
            model.set("calendarMonitoredCalendarsNames", {});

            this.rerenderCalendarFields();
        }

        /**
         * Auto-populate calendarEntityTypes with default entity types if empty.
         * Called when OAuthAccount is linked.
         */
        autoPopulateEntityTypesIfNeeded() {
            const view = this.view;
            const model = view.model;
            const metadata = view.getMetadata();
            const scopes = metadata.get("scopes") || {};

            const currentEntityTypes = model.get("calendarEntityTypes");
            if (currentEntityTypes && currentEntityTypes.length > 0) {
                return; // Already has entity types, don't override
            }

            const eventOptions = Object.keys(scopes)
                .filter((scope) => {
                    if (scope === "Email") return false;
                    if (scopes[scope].disabled) return false;
                    if (!scopes[scope].object) return false;
                    if (!scopes[scope].entity) return false;
                    if (!scopes[scope].activity || !scopes[scope].calendar)
                        return false;

                    return true;
                })
                .sort((a, b) => {
                    return view
                        .translate(a, "scopeNames")
                        .localeCompare(view.translate(b, "scopeNames"));
                });

            if (eventOptions.length > 0) {
                model.set("calendarEntityTypes", eventOptions);

                // Set default identification labels (first letter of each scope)
                eventOptions.forEach((scope) => {
                    const label = scope.substring(0, 1);
                    model.set(scope + "IdentificationLabel", label);
                });

                // Re-render the field to show the new values
                const fieldView = view.getFieldView("calendarEntityTypes");
                if (fieldView) {
                    fieldView.reRender();
                }
            }
        }
    };
});

