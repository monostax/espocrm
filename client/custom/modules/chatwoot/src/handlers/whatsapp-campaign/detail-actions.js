/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("chatwoot:handlers/whatsapp-campaign/detail-actions", [], function () {
    return class {
        constructor(view) {
            this.view = view;
        }

        translate(key, category = "messages") {
            return this.view.translate(key, category, "WhatsAppCampaign");
        }

        isSendAvailable() {
            const status = this.view.model.get("status");
            return status === "Draft";
        }

        isAbortAvailable() {
            const status = this.view.model.get("status");
            return ["Sending", "Scheduled"].includes(status);
        }

        isStopEnrollmentAvailable() {
            const status = this.view.model.get("status");
            return status === "Sending" && this.view.model.get("continuousEnrollment");
        }

        isCreateAbTestAvailable() {
            const status = this.view.model.get("status");
            return status === "Draft";
        }

        createAbTest() {
            const view = this.view;

            view.createView(
                "createAbTestDialog",
                "chatwoot:views/whatsapp-campaign/modals/create-ab-test",
                {
                    model: view.model,
                },
                (dialog) => {
                    dialog.render();

                    view.listenToOnce(dialog, "done", (response) => {
                        if (response && response.id) {
                            view.getRouter().navigate(
                                "#WhatsAppCampaignDistribution/view/" + response.id,
                                { trigger: true },
                            );
                        }
                    });
                },
            );
        }

        sendCampaign() {
            this.runAction({
                confirmMessage: this.translate("confirmSendCampaign"),
                confirmText: this.translate("Send Campaign", "labels"),
                url: "send",
                notifyMessage: this.translate("launchingCampaign"),
                successMessage: this.translate("campaignLaunched"),
                errorMessage: this.translate("failedToLaunchCampaign"),
            });
        }

        abortCampaign() {
            this.runAction({
                confirmMessage: this.translate("confirmAbortCampaign"),
                confirmText: this.translate("Abort Campaign", "labels"),
                url: "abort",
                notifyMessage: this.translate("abortingCampaign"),
                successMessage: this.translate("campaignAborted"),
                errorMessage: this.translate("failedToAbortCampaign"),
            });
        }

        stopEnrollment() {
            this.runAction({
                confirmMessage: this.translate("confirmStopEnrollment"),
                confirmText: this.translate("Stop Enrollment", "labels"),
                url: "stopEnrollment",
                notifyMessage: this.translate("stoppingEnrollment"),
                successMessage: this.translate("enrollmentStopped"),
                errorMessage: this.translate("failedToStopEnrollment"),
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

                    Espo.Ajax.postRequest(`WhatsAppCampaign/${model.id}/${o.url}`)
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
