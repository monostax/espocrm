/**
 * Mass action handler for "Enrich All Related" on
 * FeatureIntegrationClinicaNasNuvensAgendamento list view.
 *
 * Sends selected record IDs to the MassAction endpoint which triggers
 * enrichDownstreams for each Agendamento.
 */
define([], () => {
    return class {
        constructor(view) {
            this.view = view;
        }

        actionEnrichDownstreams(data) {
            const view = this.view;

            Espo.Ui.notify(
                view.translate("enrichingDownstreams", "labels", "FeatureIntegrationClinicaNasNuvensAgendamento")
            );

            Espo.Ajax.postRequest("MassAction", {
                entityType: data.entityType,
                action: "enrichDownstreams",
                params: data.params,
            }).then(result => {
                view.collection.fetch().then(() => {
                    const count = result.count || 0;

                    Espo.Ui.success(
                        view.translate("enrichDownstreamsSuccess", "labels", "FeatureIntegrationClinicaNasNuvensAgendamento")
                            .replace("{enriched}", count)
                    );
                });
            });
        }
    };
});
