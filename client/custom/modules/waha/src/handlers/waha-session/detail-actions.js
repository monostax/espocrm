/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import ActionHandler from 'action-handler';

/**
 * Action handler for WahaSession detail view dropdown actions.
 * Handles Logout and Remove actions (in the dropdown menu).
 */
class WahaSessionDetailActionHandler extends ActionHandler {

    /**
     * Logout from the session (disconnect WhatsApp account).
     */
    logout() {
        const model = this.view.model;

        this.view.confirm(
            this.view.translate('confirmLogoutSession', 'messages', 'WahaSession'),
            () => {
                Espo.Ui.notify(this.view.translate('Logging out...', 'labels', 'WahaSession'));

                Espo.Ajax.postRequest(`WahaSession/${model.id}/logout`)
                    .then(response => {
                        // Use fetch to reload the model cleanly without triggering link saves
                        model.fetch().then(() => {
                            Espo.Ui.success(this.view.translate('sessionLoggedOut', 'messages', 'WahaSession'));
                        });
                    })
                    .catch(xhr => {
                        let errorMsg = this.view.translate('Error');
                        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        Espo.Ui.error(errorMsg);
                    });
            }
        );
    }

    /**
     * Remove (delete) the session.
     */
    remove() {
        const model = this.view.model;

        this.view.confirm(
            this.view.translate('confirmRemoveSession', 'messages', 'WahaSession'),
            () => {
                Espo.Ui.notify(this.view.translate('Removing...', 'labels', 'WahaSession'));

                Espo.Ajax.deleteRequest(`WahaSession/${model.id}`)
                    .then(() => {
                        Espo.Ui.success(this.view.translate('sessionRemoved', 'messages', 'WahaSession'));
                        // Navigate back to list view
                        this.view.getRouter().navigate('#WahaSession', {trigger: true});
                    })
                    .catch(xhr => {
                        let errorMsg = this.view.translate('Error');
                        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        Espo.Ui.error(errorMsg);
                    });
            }
        );
    }

    /**
     * Check if Logout action is available.
     * Available when session is WORKING (connected to WhatsApp).
     */
    isLogoutAvailable() {
        const model = this.view.model;
        const status = model.get('status');
        
        if (!this.view.getAcl().check(model, 'edit')) {
            return false;
        }

        return status === 'WORKING';
    }

    /**
     * Check if Remove action is available.
     * Always available if user has delete permission.
     */
    isRemoveAvailable() {
        const model = this.view.model;
        
        return this.view.getAcl().check(model, 'delete');
    }
}

export default WahaSessionDetailActionHandler;
