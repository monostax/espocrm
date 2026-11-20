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
            if (this.view.model.get("replyPausedAt")) return false;

            return (
                status === "Active" ||
                status === "Processing" ||
                status === "Paused"
            );
        }

        canReview() {
            return this.view.model.get("status") === "Paused" && !!this.view.model.get("replyPausedAt");
        }

        async replyReview() {
            const model = this.view.model;
            const url = "JourneyRecord/" + model.id + "/replyReview";
            const state = await Espo.Ajax.getRequest(url);
            const modal = await this.view.createView("replyReview", "feature-journey:views/journey-record/reply-review", {
                state, url,
            });
            this.view.listenToOnce(modal, "done", () => model.fetch());
            modal.render();
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
