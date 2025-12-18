/**
 * Custom Account detail view with relationship count badges on tabs.
 */

import DetailRecordView from "views/record/detail";

class AccountDetailRecordView extends DetailRecordView {
    /**
     * Relationship links to show counts for.
     * Map of tab index to link name.
     * Tab 0 = Overview/Details, Tab 1+ = relationship tabs
     */
    relationshipTabLinks = {
        1: "contacts",
        2: "opportunities",
        3: "cases",
        4: "documents",
    };

    setup() {
        super.setup();

        // Listen for model sync to refresh counts
        this.listenTo(this.model, "sync", () => {
            this.updateTabCounts();
        });

        this.listenTo(this.model, "after:relate", () => {
            this.updateTabCounts();
        });

        this.listenTo(this.model, "after:unrelate", () => {
            this.updateTabCounts();
        });
    }

    afterRender() {
        super.afterRender();

        // Load counts after render
        if (this.model.id) {
            this.updateTabCounts();
        }
    }

    /**
     * Fetch and update relationship counts on tabs.
     */
    async updateTabCounts() {
        if (!this.model.id) {
            return;
        }

        const promises = [];
        const tabLinks = [];

        for (const [tabIndex, link] of Object.entries(
            this.relationshipTabLinks
        )) {
            const linkDefs = this.model.defs.links[link];

            if (!linkDefs) {
                continue;
            }

            const foreignEntityType = linkDefs.entity;

            if (!this.getAcl().check(foreignEntityType, "read")) {
                continue;
            }

            tabLinks.push({ tabIndex: parseInt(tabIndex), link });

            const url = `${this.model.entityType}/${this.model.id}/${link}?select=id&maxSize=0`;
            promises.push(Espo.Ajax.getRequest(url));
        }

        try {
            const results = await Promise.all(promises);

            results.forEach((result, index) => {
                const { tabIndex, link } = tabLinks[index];
                const count = result.total || 0;

                this.updateTabBadge(tabIndex, count);
            });
        } catch (e) {
            console.error("Error fetching relationship counts:", e);
        }
    }

    /**
     * Update a tab button with a count badge.
     *
     * @param {number} tabIndex
     * @param {number} count
     */
    updateTabBadge(tabIndex, count) {
        const $tab = this.$el.find(
            `.middle-tabs > button[data-tab="${tabIndex}"]`
        );

        if (!$tab.length) {
            return;
        }

        // Remove existing badge
        $tab.find(".badge").remove();

        // Get original label (without badge)
        let label = $tab.data("original-label");

        if (!label) {
            label = $tab.text().trim();
            $tab.data("original-label", label);
        }

        // Add badge only if count > 0
        if (count > 0) {
            const badgeHtml = `<span class="badge badge-default" style="margin-left: 5px;">${count}</span>`;
            $tab.html(`${label} ${badgeHtml}`);
        } else {
            $tab.html(label);
        }
    }
}

export default AccountDetailRecordView;


