define("feature-journey:views/journey-stage-action/fields/target-reference", [
    "views/fields/enum",
    "feature-journey:helpers/custom-fields",
], function (Dep, CustomFieldsHelper) {
    return Dep.extend({
        setup: function () {
            this.params.options = ["", this.model.get(this.name)].filter((value) => value != null);
            Dep.prototype.setup.call(this);
            this.cfHelper = new CustomFieldsHelper(this);
            this._loadGeneration = 0;
            this.listenTo(this.model, "change:stageId", () => this.loadReferences());
            this.wait(this.loadReferences());
        },

        loadReferences: function () {
            const generation = ++this._loadGeneration;
            const labels = {"": this.translate("enrolledRecord", "labels", "JourneyStageAction")};
            const options = [""];
            let failed = false;

            return this.cfHelper.resolveJourneyContext()
                .then((context) => context.journeyId
                    ? Espo.Ajax.getRequest("Journey/" + context.journeyId + "/recordReferences")
                    : {list: []})
                .then((data) => {
                    (data.list || []).forEach((reference) => {
                        if (reference.actionId === this.model.id) {
                            return;
                        }
                        options.push(reference.key);
                        labels[reference.key] = reference.key + " (" + reference.entityType + ") — " + reference.actionName;
                    });
                })
                .catch(() => { failed = true; })
                .then(() => {
                    if (generation !== this._loadGeneration) {
                        return;
                    }
                    const current = this.model.get(this.name);
                    if (current && !options.includes(current)) {
                        options.push(current);
                        labels[current] = current;
                    }
                    this._loadFailed = failed;
                    this.translatedOptions = labels;
                    if (this.isReady) {
                        return this.setOptionList(options);
                    }
                    this.params.options = options;
                });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);
            if (this._loadFailed && this.isEditMode()) {
                this.$el.append($("<p>").addClass("text-warning small").text(
                    this.translate("recordReferencesLoadFailed", "messages", "JourneyStageAction")
                ));
            }
        },
    });
});
