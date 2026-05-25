/**
 * OpportunityStage detail view override.
 *
 * Two-layer gating for the "Meta Conversions API" panel:
 *
 *   1. Tenant-level (AppParam `metaCapiActive`):
 *      If the user's tenant has no active MetaCapiDataset, hide the panel
 *      unconditionally — there's nothing to configure.
 *
 *   2. Per-record (already handled by native dynamicLogic in clientDefs):
 *      The denormalised OpportunityStage.metaCapiActive flag (maintained by
 *      Funnel/AfterSave/SyncStageCapiActive) is true iff the parent funnel
 *      has metaCapiEnabled=true AND a linked active dataset. Native
 *      dynamicLogic.panels.metaConversionsApi.visible reads that flag.
 *
 * This view only adds the tenant-level layer because dynamic logic can't
 * read AppParams. If the AppParam is true the per-record dynamic logic
 * takes over.
 */
define(
    "feature-meta-conversions-api:views/opportunity-stage/record/detail",
    ["views/record/detail"],
    function (Dep) {
        return Dep.extend({
            setup: function () {
                Dep.prototype.setup.call(this);

                var active = !!this.getHelper().getAppParam("metaCapiActive");

                if (active) {
                    return;
                }

                // Hide the entire CAPI panel by name.
                this.hidePanel("metaConversionsApi");
            },
        });
    }
);
