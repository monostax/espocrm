/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import NotificationBadgeView from 'views/notification/badge';

/**
 * Custom notification badge view that:
 * 1. Respects the notificationSoundsDisabled config setting (core hardcodes it to true).
 * 2. Clears popup notifications when all notifications are marked as read.
 */
class EnhancedNotificationBadgeView extends NotificationBadgeView {

    setup() {
        super.setup();

        // Fix: respect the config setting instead of hardcoding sounds disabled.
        this.notificationSoundsDisabled = this.getConfig().get('notificationSoundsDisabled');

        // Intercept Notification/action/markAllRead calls from any page to
        // immediately clear popup notifications and update the badge.
        if (!Espo.Ajax._enhancedNotificationPatched) {
            const originalPostRequest = Espo.Ajax.postRequest;

            Espo.Ajax.postRequest = function (url, data, options) {
                const promise = originalPostRequest.call(Espo.Ajax, url, data, options);

                if (url === 'Notification/action/markAllRead') {
                    promise.then(() => {
                        document.dispatchEvent(new CustomEvent('notifications:all-read'));
                    });
                }

                return promise;
            };

            Espo.Ajax._enhancedNotificationPatched = true;
        }

        this._onAllRead = () => {
            this.clearAllPopupNotifications();
            this.checkUpdates();
        };

        document.addEventListener('notifications:all-read', this._onAllRead);

        this.once('remove', () => {
            document.removeEventListener('notifications:all-read', this._onAllRead);
        });
    }

    /**
     * @override
     */
    hideNotRead() {
        super.hideNotRead();
        this.clearAllPopupNotifications();
    }

    /**
     * Remove all currently shown popup notifications from screen.
     */
    clearAllPopupNotifications() {
        if (!this.shownNotificationIds || !this.shownNotificationIds.length) {
            return;
        }

        const ids = [...this.shownNotificationIds];

        for (const id of ids) {
            const key = 'popup-' + id;

            if (this.hasView(key)) {
                this.clearView(key);
            }

            this.markPopupRemoved(id);
        }
    }
}

export default EnhancedNotificationBadgeView;
