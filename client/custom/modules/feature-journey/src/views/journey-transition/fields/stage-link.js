define("feature-journey:views/journey-transition/fields/stage-link", [
    "views/fields/link",
], function (Dep) {
    /**
     * Stage link filtered to the parent journey on the transition.
     * getSelectFilters() return value is used directly as Escort "advanced" where.
     */
    return Dep.extend({
        getSelectFilters: function () {
            const filters = Dep.prototype.getSelectFilters
                ? Dep.prototype.getSelectFilters.call(this) || {}
                : {};

            const journeyId = this.model.get("journeyId");

            if (journeyId) {
                filters.journeyId = {
                    type: "equals",
                    attribute: "journeyId",
                    value: journeyId,
                    data: {
                        type: "is",
                        idValue: journeyId,
                        nameValue: this.model.get("journeyName") || journeyId,
                    },
                };
            }

            return filters;
        },

        getCreateAttributes: function () {
            const attributes = Dep.prototype.getCreateAttributes
                ? Dep.prototype.getCreateAttributes.call(this) || {}
                : {};

            if (this.model.get("journeyId")) {
                attributes.journeyId = this.model.get("journeyId");
                attributes.journeyName = this.model.get("journeyName");
            }

            return attributes;
        },
    });
});
