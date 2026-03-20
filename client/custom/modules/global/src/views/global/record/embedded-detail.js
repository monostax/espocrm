define("global:views/global/record/embedded-detail", [
    "views/record/detail",
], function (Dep) {
    return Dep.extend({
        setup: function () {
            Dep.prototype.setup.call(this);

            this.showRecordButtons = this.options.showRecordButtons;

            if (this.showRecordButtons === undefined) {
                this.showRecordButtons = true;
            }

            if (!this.model || !this.model.id) {
                return;
            }

            const hasViewButton = (this.buttonList || []).some(
                (item) => item && item.name === "openFullRecord"
            );

            if (hasViewButton) {
                return;
            }

            this.buttonList = this.buttonList || [];

            this.buttonList.splice(1, 0, {
                name: "openFullRecord",
                label: "View",
            });
        },

        storeTab: function () {},

        selectStoredTab: function () {
            this.currentTab = 0;
        },

        isolateEmbeddedLayout: function () {
            this.$el.find(".middle-tabs").removeClass("middle-tabs").addClass("embedded-middle-tabs");
            this.$el.find(".middle").removeClass("middle").addClass("embedded-middle");
        },

        bindEmbeddedTabClicks: function () {
            this.$el.off("click.embedded-tabs");

            this.$el.on("click.embedded-tabs", ".embedded-middle-tabs > button", (e) => {
                e.preventDefault();
                e.stopPropagation();

                const tab = parseInt($(e.currentTarget).attr("data-tab"));

                if (Number.isNaN(tab)) {
                    return;
                }

                this.selectTab(tab);
            });
        },

        applyButtonsVisibility: function () {
            if (this.showRecordButtons) {
                this.$el.find(".detail-button-container.record-buttons").show();

                return;
            }

            this.$el.find(".detail-button-container.record-buttons").hide();
        },

        hideEmbeddedSide: function () {
            this.$el
                .find(".record-grid")
                .css("grid-template-columns", "minmax(0, 100%) 0")
                .css("max-width", "100%");

            this.$el
                .find(".record-grid > .side")
                .addClass("hidden")
                .removeClass("tabs-margin")
                .hide();

            this.$el
                .find(".record-grid > .left")
                .css("width", "100%")
                .css("max-width", "100%")
                .css("margin-right", "0");
        },

        actionOpenFullRecord: function (_data, e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }

            if (!this.model || !this.model.id) {
                return;
            }

            const url = `#${this.scope}/view/${this.model.id}`;

            this.getRouter().navigate(url, {trigger: true});
        },

        selectTab: function (tab) {
            this.currentTab = tab;

            this.whenRendered().then(() => {
                this.$el.find(".embedded-middle-tabs > button").removeClass("active");
                this.$el
                    .find(`.embedded-middle-tabs > button[data-tab="${tab}"]`)
                    .addClass("active");

                this.$el.find(".embedded-middle > .panel[data-tab]").addClass("tab-hidden");
                this.$el
                    .find(`.embedded-middle > .panel[data-tab="${tab}"]`)
                    .removeClass("tab-hidden");
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            this.applyButtonsVisibility();
            this.isolateEmbeddedLayout();
            this.bindEmbeddedTabClicks();
            this.hideEmbeddedSide();
            this.selectTab(this.currentTab || 0);

            setTimeout(() => {
                this.applyButtonsVisibility();
                this.isolateEmbeddedLayout();
                this.bindEmbeddedTabClicks();
                this.hideEmbeddedSide();
                this.selectTab(this.currentTab || 0);
            }, 0);
            setTimeout(() => {
                this.applyButtonsVisibility();
                this.isolateEmbeddedLayout();
                this.bindEmbeddedTabClicks();
                this.hideEmbeddedSide();
                this.selectTab(this.currentTab || 0);
            }, 80);
            setTimeout(() => {
                this.applyButtonsVisibility();
                this.isolateEmbeddedLayout();
                this.bindEmbeddedTabClicks();
                this.hideEmbeddedSide();
                this.selectTab(this.currentTab || 0);
            }, 180);
        },

        onRemove: function () {
            this.$el.off("click.embedded-tabs");

            Dep.prototype.onRemove.call(this);
        },
    });
});
