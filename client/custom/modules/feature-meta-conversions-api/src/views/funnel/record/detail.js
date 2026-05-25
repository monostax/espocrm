/**
 * Funnel detail view override.
 *
 * Gates visibility of the Meta Conversions API fields (`metaCapiEnabled`
 * and `metaCapiDataset`) on whether the user's tenant has at least one
 * active MetaCapiDataset configured. Reads the server-provided
 * `metaCapiActive` AppParam (see Classes/AppParams/MetaCapiActive.php).
 *
 * Admin users always see the fields so they can bootstrap the first
 * dataset; tenants who have not (yet) configured CAPI don't see the
 * toggles at all and aren't confused by dead configuration.
 */
define(
    "feature-meta-conversions-api:views/funnel/record/detail",
    ["views/record/detail"],
    function (Dep) {
        return Dep.extend({
            setup: function () {
                Dep.prototype.setup.call(this);

                var active = !!this.getHelper().getAppParam("metaCapiActive");

                if (active) {
                    return;
                }

                // Hide each CAPI field. We don't rely on a single panel
                // because the Funnel layout may render them inside the
                // generic field grid rather than a dedicated panel.
                this.hideField("metaCapiEnabled");
                this.hideField("metaCapiDataset");
            },
        });
    }
);
