define("feature-journey:handlers/journey-record/detail-actions", [], function () {
    return class {
        constructor(view) {
            this.view = view;
        }

        translate(key, category = "messages") {
            return this.view.translate(key, category, "JourneyRecord");
        }

        isExitAvailable() {
            const status = this.view.model.get("status");

            return (
                status === "Active" ||
                status === "Processing" ||
                status === "Paused"
            );
        }

        exit() {
            const model = this.view.model;

            Espo.Ui.confirm(
                this.translate("confirmExit"),
                {
                    confirmText: this.translate("Exit", "labels"),
                    cancelText: this.view.translate("Cancel"),
                    confirmStyle: "danger",
                },
                () => {
                    Espo.Ui.notify(this.translate("exiting"));

                    Espo.Ajax.postRequest("JourneyRecord/" + model.id + "/exit", {
                        reason: "manual",
                    })
                        .then((response) => {
                            Espo.Ui.success(this.translate("exited"));
                            model.set(response);
                            this.view.reRender();
                        })
                        .catch((xhr) => {
                            let errorMsg = this.translate("failedToExit");
                            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                                errorMsg = xhr.responseJSON.message;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                },
            );
        }
    };
});
