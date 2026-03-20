define(
    "chatwoot:views/chatwoot-inbox/record/detail",
    ["global:views/record/detail", "global:helpers/type-confirmation-dialog"],
    function (Dep, TypeConfirmationDialog) {
        return Dep.extend({
            delete: async function () {
                const config = this.getTypeDeleteConfirmationConfig();
                const confirmationValue = (this.model.get("name") || "").trim() || config.expectedValue;

                if (!config.enabled || !config.actions.detail) {
                    return Dep.prototype.delete.call(this);
                }

                try {
                    await TypeConfirmationDialog.confirm(this, {
                        message: this.translate(
                            "removeRecordConfirmation",
                            "messages",
                            this.scope
                        ),
                        instruction: this.translate(
                            "typeToConfirmValueInstruction",
                            "messages",
                            this.scope
                        ).replace("{value}", confirmationValue),
                        expectedValue: confirmationValue,
                        confirmText: this.translate("Remove"),
                    });
                } catch (e) {
                    return;
                }

                const originalConfirm = this.confirm;

                this.confirm = function (o, callback, context) {
                    if (callback) {
                        if (context) {
                            callback.call(context);
                        } else {
                            callback();
                        }
                    }

                    return Promise.resolve();
                };

                try {
                    return Dep.prototype.delete.call(this);
                } finally {
                    this.confirm = originalConfirm;
                }
            },

            getTypeDeleteConfirmationConfig: function () {
                const defs = this.getMetadata().get([
                    "clientDefs",
                    this.scope,
                    "typeDeleteConfirmation",
                ]) || {};

                const expectedValue = (defs.expectedValue || "DELETE").trim() || "DELETE";

                return {
                    enabled: defs.enabled !== false,
                    expectedValue: expectedValue,
                    actions: {
                        detail:
                            !defs.actions ||
                            defs.actions.detail === undefined ||
                            defs.actions.detail === true,
                    },
                };
            },
        });
    }
);
