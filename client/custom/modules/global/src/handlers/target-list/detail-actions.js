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
 * Detail-view actions for TargetList.
 *
 * "Launch Outreach": opens a Funnel + Stage picker modal
 * (global:views/target-list/modals/launch-outreach), then POSTs
 * TargetList/{id}/launchOutreach to bulk-create outbound Opportunities
 * (sourceChannel=Outbound, sourceTargetList=this list). Server-side guards
 * and ACL live in Global\Tools\TargetList\LaunchOutreachService.
 */
define("global:handlers/target-list/detail-actions", [], function () {
    return class {
        constructor(view) {
            this.view = view;
        }

        isLaunchOutreachAvailable() {
            return this.view.getAcl().checkScope("Opportunity", "create");
        }

        launchOutreach() {
            const view = this.view;
            const model = view.model;

            view.createView(
                "launchOutreachModal",
                "global:views/target-list/modals/launch-outreach",
                {},
                (modal) => {
                    modal.render();

                    view.listenToOnce(modal, "apply", (data) => {
                        Espo.Ui.notify(view.translate("pleaseWait", "messages"));

                        Espo.Ajax.postRequest(
                            `TargetList/${model.id}/launchOutreach`,
                            {
                                funnelId: data.funnelId,
                                stageId: data.stageId,
                            }
                        )
                            .then((response) => {
                                Espo.Ui.notify(false);

                                const message = view
                                    .translate("launchOutreachScheduled", "messages", "TargetList")
                                    .replace("{scheduled}", response.scheduled)
                                    .replace("{skipped}",
                                        (response.skippedAlreadyAttributed || 0) +
                                        (response.skippedOpenInFunnel || 0));

                                Espo.Ui.success(message);

                                model.fetch();
                            })
                            .catch((xhr) => {
                                Espo.Ui.error(
                                    xhr?.responseJSON?.message ||
                                    view.translate("Error")
                                );
                            });
                    });
                }
            );
        }
    };
});
