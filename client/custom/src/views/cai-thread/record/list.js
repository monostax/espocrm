/************************************************************************
 * Custom List View for CAIThread Entity
 *
 * This view extends the default list view and can be customized
 * to modify the behavior and appearance of the CAIThread list table.
 ************************************************************************/

define("custom:views/caithread/record/list", ["views/record/list"], (
    ListRecordView
) => {
    return class extends ListRecordView {
        /**
         * The row actions view - customize the dropdown menu on each row
         * Set to false to disable row actions completely
         */
        rowActionsView = "views/record/row-actions/default";

        /**
         * Show/hide checkboxes for mass actions
         */
        checkboxes = true;

        /**
         * Enable/disable quick detail (popup on row click)
         */
        quickDetailDisabled = false;

        /**
         * Enable/disable quick edit
         */
        quickEditDisabled = false;

        /**
         * Available mass actions
         * Options: 'remove', 'merge', 'massUpdate', 'export', 'follow', 'unfollow'
         */
        massActionList = ["remove", "massUpdate", "export"];

        /**
         * Mandatory attributes to fetch from API even if not displayed in list
         */
        mandatorySelectAttributeList = ["mastraThreadId"];

        /**
         * Setup method - called when view is initialized
         * Use this to add custom logic, event listeners, etc.
         */
        setup() {
            super.setup();

            // Add custom setup logic here
            // Example: this.listenTo(this.collection, 'sync', () => { ... });
        }

        /**
         * After render hook - called after the view is rendered
         */
        afterRender() {
            super.afterRender();

            // Add custom DOM manipulations or post-render logic here

            // Intercept clicks on the "name" field to navigate to AI thread in parent
            this.$el.find('td.cell[data-name="name"] a').on("click", (e) => {
                e.preventDefault();
                e.stopPropagation();

                // Get the model ID from the row
                const $row = $(e.currentTarget).closest("tr[data-id]");
                const modelId = $row.data("id");
                const model = this.collection.get(modelId);

                if (model) {
                    const mastraThreadId = model.get("mastraThreadId");

                    if (mastraThreadId) {
                        // Use iframe bridge to notify parent window
                        if (window.EspoCRMBridge) {
                            window.EspoCRMBridge.notifyThreadNavigation(
                                mastraThreadId
                            );
                        } else {
                            console.warn(
                                "CAIThread: EspoCRMBridge not available"
                            );
                        }
                    } else {
                        console.warn(
                            "CAIThread: No mastraThreadId found for this record"
                        );
                    }
                }
            });
        }

        /**
         * Get row selector for a specific model
         * @param {string} id - Model ID
         * @returns {string} - jQuery selector
         */
        getRowSelector(id) {
            return `tr[data-id="${id}"]`;
        }

        /**
         * Helper method to navigate to thread in parent window
         * @param {string} id - Record ID
         */
        navigateToThreadInParent(id) {
            const model = this.collection.get(id);

            if (model) {
                const mastraThreadId = model.get("mastraThreadId");

                if (mastraThreadId) {
                    // Use iframe bridge to notify parent window
                    if (window.EspoCRMBridge) {
                        window.EspoCRMBridge.notifyThreadNavigation(
                            mastraThreadId
                        );
                    } else {
                        console.warn("CAIThread: EspoCRMBridge not available");
                    }
                } else {
                    console.warn(
                        "CAIThread: No mastraThreadId found for this record"
                    );
                }
            }
        }

        /**
         * Override the Quick View action to navigate to thread in parent window
         * instead of showing the default quick view modal
         */
        actionQuickView(data) {
            this.navigateToThreadInParent(data.id);
        }

        /**
         * Override the View action to navigate to thread in parent window
         * instead of navigating to the detail page
         */
        actionView(data) {
            this.navigateToThreadInParent(data.id);
        }

        /**
         * Build row for a model - override to customize how rows are rendered
         * @param {number} i - Row index
         * @param {Object} model - Model instance
         * @param {Function} callback - Callback function
         */
        /*
        buildRow(i, model, callback) {
            super.buildRow(i, model, (view) => {
                // Custom row building logic here
                callback(view);
            });
        }
        */

        /**
         * Get header for column - override to customize column headers
         * @param {string} name - Field name
         * @returns {string} - Header HTML
         */
        /*
        getHeaderHtml(name) {
            const html = super.getHeaderHtml(name);
            // Modify header HTML here
            return html;
        }
        */
    };
});

