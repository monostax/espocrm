/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('waha:views/waha-session-app/modals/edit', ['views/modals/edit'], function (Dep) {

    return Dep.extend({

        setup: function () {
            // Store attributes passed from parent before setup
            this._initialAttributes = this.options.attributes || {};
            
            Dep.prototype.setup.call(this);
        },

        actionSave: function () {
            const editView = this.getRecordView();

            if (!editView) {
                return;
            }

            const isValid = editView.validate();

            if (isValid) {
                return;
            }

            const data = editView.fetch();

            // Merge with initial attributes (like platformId, sessionName passed from parent)
            const fullData = {
                ...this._initialAttributes,
                ...data
            };

            // Check if this is a new record (no id or id not in expected format)
            const modelId = this.model.id;
            const isNewRecord = !modelId || !modelId.includes('_');

            Espo.Ui.notify(this.translate('saving', 'messages'));

            if (isNewRecord) {
                Espo.Ajax.postRequest('WahaSessionApp', fullData)
                    .then(response => {
                        Espo.Ui.success(this.translate('Created'));
                        this.trigger('after:save', response);
                        this.dialog.close();
                    })
                    .catch(xhr => {
                        let errorMsg = 'Error';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.messageTranslation) {
                            errorMsg = xhr.responseJSON.messageTranslation;
                        } else if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        Espo.Ui.error(errorMsg);
                    });
            } else {
                Espo.Ajax.putRequest(`WahaSessionApp/${modelId}`, fullData)
                    .then(response => {
                        Espo.Ui.success(this.translate('Saved'));
                        this.trigger('after:save', response);
                        this.dialog.close();
                    })
                    .catch(xhr => {
                        let errorMsg = 'Error';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        Espo.Ui.error(errorMsg);
                    });
            }
        }
    });
});

