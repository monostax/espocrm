/**
 * Action handler for the "Open in MEDX" button.
 * Resolves the correct MEDX URL based on the entity type and opens it
 * in a new browser tab.
 *
 *   Cliente -> /contatos/ficha/{clienteId}
 */
define(["action-handler"], (Dep) => {

    const ENTITY_CONFIG = {
        FeatureIntegrationMedxCliente: {
            field: "clienteId",
            page: "pages_front_Desk/contatoedit.html",
        },
    };

    return class extends Dep {
        _getConfig() {
            return ENTITY_CONFIG[this.view.model.entityType] || null;
        }

        openInMedx() {
            const config = this._getConfig();

            if (!config) {
                return;
            }

            const value = this.view.model.get(config.field);

            if (!value) {
                return;
            }

            const url =
                "https://v65.medx.med.br/" +
                config.page +
                "?id=" + encodeURIComponent(value);

            window.open(url, "_blank");
        }

        isOpenInMedxVisible() {
            const config = this._getConfig();

            return config ? !!this.view.model.get(config.field) : false;
        }
    };
});
