define("feature-journey:views/journey-record/reply-review", ["views/modal"], function (Modal) {
    return Modal.extend({
        templateContent: `
            {{#if pending}}
                <label>{{replyLabel}}</label>
                <select class="form-control" data-name="conversation">
                    {{#each pending}}<option value="{{key}}">{{label}}</option>{{/each}}
                </select>
            {{else}}
                <label>{{replacementLabel}}</label>
                <input type="tel" class="form-control" data-name="replacement" autocomplete="off">
            {{/if}}`,
        setup() {
            this.state = this.options.state;
            this.headerText = this.label("replyReview");
            const pending = Object.keys(this.state.pending || {}).length;
            this.buttonList = [
                pending ? {name: "wrongNumber", text: this.label("wrongNumber"), style: "warning"}
                    : {name: "resume", text: this.label("resumeReview"), style: "primary"},
                {name: "end", text: this.label("endCadence"), style: "danger"},
                {name: "cancel", label: "Close"},
            ];
        },
        label(key) { return this.translate(key, "labels", "JourneyRecord"); },
        data() {
            return {
                pending: Object.entries(this.state.pending || {}).map(([key, item]) => ({
                    key, label: item.phone || this.label("unknownPhone"),
                })),
                replyLabel: this.label("replyPhone"), replacementLabel: this.label("verifiedReplacement"),
            };
        },
        actionWrongNumber() { return this.decide("wrongNumber"); },
        actionResume() { return this.decide("resume"); },
        actionEnd() {
            return Espo.Ui.confirm(this.label("confirmEndCadence"), {
                confirmText: this.label("endCadence"), cancelText: this.translate("Cancel"), confirmStyle: "danger",
            }).then(() => this.decide("end"));
        },
        async decide(decision) {
            if (this.busy) return;
            this.busy = true;
            try {
                await Espo.Ajax.postRequest(this.options.url, {
                    decision, revision: this.state.revision,
                    conversation: this.$el.find('[data-name="conversation"]').val(),
                    replacement: this.$el.find('[data-name="replacement"]').val(),
                });
                Espo.Ui.success(this.label(decision === "wrongNumber" ? "wrongNumberSaved" : "reviewSaved"));
                this.trigger("done");
                this.close();
            } finally {
                this.busy = false;
            }
        },
    });
});
