/**
 * Custom side panel for displaying the contact field.
 * Only visible for existing records (not during creation).
 */
define("feature-clinica-base:views/paciente/record/panels/contact-side", [
    "views/record/panels/side",
], function (Dep) {
    return Dep.extend({
        name: "contactSide",
        label: "Contact",

        setup: function () {
            this.fieldList = ["contact"];
            Dep.prototype.setup.call(this);
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            // Only show this panel for existing records
            if (this.model.isNew()) {
                this.hide();
            } else {
                this.show();
            }
        },
    });
});

