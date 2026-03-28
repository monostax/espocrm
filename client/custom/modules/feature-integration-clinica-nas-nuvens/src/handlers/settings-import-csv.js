/**
 * Action handler for the "Import CSV Data" button on
 * FeatureIntegrationClinicaNasNuvensSettings detail view.
 *
 * Shows a dialog with date range inputs and triggers the import job.
 */
define(["action-handler"], (Dep) => {
    return class extends Dep {
        async importCsvData() {
            const view = this.view;
            const entityType = "FeatureIntegrationClinicaNasNuvensSettings";

            const title = view.translate("importCsvDataDialogTitle", "labels", entityType);
            const bodyText = view.translate("importCsvDataDialogBody", "labels", entityType);
            const confirmText = view.translate("Yes");
            const cancelText = view.translate("Cancel");

            const today = new Date().toISOString().slice(0, 10);
            const thirtyDaysAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);

            const body =
                `<p>${bodyText}</p>` +
                `<div class="margin-top">` +
                    `<div class="form-group">` +
                        `<label class="control-label">${view.translate("dateFrom", "fields", entityType) || "Date From"}</label>` +
                        `<input type="date" class="form-control" id="import-date-from" value="${thirtyDaysAgo}" max="${today}">` +
                    `</div>` +
                    `<div class="form-group">` +
                        `<label class="control-label">${view.translate("dateTo", "fields", entityType) || "Date To"}</label>` +
                        `<input type="date" class="form-control" id="import-date-to" value="${today}">` +
                    `</div>` +
                `</div>`;

            return new Promise((resolve) => {
                const dialog = Espo.Ui.dialog({
                    header: title,
                    body: body,
                    buttonList: [
                        {
                            name: "confirm",
                            text: confirmText,
                            style: "primary",
                            onClick: async () => {
                                const dateFrom = document.getElementById("import-date-from")?.value;
                                const dateTo = document.getElementById("import-date-to")?.value;

                                if (!dateFrom || !dateTo) {
                                    Espo.Ui.error("Please select both dates.");

                                    return;
                                }

                                if (dateFrom > dateTo) {
                                    Espo.Ui.error("Date From must be before or equal to Date To.");

                                    return;
                                }

                                dialog.close();

                                try {
                                    await Espo.Ajax.postRequest(
                                        entityType + "/action/importCsvData",
                                        {
                                            id: view.model.id,
                                            dateFrom: dateFrom,
                                            dateTo: dateTo,
                                        }
                                    );

                                    Espo.Ui.success(
                                        view.translate("importCsvDataQueued", "labels", entityType)
                                    );

                                    view.model.fetch();
                                } catch (e) {
                                    Espo.Ui.error(
                                        view.translate("importCsvDataFailed", "labels", entityType)
                                    );
                                }

                                resolve();
                            },
                        },
                        {
                            name: "cancel",
                            text: cancelText,
                            onClick: () => {
                                dialog.close();
                                resolve();
                            },
                        },
                    ],
                });

                dialog.show();
            });
        }

        isImportCsvDataVisible() {
            const status = this.view.model.get("importStatus");

            return status !== "inProgress";
        }
    };
});
