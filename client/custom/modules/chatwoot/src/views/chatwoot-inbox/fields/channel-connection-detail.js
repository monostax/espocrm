define("chatwoot:views/chatwoot-inbox/fields/channel-connection-detail", [
    "views/fields/base",
], function (Dep) {
    return Dep.extend({
        templateContent:
            '<div class="channel-connection-detail-field">' +
            '<div class="channel-connection-detail-container"></div>' +
            '<div class="text-muted channel-connection-empty hidden">{{translate "No Data"}}</div>' +
            "</div>",

        setup: function () {
            Dep.prototype.setup.call(this);

            this.link = this.options.link || "chatwootInboxIntegration";
            this.targetScope = "ChatwootInboxIntegration";
            this.showRecordButtons = this.options.showRecordButtons;

            if (this.showRecordButtons === undefined && this.options.defs?.params) {
                this.showRecordButtons = this.options.defs.params.showRecordButtons;
            }

            if (this.showRecordButtons === undefined) {
                this.showRecordButtons = true;
            }
        },

        fetch: function () {
            return {};
        },

        getAttributeList: function () {
            return [];
        },

        validate: function () {
            return false;
        },

        afterRender: function () {
            this.renderLinkedRecord();
        },

        renderLinkedRecord: function () {
            if (!this.model.id) {
                this.showEmpty();

                return;
            }

            const idAttribute = this.link + "Id";
            const linkedId = this.model.get(idAttribute);

            if (!linkedId) {
                this.showEmpty();

                return;
            }

            this.getModelFactory().create(this.targetScope, (targetModel) => {
                targetModel.id = linkedId;

                targetModel.fetch().then(() => {
                    this.createView(
                        "record",
                        "global:views/global/record/embedded-detail",
                        {
                            selector: ".channel-connection-detail-container",
                            model: targetModel,
                            scope: this.targetScope,
                            layoutName: "detail",
                            buttonsDisabled: false,
                            inlineEditDisabled: false,
                            sideView: null,
                            showRecordButtons: this.showRecordButtons,
                        },
                        (view) => {
                            this.$el.find(".channel-connection-empty").addClass("hidden");
                            view.render();
                        }
                    );
                }).catch(() => {
                    this.showEmpty();
                });
            });
        },

        showEmpty: function () {
            this.$el.find(".channel-connection-detail-container").empty();
            this.$el.find(".channel-connection-empty").removeClass("hidden");
        },
    });
});
