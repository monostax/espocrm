/**
 * Action handler for the "Import Clientes" button on
 * FeatureIntegrationMedxSettings detail view.
 *
 * Triggers the MEDX cliente import job for the current profile.
 */
define(["action-handler"], (Dep) => {
    return class extends Dep {
        async importClientes() {
            await Espo.Ui.confirm(
                this.view.translate("importClientesConfirm", "labels", "FeatureIntegrationMedxSettings"),
                { confirmText: this.view.translate("Yes") }
            );

            try {
                await Espo.Ajax.postRequest(
                    "FeatureIntegrationMedxSettings/action/importClientes",
                    { id: this.view.model.id }
                );

                Espo.Ui.success(
                    this.view.translate("importClientesQueued", "labels", "FeatureIntegrationMedxSettings")
                );

                this.view.model.fetch();
            } catch (e) {
                Espo.Ui.error(
                    this.view.translate("importClientesFailed", "labels", "FeatureIntegrationMedxSettings")
                );
            }
        }

        isImportClientesVisible() {
            const status = this.view.model.get("importStatus");

            return status !== "inProgress";
        }
    };
});
