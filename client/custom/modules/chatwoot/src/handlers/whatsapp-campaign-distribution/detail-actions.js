/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("chatwoot:handlers/whatsapp-campaign-distribution/detail-actions", [], function () {
    return class {
        constructor(view) {
            this.view = view;
        }

        translate(key, category = "messages") {
            return this.view.translate(key, category, "WhatsAppCampaignDistribution");
        }

        isActivateAvailable() {
            const status = this.view.model.get("status");
            return ["Draft", "Stopped"].includes(status);
        }

        isStopAvailable() {
            const status = this.view.model.get("status");
            return status === "Active";
        }

        activateDistribution() {
            this.runAction({
                confirmMessage: this.translate("confirmActivateDistribution"),
                confirmText: this.translate("Activate Distribution", "labels"),
                url: "activate",
                notifyMessage: this.translate("activatingDistribution"),
                successMessage: this.translate("distributionActivated"),
                errorMessage: this.translate("failedToActivateDistribution"),
            });
        }

        stopDistribution() {
            this.runAction({
                confirmMessage: this.translate("confirmStopDistribution"),
                confirmText: this.translate("Stop Distribution", "labels"),
                url: "stop",
                notifyMessage: this.translate("stoppingDistribution"),
                successMessage: this.translate("distributionStopped"),
                errorMessage: this.translate("failedToStopDistribution"),
            });
        }

        runAction(o) {
            const model = this.view.model;

            Espo.Ui.confirm(
                o.confirmMessage,
                {
                    confirmText: o.confirmText,
                    cancelText: this.view.translate("Cancel"),
                    confirmStyle: "danger",
                },
                () => {
                    Espo.Ui.notify(o.notifyMessage);

                    Espo.Ajax.postRequest(`WhatsAppCampaignDistribution/${model.id}/${o.url}`)
                        .then((response) => {
                            Espo.Ui.success(o.successMessage);
                            model.set(response);
                            this.view.reRender();
                        })
                        .catch((xhr) => {
                            let errorMsg = o.errorMessage;
                            if (xhr?.responseJSON?.message) {
                                errorMsg = xhr.responseJSON.message;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                },
            );
        }
    };
});
