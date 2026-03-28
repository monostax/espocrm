/**
 * Action handler for the "Request Export" and "Download Export" buttons on
 * FeatureIntegrationClinicaNasNuvensSettings detail view.
 *
 * Triggers CNN data export request or download operations via backend actions.
 */
define(["action-handler"], (Dep) => {
    return class extends Dep {
        async requestCnnExport() {
            await Espo.Ui.confirm(
                this.view.translate("requestCnnExport", "labels", "FeatureIntegrationClinicaNasNuvensSettings"),
                { confirmText: this.view.translate("Yes") }
            );

            try {
                await Espo.Ajax.postRequest(
                    "FeatureIntegrationClinicaNasNuvensSettings/action/requestCnnExport",
                    { id: this.view.model.id }
                );

                Espo.Ui.success(
                    this.view.translate("requestCnnExportQueued", "labels", "FeatureIntegrationClinicaNasNuvensSettings")
                );

                this.view.model.fetch();
            } catch (e) {
                Espo.Ui.error(
                    this.view.translate("requestCnnExportFailed", "labels", "FeatureIntegrationClinicaNasNuvensSettings")
                );
            }
        }

        async downloadCnnExport() {
            await Espo.Ui.confirm(
                this.view.translate("downloadCnnExport", "labels", "FeatureIntegrationClinicaNasNuvensSettings"),
                { confirmText: this.view.translate("Yes") }
            );

            try {
                await Espo.Ajax.postRequest(
                    "FeatureIntegrationClinicaNasNuvensSettings/action/downloadCnnExport",
                    { id: this.view.model.id }
                );

                Espo.Ui.success(
                    this.view.translate("downloadCnnExportQueued", "labels", "FeatureIntegrationClinicaNasNuvensSettings")
                );

                this.view.model.fetch();
            } catch (e) {
                Espo.Ui.error(
                    this.view.translate("downloadCnnExportFailed", "labels", "FeatureIntegrationClinicaNasNuvensSettings")
                );
            }
        }

        isRequestCnnExportVisible() {
            const status = this.view.model.get("exportStatus");

            return status !== "requesting" && status !== "downloading";
        }

        isDownloadCnnExportVisible() {
            const status = this.view.model.get("exportStatus");

            return status !== "downloading";
        }
    };
});
