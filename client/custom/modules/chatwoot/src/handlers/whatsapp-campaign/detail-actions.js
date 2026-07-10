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

        stopEnrollment() {
            const model = this.view.model;

            Espo.Ui.confirm(
                "Stop enrolling new contacts into this campaign? Contacts already enrolled will still be processed, and the campaign will complete once they are done.",
                {
                    confirmText: "Stop Enrollment",
                    cancelText: this.view.translate("Cancel"),
                    confirmStyle: "danger",
                },
                () => {
                    Espo.Ui.notify("Stopping enrollment...");

                    Espo.Ajax.postRequest(`WhatsAppCampaign/${model.id}/stopEnrollment`)
                        .then((response) => {
                            Espo.Ui.success("Enrollment stopped.");
                            model.set(response);
                            this.view.reRender();
                        })
                        .catch((xhr) => {
                            let errorMsg = "Failed to stop enrollment";
                            if (xhr?.responseJSON?.message) {
                                errorMsg = xhr.responseJSON.message;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                },
            );
        }

        sendCampaign() {
            const model = this.view.model;

            Espo.Ui.confirm(
                "Are you sure you want to send this campaign? This will start sending messages to all targeted contacts.",
                {
                    confirmText: "Send Campaign",
                    cancelText: this.view.translate("Cancel"),
                    confirmStyle: "danger",
                },
                () => {
                    Espo.Ui.notify("Launching campaign...");

                    Espo.Ajax.postRequest(`WhatsAppCampaign/${model.id}/send`)
                        .then((response) => {
                            Espo.Ui.success("Campaign launched successfully.");
                            model.set(response);
                            this.view.reRender();
                        })
                        .catch((xhr) => {
                            let errorMsg = "Failed to launch campaign";
                            if (xhr?.responseJSON?.message) {
                                errorMsg = xhr.responseJSON.message;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                },
            );
        }

        abortCampaign() {
            const model = this.view.model;

            Espo.Ui.confirm(
                "Are you sure you want to abort this campaign? Messages already sent will not be recalled.",
                {
                    confirmText: "Abort Campaign",
                    cancelText: this.view.translate("Cancel"),
                    confirmStyle: "danger",
                },
                () => {
                    Espo.Ui.notify("Aborting campaign...");

                    Espo.Ajax.postRequest(`WhatsAppCampaign/${model.id}/abort`)
                        .then((response) => {
                            Espo.Ui.success("Campaign aborted.");
                            model.set(response);
                            this.view.reRender();
                        })
                        .catch((xhr) => {
                            let errorMsg = "Failed to abort campaign";
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

