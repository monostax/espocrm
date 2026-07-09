/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * "Launch Outreach" modal for TargetList: pick the Funnel and the initial
 * Stage the outbound batch enters. The stage field reuses
 * global:views/opportunity/fields/opportunity-stage, which filters the
 * stage select by the chosen funnel and clears it when the funnel changes.
 *
 * Triggers "apply" with { funnelId, stageId }; the caller
 * (global:handlers/target-list/detail-actions) performs the POST.
 */
define(
    "global:views/target-list/modals/launch-outreach",
    ["views/modal", "model"],
    function (Dep, Model) {
        return Dep.extend({
            className: "dialog dialog-record",

            templateContent: `
                <div class="margin-bottom">
                    <p class="text-muted">{{translate 'launchOutreachInfo' category='messages' scope='TargetList'}}</p>
                </div>
                <div class="record no-side-margin">{{{record}}}</div>
            `,

            setup: function () {
                Dep.prototype.setup.call(this);

                this.headerText = this.translate("Launch Outreach", "labels", "TargetList");

                this.buttonList = [
                    {
                        name: "launch",
                        label: this.translate("Launch", "labels", "TargetList"),
                        style: "danger",
                    },
                    {
                        name: "cancel",
                        label: "Cancel",
                    },
                ];

                this.shortcutKeys = {
                    "Control+Enter": () => this.actionLaunch(),
                };

                const model = (this.model = new Model());

                model.name = "TargetListLaunchOutreach";

                model.setDefs({
                    fields: {
                        funnel: {
                            type: "link",
                            entity: "Funnel",
                            required: true,
                        },
                        opportunityStage: {
                            type: "link",
                            entity: "OpportunityStage",
                            required: true,
                            view: "global:views/opportunity/fields/opportunity-stage",
                        },
                    },
                });

                this.createView("record", "views/record/edit-for-modal", {
                    model: model,
                    selector: ".record",
                    detailLayout: [
                        {
                            rows: [
                                [
                                    {
                                        name: "funnel",
                                        labelText: this.translate("funnel", "fields", "Opportunity"),
                                    },
                                    {
                                        name: "opportunityStage",
                                        labelText: this.translate("opportunityStage", "fields", "Opportunity"),
                                    },
                                ],
                            ],
                        },
                    ],
                });
            },

            actionLaunch: function () {
                const recordView = this.getView("record");

                if (recordView.validate()) {
                    return;
                }

                recordView.processFetch();

                const funnelId = this.model.get("funnelId");
                const stageId = this.model.get("opportunityStageId");

                if (!funnelId || !stageId) {
                    return;
                }

                this.trigger("apply", {
                    funnelId: funnelId,
                    stageId: stageId,
                });

                this.close();
            },
        });
    }
);
