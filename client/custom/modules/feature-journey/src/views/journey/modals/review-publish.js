define("feature-journey:views/journey/modals/review-publish", ["views/modal"], function (Dep) {
    /**
     * Review & Publish — validation, audience breakdown, side effects, honest activate result.
     */
    return Dep.extend({
        className: "dialog dialog-record",

        backdrop: "static",

        fitHeight: true,

        templateContent:
            '<div class="journey-review-publish">' +
                '{{#if loading}}' +
                '<div class="text-muted text-center" style="padding:24px 8px">' +
                    '<span class="fas fa-spinner fa-spin"></span> {{loadingText}}' +
                '</div>' +
                '{{else}}' +
                '{{#if loadError}}' +
                '<div class="alert alert-danger">{{loadError}}</div>' +
                '{{else}}' +

                '{{#if hasErrors}}' +
                '<div class="alert alert-danger journey-review-block">' +
                    '<div class="journey-review-block-title">' +
                        '<span class="fas fa-ban"></span> {{errorsTitle}}' +
                    '</div>' +
                    '<ul class="journey-review-issue-list">' +
                        '{{#each errorIssues}}' +
                        '<li>{{message}}</li>' +
                        '{{/each}}' +
                    '</ul>' +
                '</div>' +
                '{{/if}}' +

                '{{#if hasWarnings}}' +
                '<div class="alert alert-warning journey-review-block">' +
                    '<div class="journey-review-block-title">' +
                        '<span class="fas fa-exclamation-triangle"></span> {{warningsTitle}}' +
                    '</div>' +
                    '<ul class="journey-review-issue-list">' +
                        '{{#each warningIssues}}' +
                        '<li>{{message}}</li>' +
                        '{{/each}}' +
                    '</ul>' +
                '</div>' +
                '{{/if}}' +

                '{{#unless hasIssues}}' +
                '<div class="alert alert-success journey-review-block">' +
                    '<span class="fas fa-check-circle"></span> {{noIssuesText}}' +
                '</div>' +
                '{{/unless}}' +

                '<div class="journey-review-section">' +
                    '<div class="journey-review-section-title">{{audienceTitle}}</div>' +
                    '<div class="journey-review-audience-grid">' +
                        '<div class="journey-review-stat primary">' +
                            '<div class="journey-review-stat-value">{{audience.wouldEnroll}}</div>' +
                            '<div class="journey-review-stat-label">{{wouldEnrollLabel}}</div>' +
                        '</div>' +
                        '<div class="journey-review-stat">' +
                            '<div class="journey-review-stat-value">{{audience.eligibleCount}}</div>' +
                            '<div class="journey-review-stat-label">{{eligibleLabel}}</div>' +
                        '</div>' +
                        '<div class="journey-review-stat">' +
                            '<div class="journey-review-stat-value">{{audience.alreadyActive}}</div>' +
                            '<div class="journey-review-stat-label">{{alreadyActiveLabel}}</div>' +
                        '</div>' +
                        '<div class="journey-review-stat">' +
                            '<div class="journey-review-stat-value">{{audience.excludedCount}}</div>' +
                            '<div class="journey-review-stat-label">{{excludedLabel}}</div>' +
                        '</div>' +
                        '<div class="journey-review-stat">' +
                            '<div class="journey-review-stat-value">{{audience.reEnrollBlocked}}</div>' +
                            '<div class="journey-review-stat-label">{{reEnrollBlockedLabel}}</div>' +
                        '</div>' +
                        '<div class="journey-review-stat">' +
                            '<div class="journey-review-stat-value">{{audience.manualCount}}</div>' +
                            '<div class="journey-review-stat-label">{{manualLabel}}</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="text-muted small" style="margin-top:8px">' +
                        '{{targetTypeLabel}}: <strong>{{audience.targetEntityType}}</strong>' +
                        ' · {{includeListsLabel}}: <strong>{{audience.includeListsUsed}}</strong>' +
                        ' ({{audience.includeListCount}})' +
                        '{{#if audience.continuousEnrollment}}' +
                        ' · <span class="label label-info">{{continuousLabel}}</span>' +
                        '{{/if}}' +
                    '</div>' +
                '</div>' +

                '<div class="journey-review-section">' +
                    '<div class="journey-review-section-title">{{flowTitle}}</div>' +
                    '<div class="text-muted small">' +
                        '{{stagesLabel}}: <strong>{{stages.activeStageCount}}</strong>' +
                        ' · {{entryLabel}}: <strong>{{stages.entryCount}}</strong>' +
                        ' · {{successLabel}}: <strong>{{stages.successCount}}</strong>' +
                        ' · {{exitLabel}}: <strong>{{stages.exitCount}}</strong>' +
                        ' · {{connectionsLabel}}: <strong>{{stages.activeTransitionCount}}</strong>' +
                        ' · {{actionsLabel}}: <strong>{{stages.activeActionCount}}</strong>' +
                    '</div>' +
                '</div>' +

                '<div class="journey-review-section">' +
                    '<div class="journey-review-section-title">{{sideEffectsTitle}}</div>' +
                    '{{#if hasSideEffects}}' +
                    '<div class="journey-review-side-effects">' +
                        '{{#each sideEffects}}' +
                        '<div class="journey-review-side-effect{{#if highImpact}} high-impact{{/if}}">' +
                            '<div class="clearfix">' +
                                '<span class="badge{{#if highImpact}} badge-warning{{/if}}" ' +
                                    'style="margin-right:6px">{{count}}</span>' +
                                '<strong>{{typeLabel}}</strong>' +
                            '</div>' +
                            '{{#if items.length}}' +
                            '<ul class="journey-review-side-effect-items">' +
                                '{{#each items}}' +
                                '<li>' +
                                    '<span class="text-muted">{{stageName}}</span>' +
                                    ' · {{actionName}}' +
                                    '{{#if summary}}' +
                                    ' <span class="text-muted">— {{summary}}</span>' +
                                    '{{/if}}' +
                                    ' <span class="label label-default">{{trigger}}</span>' +
                                '</li>' +
                                '{{/each}}' +
                            '</ul>' +
                            '{{/if}}' +
                        '</div>' +
                        '{{/each}}' +
                    '</div>' +
                    '{{else}}' +
                    '<div class="text-muted small">{{noSideEffectsText}}</div>' +
                    '{{/if}}' +
                '</div>' +

                '{{#if canActivate}}' +
                '<p class="text-muted small" style="margin-top:12px;margin-bottom:0">' +
                    '{{confirmHint}}' +
                '</p>' +
                '{{else}}' +
                '<p class="text-danger small" style="margin-top:12px;margin-bottom:0">' +
                    '{{blockedHint}}' +
                '</p>' +
                '{{/if}}' +

                '{{/if}}' +
                '{{/if}}' +
            '</div>',

        data: function () {
            const review = this.review || {};
            const issues = review.issues || [];
            const errorIssues = issues.filter((i) => i.level === "error");
            const warningIssues = issues.filter((i) => i.level === "warning");
            const audience = review.audience || {};
            const stages = review.stages || {};
            const sideEffects = (review.sideEffects || []).map((group) => {
                return {
                    type: group.type,
                    typeLabel: this.actionTypeLabel(group.type),
                    count: group.count,
                    highImpact: !!group.highImpact,
                    items: group.items || [],
                };
            });

            return {
                loading: this.loading,
                loadError: this.loadError,
                loadingText: this.translateScoped("reviewLoading"),
                hasErrors: errorIssues.length > 0,
                hasWarnings: warningIssues.length > 0,
                hasIssues: issues.length > 0,
                errorIssues: errorIssues,
                warningIssues: warningIssues,
                errorsTitle: this.translateScoped("reviewErrorsTitle"),
                warningsTitle: this.translateScoped("reviewWarningsTitle"),
                noIssuesText: this.translateScoped("reviewNoIssues"),
                audienceTitle: this.translateScoped("reviewAudienceTitle"),
                audience: {
                    wouldEnroll: audience.wouldEnroll != null ? audience.wouldEnroll : "—",
                    eligibleCount: audience.eligibleCount != null ? audience.eligibleCount : "—",
                    alreadyActive: audience.alreadyActive != null ? audience.alreadyActive : "—",
                    excludedCount: audience.excludedCount != null ? audience.excludedCount : "—",
                    reEnrollBlocked: audience.reEnrollBlocked != null ? audience.reEnrollBlocked : "—",
                    manualCount: audience.manualCount != null ? audience.manualCount : "—",
                    includeListCount: audience.includeListCount != null ? audience.includeListCount : 0,
                    includeListsUsed: audience.includeListsUsed != null ? audience.includeListsUsed : 0,
                    targetEntityType: audience.targetEntityType || "—",
                    continuousEnrollment: !!audience.continuousEnrollment,
                },
                wouldEnrollLabel: this.translateScoped("reviewWouldEnroll"),
                eligibleLabel: this.translateScoped("reviewEligible"),
                alreadyActiveLabel: this.translateScoped("reviewAlreadyActive"),
                excludedLabel: this.translateScoped("reviewExcluded"),
                reEnrollBlockedLabel: this.translateScoped("reviewReEnrollBlocked"),
                manualLabel: this.translateScoped("reviewManual"),
                targetTypeLabel: this.translateScoped("reviewTargetType"),
                includeListsLabel: this.translateScoped("reviewIncludeLists"),
                continuousLabel: this.translateScoped("reviewContinuousOn"),
                flowTitle: this.translateScoped("reviewFlowTitle"),
                stagesLabel: this.translateScoped("reviewStages"),
                entryLabel: this.translateScoped("reviewEntry"),
                successLabel: this.translateScoped("reviewSuccess"),
                exitLabel: this.translateScoped("reviewExit"),
                connectionsLabel: this.translateScoped("reviewConnections"),
                actionsLabel: this.translateScoped("reviewActions"),
                stages: {
                    activeStageCount: stages.activeStageCount != null ? stages.activeStageCount : 0,
                    entryCount: stages.entryCount != null ? stages.entryCount : 0,
                    successCount: stages.successCount != null ? stages.successCount : 0,
                    exitCount: stages.exitCount != null ? stages.exitCount : 0,
                    activeTransitionCount:
                        stages.activeTransitionCount != null ? stages.activeTransitionCount : 0,
                    activeActionCount: stages.activeActionCount != null ? stages.activeActionCount : 0,
                },
                sideEffectsTitle: this.translateScoped("reviewSideEffectsTitle"),
                hasSideEffects: sideEffects.length > 0,
                sideEffects: sideEffects,
                noSideEffectsText: this.translateScoped("reviewNoSideEffects"),
                canActivate: !!review.canActivate,
                confirmHint: this.translateScoped("reviewConfirmHint"),
                blockedHint: this.translateScoped("reviewBlockedHint"),
            };
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.journeyModel = this.options.model;
            this.loading = true;
            this.loadError = null;
            this.review = null;
            this.publishing = false;

            this.headerText =
                this.translateScoped("Review & Publish", "labels") +
                (this.journeyModel.get("name")
                    ? " · " + this.journeyModel.get("name")
                    : "");

            this.buttonList = [
                {
                    name: "publish",
                    label: this.translateScoped("Turn on", "labels"),
                    style: "danger",
                    disabled: true,
                },
                {
                    name: "cancel",
                    label: "Cancel",
                },
            ];

            this.once("close", () => {
                if (this.options.onClose) {
                    this.options.onClose();
                }
            });

            this.loadReview();
        },

        translateScoped: function (key, category) {
            return this.translate(key, category || "messages", "Journey");
        },

        actionTypeLabel: function (type) {
            const map = {
                sendEmail: this.translateScoped("actionTypeSendEmail"),
                sendWhatsAppMessage: this.translateScoped("actionTypeSendWhatsAppMessage"),
                sendWhatsAppTemplate: this.translateScoped("actionTypeSendWhatsAppTemplate"),
                createTask: this.translateScoped("actionTypeCreateTask"),
                notifyUser: this.translateScoped("actionTypeNotifyUser"),
                updateTarget: this.translateScoped("actionTypeUpdateTarget"),
                executeFormula: this.translateScoped("actionTypeExecuteFormula"),
                recordTrackingEvent: this.translateScoped("actionTypeRecordTrackingEvent"),
                runScript: this.translateScoped("actionTypeRunScript"),
            };

            return map[type] || type;
        },

        loadReview: function () {
            this.loading = true;
            this.loadError = null;
            this.reRender();
            this.disableButton("publish");

            Espo.Ajax.getRequest("Journey/" + this.journeyModel.id + "/review")
                .then((response) => {
                    this.loading = false;
                    this.review = response || {};
                    this.reRender();
                    if (this.review.canActivate) {
                        this.enableButton("publish");
                    } else {
                        this.disableButton("publish");
                    }
                })
                .catch((xhr) => {
                    this.loading = false;
                    let msg = this.translateScoped("reviewLoadFailed");
                    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    this.loadError = msg;
                    this.reRender();
                    this.disableButton("publish");
                });
        },

        actionPublish: function () {
            if (this.publishing) {
                return;
            }

            if (!this.review || !this.review.canActivate) {
                return;
            }

            this.publishing = true;
            this.disableButton("publish");
            Espo.Ui.notify(this.translateScoped("activating"));

            Espo.Ajax.postRequest("Journey/" + this.journeyModel.id + "/activate")
                .then((response) => {
                    this.publishing = false;
                    Espo.Ui.notify(false);

                    const journey =
                        response && response.journey
                            ? response.journey
                            : response;
                    const enrollment =
                        (response && response.enrollment) || {
                            enrolled: 0,
                            skippedCount: 0,
                            failedCount: 0,
                        };

                    if (journey) {
                        this.journeyModel.set(journey);
                    }

                    const enrolled = enrollment.enrolled != null ? enrollment.enrolled : 0;
                    const skipped =
                        enrollment.skippedCount != null ? enrollment.skippedCount : 0;
                    const failed =
                        enrollment.failedCount != null ? enrollment.failedCount : 0;

                    let msg = this.translateScoped("activatedWithCounts")
                        .replace("{enrolled}", String(enrolled))
                        .replace("{skipped}", String(skipped))
                        .replace("{failed}", String(failed));

                    if (failed > 0) {
                        Espo.Ui.warning(msg);
                    } else {
                        Espo.Ui.success(msg);
                    }

                    if (this.options.onPublished) {
                        this.options.onPublished({
                            journey: journey,
                            enrollment: enrollment,
                            response: response,
                        });
                    }

                    if (failed > 0 && enrollment.failed && enrollment.failed.length) {
                        const lines = enrollment.failed.slice(0, 5).map((row) => {
                            const who = (row.targetType || "") + " " + (row.targetId || "");
                            const why = row.error || row.reason || "";
                            return "• " + who + (why ? ": " + why : "");
                        });
                        Espo.Ui.notify(
                            this.translateScoped("activationFailuresDetail") +
                                " " +
                                lines.join(" "),
                            "warning",
                            8000
                        );
                    }

                    this.close();
                })
                .catch((xhr) => {
                    this.publishing = false;
                    this.enableButton("publish");
                    Espo.Ui.notify(false);
                    let errorMsg = this.translateScoped("failedToActivate");
                    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    Espo.Ui.error(errorMsg);
                });
        },
    });
});
