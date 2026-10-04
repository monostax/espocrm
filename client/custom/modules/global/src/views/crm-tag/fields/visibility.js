import EnumField from "views/fields/enum";

export default class VisibilityField extends EnumField {
    setup() {
        super.setup();

        if (this.mode === "search" || !this.model.isNew()) return;

        this.listenTo(this.model, "change:visibility", () => {
            if (this.model.get("visibility") === "personal") {
                this.model.set({teamsIds: [], teamsNames: {}});
            }
        });

        if (this.model.get("tenantId")) return;

        this.wait(Espo.Ajax.getRequest("CrmTag/action/defaultWorkspace").then(attributes => {
            if (!this.model.get("tenantId") && attributes.tenantId) {
                this.model.set(attributes);
            }
        }));
    }
}
