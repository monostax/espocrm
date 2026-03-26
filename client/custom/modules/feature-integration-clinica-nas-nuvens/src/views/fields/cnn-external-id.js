/**
 * Custom field view that renders a CNN remote ID as an external link
 * opening in a new tab. The URL path is resolved dynamically based on
 * the field name:
 *
 *   pacienteId    -> /paciente/visualiza/{id}
 *   agendamentoId -> /agenda/resumo-completo/{id}
 *   faturamentoId -> /faturamento/{id}
 */
define(["views/fields/varchar"], (Dep) => {

    const URL_MAP = {
        pacienteId: "paciente/visualiza",
        agendamentoId: "agenda/resumo-completo",
        faturamentoId: "faturamento",
        procedimentoTipoId: "procedimento",
    };

    return class extends Dep {
        detailTemplate = "feature-integration-clinica-nas-nuvens:fields/cnn-external-id/detail";
        listTemplate = "feature-integration-clinica-nas-nuvens:fields/cnn-external-id/detail";
        listLinkTemplate = "feature-integration-clinica-nas-nuvens:fields/cnn-external-id/detail";

        data() {
            const data = super.data();
            const value = this.model.get(this.name);
            const path = URL_MAP[this.name];

            if (value && path) {
                data.url =
                    "https://app.clinicanasnuvens.com.br/" +
                    path + "/" +
                    encodeURIComponent(value);
            } else {
                data.url = null;
            }

            return data;
        }
    };
});
