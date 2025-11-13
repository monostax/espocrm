/************************************************************************
 * Custom List View for CAIThread Entity
 *
 * This view extends the default list view and customizes the behavior
 * of the CAIThread list page (including header buttons).
 ************************************************************************/

define("custom:views/caithread/list", ["views/list"], (ListView) => {
    return class extends ListView {
        /**
         * Hide the Create button from the list view header
         */
        createButton = false;

        setup() {
            super.setup();

            // Add any additional custom initialization logic here
        }
    };
});

