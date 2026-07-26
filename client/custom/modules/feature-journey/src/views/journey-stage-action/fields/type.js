define("feature-journey:views/journey-stage-action/fields/type", [
    "views/fields/enum",
], function (Dep) {
    /**
     * Hide platform-tier action types from non-admin users.
     * Keeps the current value so detail/list still labels existing records.
     */
    return Dep.extend({
        setupOptions: function () {
            const options = this.params.options || [];
            if (!options.length) {
                return;
            }

            if (this.getUser().isAdmin()) {
                return;
            }

            const current = this.model.get(this.name);

            this.params.options = options.filter((type) => {
                const tier =
                    this.getMetadata().get([
                        "app",
                        "journeyActionTypes",
                        "types",
                        type,
                        "tier",
                    ]) || "tenant";

                if (tier !== "platform") {
                    return true;
                }

                // Allow display of a pre-existing platform type (read path).
                return type === current;
            });
        },
    });
});
