/**
 * Action handler for the "Open in Clinica Nas Nuvens" button.
 * Resolves the correct CNN URL based on the entity type and opens it
 * in a new browser tab.
 *
 *   Paciente    -> /paciente/visualiza/{pacienteId}
 *   Agendamento -> /agenda/resumo-completo/{agendamentoId}
 *   Faturamento -> /faturamento/{faturamentoId}
 */
define(["action-handler"], (Dep) => {

    const ENTITY_CONFIG = {
        FeatureIntegrationClinicaNasNuvensPaciente: {
            field: "pacienteId",
            path: "paciente/visualiza",
        },
        FeatureIntegrationClinicaNasNuvensAgendamento: {
            field: "agendamentoId",
            path: "agenda/resumo-completo",
        },
        FeatureIntegrationClinicaNasNuvensFaturamento: {
            field: "faturamentoId",
            path: "faturamento",
        },
    };

    return class extends Dep {
        _getConfig() {
            return ENTITY_CONFIG[this.view.model.entityType] || null;
        }

        openInClinicaNasNuvens() {
            const config = this._getConfig();

            if (!config) {
                return;
            }

            const value = this.view.model.get(config.field);

            if (!value) {
                return;
            }

            const url =
                "https://app.clinicanasnuvens.com.br/" +
                config.path + "/" +
                encodeURIComponent(value);

            window.open(url, "_blank");
        }

        isOpenInClinicaNasNuvensVisible() {
            const config = this._getConfig();

            return config ? !!this.view.model.get(config.field) : false;
        }
    };
});
