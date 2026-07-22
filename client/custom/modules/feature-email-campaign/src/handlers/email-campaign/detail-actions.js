/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("feature-email-campaign:handlers/email-campaign/detail-actions", [], function () {
    return class {
        constructor(view) {
            this.view = view;
        }

        translate(key, category = "messages") {
            return this.view.translate(key, category, "EmailCampaign");
        }

        isSendAvailable() {
            return this.view.model.get("status") === "Draft";
        }

        isAbortAvailable() {
            return ["Sending", "Scheduled"].includes(this.view.model.get("status"));
        }

        isStopEnrollmentAvailable() {
            return (
                this.view.model.get("status") === "Sending" &&
                this.view.model.get("continuousEnrollment")
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

                    Espo.Ajax.postRequest(`EmailCampaign/${model.id}/${o.url}`)
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
