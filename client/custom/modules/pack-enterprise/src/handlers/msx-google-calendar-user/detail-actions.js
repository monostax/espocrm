define('pack-enterprise:handlers/msx-google-calendar-user/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        isSyncNowAvailable() {
            const model = this.view.model;

            return model.get('oAuthAccountId') && model.get('active') && !model.isNew();
        }

        syncNow() {
            const model = this.view.model;

            Espo.Ui.confirm(
                this.view.translate('confirmSyncNow', 'messages', 'MsxGoogleCalendarUser'),
                {
                    confirmText: this.view.translate('Sync Now', 'labels', 'MsxGoogleCalendarUser'),
                    cancelText: this.view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(this.view.translate('Synchronizing...'));

                    Espo.Ajax.postRequest(`MsxGoogleCalendarUser/${model.id}/syncNow`)
                        .then(response => {
                            Espo.Ui.success(
                                this.view.translate('syncStarted', 'messages', 'MsxGoogleCalendarUser')
                            );
                            model.set(response);
                            this.view.reRender();
                        })
                        .catch(xhr => {
                            let errorMsg = this.view.translate(
                                'syncFailed', 'messages', 'MsxGoogleCalendarUser'
                            );

                            if (xhr?.responseJSON?.message) {
                                errorMsg = xhr.responseJSON.message;
                            }

                            Espo.Ui.error(errorMsg);
                        });
                }
            );
        }
    };
});
