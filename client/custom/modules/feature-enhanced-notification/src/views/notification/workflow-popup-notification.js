/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import PopupNotificationView from 'views/popup-notification';

/**
 * Popup notification view for Workflow/Formula-generated notifications.
 * Displays the notification message and an optional link to the related entity.
 * Marks the notification as read when dismissed.
 */
class WorkflowPopupNotificationView extends PopupNotificationView {

    template = 'feature-enhanced-notification:notification/workflow-popup-notification'

    type = 'workflowMessage'
    style = 'warning'
    closeButton = true

    setup() {
        this.header = this.translate('Notification', 'scopeNames');
    }

    data() {
        return {
            header: this.header,
            message: this.notificationData.message || '',
            entityType: this.notificationData.entityType || null,
            entityId: this.notificationData.entityId || null,
            entityName: this.notificationData.entityName || '',
            userName: this.notificationData.userName || '',
            ...super.data(),
        };
    }

    onCancel() {
        if (this.notificationId) {
            Espo.Ajax.putRequest('Notification/' + this.notificationId, {read: true});
        }
    }

    onConfirm() {
        if (this.notificationId) {
            Espo.Ajax.putRequest('Notification/' + this.notificationId, {read: true});
        }
    }
}

export default WorkflowPopupNotificationView;
