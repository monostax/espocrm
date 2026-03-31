/**
 * Custom field view that renders a MEDX remote ID as an external link
 * opening in a new tab. The URL path is resolved dynamically based on
 * the field name:
 *
 *   clienteId -> /contatos/ficha/{id}
 */
define(["views/fields/varchar"], (Dep) => {

    const URL_MAP = {
        clienteId: "pages_front_Desk/contatoedit.html",
    };

    return class extends Dep {
        detailTemplate = "feature-integration-medx:fields/medx-external-id/detail";
        listTemplate = "feature-integration-medx:fields/medx-external-id/detail";
        listLinkTemplate = "feature-integration-medx:fields/medx-external-id/detail";

        data() {
            const data = super.data();
            const value = this.model.get(this.name);
            const page = URL_MAP[this.name];

            if (value && page) {
                data.url =
                    "https://v65.medx.med.br/" +
                    page +
                    "?id=" + encodeURIComponent(value);
            } else {
                data.url = null;
            }

            return data;
        }
    };
});
