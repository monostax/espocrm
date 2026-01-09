/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('waha:views/waha-session/modals/edit', ['views/modals/edit'], function (Dep) {

    return Dep.extend({

        setup: function () {
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

            // For new records, we need to map platformId from the platform link
            if (this.isNew) {
                // Get platformId from the platform link field
                const platformId = this.model.get('platformId');
                if (!platformId) {
                    Espo.Ui.error(this.translate('Platform is required', 'messages', 'WahaSession'));
                    return;
                }
                data.platformId = platformId;
            }

            Espo.Ui.notify(this.translate('saving', 'messages'));

            // Use custom API endpoint for creation
            if (this.isNew) {
                Espo.Ajax.postRequest('WahaSession', data)
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
                // For updates, use standard save
                this.model.save(data)
                    .then(() => {
                        Espo.Ui.success(this.translate('Saved'));
                        this.trigger('after:save', this.model);
                        this.dialog.close();
                    })
                    .catch(() => {
                        Espo.Ui.error(this.translate('Error'));
                    });
            }
        }
    });
});

