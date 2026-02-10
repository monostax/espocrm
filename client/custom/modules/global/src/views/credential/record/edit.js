define("global:views/credential/record/edit", ["views/record/edit"], function (
    Dep,
) {
    return Dep.extend({
        setup: function () {
            Dep.prototype.setup.call(this);

            // When credentialType changes, force config field to re-render
            // so it picks up the new uiConfig.
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
        },
    });
});
