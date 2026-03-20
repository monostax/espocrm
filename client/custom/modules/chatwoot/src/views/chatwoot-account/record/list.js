define(
    "chatwoot:views/chatwoot-account/record/list",
    ["views/record/list", "global:helpers/type-confirmation-dialog"],
    function (Dep, TypeConfirmationDialog) {
        return Dep.extend({
            actionQuickRemove: async function (data) {
                const config = this.getTypeDeleteConfirmationConfig();
                const confirmationValue = this.getRecordConfirmationValue(
                    data && data.id,
                    config
                );

                if (!config.enabled || !config.actions.quickRemove) {
                    return Dep.prototype.actionQuickRemove.call(this, data);
                }

                try {
                    await this.typeDeleteConfirmation(
                        this.translate(
                            "removeRecordConfirmation",
                            "messages",
                            this.scope
                        ),
                        confirmationValue
                    );
                } catch (e) {
                    return;
                }

                return this.runWithoutDefaultConfirm(() =>
                    Dep.prototype.actionQuickRemove.call(this, data)
                );
            },

            massActionRemove: async function () {
                const config = this.getTypeDeleteConfirmationConfig();
                const confirmationValue = this.getMassRemoveConfirmationValue(config);

                if (!config.enabled || !config.actions.massRemove) {
                    return Dep.prototype.massActionRemove.call(this);
                }

                if (!this.getAcl().check(this.entityType, "delete")) {
                    Espo.Ui.error(this.translate("Access denied"));

                    return false;
                }

                try {
                    await this.typeDeleteConfirmation(
                        this.translate(
                            "removeSelectedRecordsConfirmation",
                            "messages",
                            this.scope
                        ),
                        confirmationValue
                    );
                } catch (e) {
                    return;
                }

                return this.runWithoutDefaultConfirm(() =>
                    Dep.prototype.massActionRemove.call(this)
                );
            },

            typeDeleteConfirmation: function (message, expectedValue) {
                return TypeConfirmationDialog.confirm(this, {
                    message: message,
                    instruction: this.translate(
                        "typeToConfirmValueInstruction",
                        "messages",
                        this.scope
                    ).replace("{value}", expectedValue),
                    expectedValue: expectedValue,
                    confirmText: this.translate("Remove"),
                });
            },

            getTypeDeleteConfirmationConfig: function () {
                const defs =
                    this.getMetadata().get([
                        "clientDefs",
                        this.scope,
                        "typeDeleteConfirmation",
                    ]) || {};

                const expectedValue =
                    (defs.expectedValue || "DELETE").trim() || "DELETE";

                return {
                    enabled: defs.enabled !== false,
                    expectedValue: expectedValue,
                    actions: {
                        quickRemove:
                            !defs.actions ||
                            defs.actions.quickRemove === undefined ||
                            defs.actions.quickRemove === true,
                        massRemove:
                            !defs.actions ||
                            defs.actions.massRemove === undefined ||
                            defs.actions.massRemove === true,
                    },
                };
            },

            getRecordConfirmationValue: function (id, config) {
                const model = id ? this.collection.get(id) : null;
                const name = model ? (model.get("name") || "").trim() : "";

                return name || config.expectedValue;
            },

            getMassRemoveConfirmationValue: function (config) {
                if (this.allResultIsChecked) {
                    return config.expectedValue;
                }

                if (!this.checkedList.length) {
                    return config.expectedValue;
                }

                const firstId = this.checkedList[0];

                return this.getRecordConfirmationValue(firstId, config);
            },

            runWithoutDefaultConfirm: async function (callback) {
                const originalConfirm = this.confirm;

                this.confirm = function (o, confirmCallback, context) {
                    if (confirmCallback) {
                        if (context) {
                            confirmCallback.call(context);
                        } else {
                            confirmCallback();
                        }
                    }

                    return Promise.resolve();
                };

                try {
                    return await callback();
                } finally {
                    this.confirm = originalConfirm;
                }
            },
        });
    }
);
