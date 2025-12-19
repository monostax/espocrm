/**
 * Custom Activities panel that respects orderDirection from panel defs.
 * Shows activities in ascending order (closest dates first) when configured.
 */

import ActivitiesPanelView from "crm:views/record/panels/activities";

class CustomActivitiesPanelView extends ActivitiesPanelView {
    setup() {
        // Override the order BEFORE calling super.setup() so the collection uses it
        if (this.defs && this.defs.orderDirection) {
            this.order = this.defs.orderDirection;
        }

        super.setup();

        // The backend always returns DESC order, so we need to reverse on client
        // Listen for collection sync and re-sort if needed
        if (
            this.collection &&
            this.defs &&
            this.defs.orderDirection === "asc"
        ) {
            this.listenTo(this.collection, "sync", () => {
                this.sortCollectionAsc();
            });
        }
    }

    /**
     * Sort the collection in ascending order by dateStart.
     */
    sortCollectionAsc() {
        if (!this.collection || !this.collection.models) {
            return;
        }

        // Sort models in place by dateStart ascending
        this.collection.models.sort((a, b) => {
            const dateA = a.get("dateStart") || a.get("dateEnd") || "";
            const dateB = b.get("dateStart") || b.get("dateEnd") || "";

            if (!dateA && !dateB) return 0;
            if (!dateA) return 1;
            if (!dateB) return -1;

            return dateA.localeCompare(dateB);
        });

        // Trigger reset to force the view to re-render
        this.collection.trigger("reset", this.collection);
    }
}

export default CustomActivitiesPanelView;


