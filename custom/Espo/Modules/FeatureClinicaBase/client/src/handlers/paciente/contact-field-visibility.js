/**
 * Handler to control contact field visibility in the main detail view.
 * Shows contact field on create, hides it for existing records (shown in side panel instead).
 */
define("feature-clinica-base:handlers/paciente/contact-field-visibility", [], function () {
    return {
        /**
         * @param {import('views/record/detail').default} recordView
         */
        setup: function (recordView) {
            // Wait for the view to be rendered
            recordView.once("after:render", function () {
                const contactField = recordView.getFieldView("contact");

                if (!contactField) {
                    return;
                }

                // Hide contact field in main view for existing records
                // It will be shown in the custom side panel instead
                if (!recordView.model.isNew()) {
                    contactField.hide();
                }
            });
        },
    };
});

