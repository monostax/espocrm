define("feature-credential:views/credential/record/detail", [
    "views/record/detail",
], function (Dep) {
    return Dep.extend({

        setup: function () {
            Dep.prototype.setup.call(this);

            // When switching to edit mode inline, ensure the config field
            // has its uiConfig loaded for proper form rendering.
            this.listenTo(this.model, 'change:credentialTypeId', function () {
                var configView = this.getFieldView('config');

                if (configView && typeof configView.loadUiConfig === 'function') {
                    configView.loadUiConfig().then(function () {
                        if (configView.isRendered()) {
                            configView.reRender();
                        }
                    });
                }
            }.bind(this));

            // Add Health Check button to the dropdown menu.
            this.dropdownItemList.push({
                name: 'healthCheck',
                label: 'Health Check',
                action: 'healthCheck',
            });
        },

        actionHealthCheck: function () {
            // Disable button while running.
            this.disableActionItems();

            Espo.Ui.notify(this.translate('Please wait...'));

            Espo.Ajax.postRequest('Credential/action/healthCheck', {
                id: this.model.id,
            }).then(function (result) {
                Espo.Ui.notify(false);

                if (result.status === 'healthy') {
                    Espo.Ui.success(result.message || 'Credential is healthy');
                } else if (result.status === 'unhealthy') {
                    Espo.Ui.error(result.message || 'Credential is unhealthy');
                } else {
                    Espo.Ui.warning(result.message || 'Health check status unknown');
                }

                // Refresh model to show updated lastHealthCheckStatus + lastHealthCheckAt.
                this.model.fetch();

                this.enableActionItems();
            }.bind(this)).catch(function (err) {
                Espo.Ui.notify(false);
                Espo.Ui.error('Health check failed: ' + (err.message || 'Unknown error'));
                this.enableActionItems();
            }.bind(this));
        },

        /**
         * Disable dropdown action items during health check.
         */
        disableActionItems: function () {
            this.$el.find('[data-action="healthCheck"]').addClass('disabled');
        },

        /**
         * Re-enable dropdown action items after health check.
         */
        enableActionItems: function () {
            this.$el.find('[data-action="healthCheck"]').removeClass('disabled');
        },
    });
});
