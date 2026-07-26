define("feature-journey:handlers/journey/detail-actions", [], function () {
    return class {
        constructor(view) {
            this.view = view;
        }

        translate(key, category = "messages") {
            return this.view.translate(key, category, "Journey");
        }

        isActivateAvailable() {
            const status = this.view.model.get("status");
            return status === "Draft" || status === "Paused";
        }

        isPauseAvailable() {
            return this.view.model.get("status") === "Active";
        }

        isStopEnrollmentAvailable() {
            return (
                this.view.model.get("status") === "Active" &&
                this.view.model.get("continuousEnrollment")
            );
        }

        isArchiveAvailable() {
            const status = this.view.model.get("status");
            return status !== "Archived";
        }

        activate() {
            const view = this.view;
            const model = view.model;

            view.createView(
                "journeyReviewPublish",
                "feature-journey:views/journey/modals/review-publish",
                {
                    model: model,
                    onPublished: () => {
                        view.reRender();
                    },
                },
                (modalView) => {
                    modalView.render();
                }
            );
        }

        pause() {
            this.runAction({
                confirmMessage: this.translate("confirmPause"),
                confirmText: this.translate("Pause", "labels"),
                url: "pause",
                notifyMessage: this.translate("pausing"),
                successMessage: this.translate("paused"),
                errorMessage: this.translate("failedToPause"),
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

        archive() {
            this.runAction({
                confirmMessage: this.translate("confirmArchive"),
                confirmText: this.translate("Archive", "labels"),
                url: "archive",
                notifyMessage: this.translate("archiving"),
                successMessage: this.translate("archived"),
                errorMessage: this.translate("failedToArchive"),
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

                    Espo.Ajax.postRequest("Journey/" + model.id + "/" + o.url)
                        .then((response) => {
                            Espo.Ui.success(o.successMessage);
                            model.set(response);
                            this.view.reRender();
                        })
                        .catch((xhr) => {
                            let errorMsg = o.errorMessage;
                            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                                errorMsg = xhr.responseJSON.message;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                }
            );
        }
    };
});
