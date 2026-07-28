define("feature-journey:views/journey/fields/goal-success-stage", [
    "views/fields/link",
], function (Dep) {
    return Dep.extend({
        getSelectFilters: function () {
            const filters = Dep.prototype.getSelectFilters
                ? Dep.prototype.getSelectFilters.call(this) || {}
                : {};

            if (this.model.id) {
                filters.journeyId = {
                    type: "equals",
                    attribute: "journeyId",
                    value: this.model.id,
                    data: {
                        type: "is",
                        idValue: this.model.id,
                        nameValue: this.model.get("name") || this.model.id,
                    },
                };
            }

            filters.stageType = {
                type: "equals",
                attribute: "stageType",
                value: "Success",
            };
            filters.isActive = {
                type: "isTrue",
                attribute: "isActive",
            };

            return filters;
        },

        getCreateAttributes: function () {
            const attributes = Dep.prototype.getCreateAttributes
                ? Dep.prototype.getCreateAttributes.call(this) || {}
                : {};

            attributes.journeyId = this.model.id;
            attributes.journeyName = this.model.get("name");
            attributes.stageType = "Success";
            attributes.isActive = true;

            return attributes;
        },
    });
});
