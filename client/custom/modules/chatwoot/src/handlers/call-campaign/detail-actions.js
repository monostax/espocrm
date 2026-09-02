/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("chatwoot:handlers/call-campaign/detail-actions", [], function () {
    return class {
        constructor(view) {
            this.view = view;
        }

        translate(key, category = "messages") {
            return this.view.translate(key, category, "CallCampaign");
        }

        isLaunchAvailable() {
            const status = this.view.model.get("status");
            return status === "Draft";
        }

        isAbortAvailable() {
            const status = this.view.model.get("status");
            return ["Active", "Paused"].includes(status);
        }

        launchCampaign() {
            this.runAction({
                confirmMessage: this.translate("confirmLaunchCampaign"),
                confirmText: this.translate("Launch", "labels"),
                url: "launch",
                notifyMessage: this.translate("launchingCampaign"),
                successMessage: this.translate("launchStarted"),
                errorMessage: this.translate("failedToLaunchCampaign"),
            });
        }

        abortCampaign() {
            this.runAction({
                confirmMessage: this.translate("confirmAbortCampaign"),
                confirmText: this.translate("Abort", "labels"),
                url: "abort",
                notifyMessage: this.translate("abortingCampaign"),
                successMessage: this.translate("campaignAborted"),
                errorMessage: this.translate("failedToAbortCampaign"),
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

                    Espo.Ajax.postRequest(`CallCampaign/${model.id}/${o.url}`)
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
