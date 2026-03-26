/**
 * Action handler for the "Enrich Downstreams" button on
 * FeatureIntegrationClinicaNasNuvensAgendamento detail view.
 *
 * Calls the backend to re-enrich the Agendamento and all related downstream
 * CNN entities (Paciente, Profissional, ConvenioTipo, Faturamento,
 * ProcedimentoTipo).
 */
define(["action-handler"], (Dep) => {
    return class extends Dep {
        async enrichDownstreams() {
            Espo.Ui.notify(
                this.view.translate("enrichingDownstreams", "labels", "FeatureIntegrationClinicaNasNuvensAgendamento")
            );

            try {
                const response = await Espo.Ajax.postRequest(
                    "FeatureIntegrationClinicaNasNuvensAgendamento/action/enrichDownstreams",
                    { id: this.view.model.id }
                );

                const enrichedCount = (response.enriched || []).length;
                const errorCount = (response.errors || []).length;

                if (errorCount > 0) {
                    Espo.Ui.warning(
                        this.view.translate("enrichDownstreamsPartial", "labels", "FeatureIntegrationClinicaNasNuvensAgendamento")
                            .replace("{enriched}", enrichedCount)
                            .replace("{errors}", errorCount)
                    );
                } else {
                    Espo.Ui.success(
                        this.view.translate("enrichDownstreamsSuccess", "labels", "FeatureIntegrationClinicaNasNuvensAgendamento")
                            .replace("{enriched}", enrichedCount)
                    );
                }

                this.view.model.fetch();
            } catch (e) {
                Espo.Ui.error(
                    this.view.translate("enrichDownstreamsFailed", "labels", "FeatureIntegrationClinicaNasNuvensAgendamento")
                );
            }
        }

        isEnrichDownstreamsVisible() {
            return !!this.view.model.get("agendamentoId");
        }
    };
});
