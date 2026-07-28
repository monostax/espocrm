define("feature-journey:views/journey/record/panels/flow", [
    "views/record/panels/bottom",
], function (Dep) {
    /**
     * n8n / Make-style vertical flow of stages, actions, and transitions.
     * Reads related JourneyStage / JourneyStageAction / JourneyTransition records.
     */
    return Dep.extend({
        templateContent:
            '<div class="journey-flow-panel">' +
                '<div class="journey-flow-toolbar clearfix" style="margin-bottom:14px">' +
                    '<div class="btn-group pull-left">' +
                        '{{#if canEdit}}' +
                        '<button type="button" class="btn btn-default btn-sm" data-action="createStage">' +
                            '<span class="fas fa-plus"></span> {{translate "createStage" category="labels" scope="Journey"}}' +
                        '</button>' +
                        '<button type="button" class="btn btn-default btn-sm" data-action="createTransition">' +
                            '<span class="fas fa-arrow-right"></span> {{translate "createTransition" category="labels" scope="Journey"}}' +
                        '</button>' +
                        '{{/if}}' +
                    '</div>' +
                    '<button type="button" class="btn btn-text btn-sm pull-right" data-action="refreshFlow">' +
                        '<span class="fas fa-sync"></span>' +
                    '</button>' +
                '</div>' +
                '{{#if loading}}' +
                '<div class="journey-flow-loading text-muted">' +
                    '<span class="fas fa-spinner fa-spin"></span> …' +
                '</div>' +
                '{{else}}' +
                '{{#unless hasStages}}' +
                '<div class="panel panel-default">' +
                    '<div class="panel-body text-center text-muted">' +
                        '<p style="margin:8px 0 12px">{{translate "flowEmpty" category="messages" scope="Journey"}}</p>' +
                        '{{#if canEdit}}' +
                        '<button type="button" class="btn btn-primary btn-sm" data-action="createStage">' +
                            '<span class="fas fa-plus"></span> {{translate "createStage" category="labels" scope="Journey"}}' +
                        '</button>' +
                        '{{/if}}' +
                    '</div>' +
                '</div>' +
                '{{else}}' +
                '<div class="journey-flow-canvas">' +
                    '{{#if hasEnrollment}}' +
                    '<div class="journey-flow-enrollment" style="margin-bottom:16px">' +
                        '<div class="text-muted small" style="margin-bottom:6px">' +
                            '<span class="fas fa-sign-in-alt"></span> ' +
                            '{{translate "enrollment" category="labels" scope="Journey"}}' +
                        '</div>' +
                        '{{#each enrollmentTransitions}}' +
                        '<div class="journey-flow-edge" ' +
                            'style="padding:8px 12px;margin-bottom:6px;border:1px dashed #c5c5c5;border-radius:6px;cursor:pointer;background:#fafafa" ' +
                            'data-action="openTransition" data-id="{{id}}">' +
                            '<span class="label label-default" style="margin-right:6px">{{triggerType}}</span>' +
                            '<strong>{{name}}</strong>' +
                            ' <span class="text-muted">→ {{toStageName}}</span>' +
                            '{{#if eventCodes}}' +
                            ' <span class="text-muted small">({{eventCodes}})</span>' +
                            '{{/if}}' +
                        '</div>' +
                        '{{/each}}' +
                    '</div>' +
                    '{{/if}}' +
                    '{{#if hasJourneyWide}}' +
                    '<div class="journey-flow-global" style="margin-bottom:16px">' +
                        '<div class="text-muted small" style="margin-bottom:6px">' +
                            '<span class="fas fa-globe"></span> ' +
                            '{{translate "journeyWideTransitions" category="labels" scope="Journey"}}' +
                        '</div>' +
                        '{{#each journeyWideTransitions}}' +
                        '<div class="journey-flow-edge" ' +
                            'style="padding:8px 12px;margin-bottom:6px;border:1px solid #b9d4ef;border-radius:6px;cursor:pointer;background:#f4f9ff" ' +
                            'data-action="openTransition" data-id="{{id}}">' +
                            '<span class="label label-info" style="margin-right:6px">{{triggerType}}</span>' +
                            '<strong>{{name}}</strong>' +
                            ' <span class="text-muted">→ {{toStageName}}</span>' +
                            '{{#if eventCodes}}' +
                            ' <span class="text-muted small">({{eventCodes}})</span>' +
                            '{{/if}}' +
                        '</div>' +
                        '{{/each}}' +
                    '</div>' +
                    '{{/if}}' +
                    '{{#each stages}}' +
                    '<div class="journey-flow-stage" data-stage-id="{{id}}" ' +
                        'style="border:1px solid #d8d8d8;border-radius:10px;background:#fff;margin-bottom:4px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)">' +
                        '<div class="journey-flow-stage-head clearfix" ' +
                            'style="padding:10px 14px;border-bottom:1px solid #eee;cursor:pointer;background:{{headBg}}" ' +
                            'data-action="openStage" data-id="{{id}}">' +
                            '<div class="pull-right">' +
                                '{{#unless isActive}}' +
                                '<span class="label label-default">{{translate "Inactive" category="labels" scope="Global"}}</span> ' +
                                '{{/unless}}' +
                                '<span class="label label-{{styleClass}}">{{stageType}}</span>' +
                            '</div>' +
                            '<div style="font-weight:600;font-size:14px">' +
                                '<span class="text-muted" style="margin-right:6px">{{order}}</span>' +
                                '{{name}}' +
                            '</div>' +
                            '{{#if maxDuration}}' +
                            '<div class="text-muted small" style="margin-top:2px">' +
                                '<span class="far fa-clock"></span> max {{maxDuration}}' +
                            '</div>' +
                            '{{/if}}' +
                        '</div>' +
                        '<div class="journey-flow-stage-body" style="padding:10px 14px">' +
                            '<div class="row">' +
                                '<div class="col-sm-6">' +
                                    '<div class="text-muted small" style="margin-bottom:4px">On enter</div>' +
                                    '{{#if hasOnEnter}}' +
                                    '<div class="journey-flow-actions">' +
                                        '{{#each onEnter}}' +
                                        '<span class="label label-primary" ' +
                                            'style="display:inline-block;margin:0 4px 4px 0;cursor:pointer;padding:5px 8px" ' +
                                            'data-action="openAction" data-id="{{id}}" title="{{name}}">' +
                                            '{{typeLabel}}' +
                                        '</span>' +
                                        '{{/each}}' +
                                    '</div>' +
                                    '{{else}}' +
                                    '<span class="text-muted small">—</span>' +
                                    '{{/if}}' +
                                '</div>' +
                                '<div class="col-sm-6">' +
                                    '<div class="text-muted small" style="margin-bottom:4px">On exit</div>' +
                                    '{{#if hasOnExit}}' +
                                    '<div class="journey-flow-actions">' +
                                        '{{#each onExit}}' +
                                        '<span class="label label-info" ' +
                                            'style="display:inline-block;margin:0 4px 4px 0;cursor:pointer;padding:5px 8px" ' +
                                            'data-action="openAction" data-id="{{id}}" title="{{name}}">' +
                                            '{{typeLabel}}' +
                                        '</span>' +
                                        '{{/each}}' +
                                    '</div>' +
                                    '{{else}}' +
                                    '<span class="text-muted small">—</span>' +
                                    '{{/if}}' +
                                '</div>' +
                            '</div>' +
                            '{{#if ../canEdit}}' +
                            '<div style="margin-top:8px">' +
                                '<button type="button" class="btn btn-default btn-xs" ' +
                                    'data-action="createAction" data-stage-id="{{id}}">' +
                                    '<span class="fas fa-plus"></span> {{translate "addAction" category="labels" scope="Journey"}}' +
                                '</button>' +
                            '</div>' +
                            '{{/if}}' +
                        '</div>' +
                    '</div>' +
                    '<div class="journey-flow-edges" style="padding:6px 0 14px 24px;border-left:2px solid #e0e0e0;margin:0 0 0 18px">' +
                        '{{#if hasOutTransitions}}' +
                        '{{#each outTransitions}}' +
                        '<div class="journey-flow-edge" ' +
                            'style="padding:6px 10px;margin-bottom:4px;border-radius:6px;background:#f7f7fb;cursor:pointer;border:1px solid #e8e8f0" ' +
                            'data-action="openTransition" data-id="{{id}}">' +
                            '<span class="text-muted" style="margin-right:4px">↓</span>' +
                            '<span class="label label-default" style="margin-right:6px">{{triggerType}}</span>' +
                            '<span>{{name}}</span>' +
                            ' <span class="text-muted">→ {{toStageName}}</span>' +
                            '{{#if waitPeriod}}' +
                            ' <span class="text-muted small"><span class="far fa-clock"></span> {{waitPeriod}}</span>' +
                            '{{/if}}' +
                            '{{#if eventCodes}}' +
                            ' <span class="text-muted small">[{{eventCodes}}]</span>' +
                            '{{/if}}' +
                        '</div>' +
                        '{{/each}}' +
                        '{{/if}}' +
                        '{{#if ../canEdit}}' +
                        '<button type="button" class="btn btn-default btn-xs" ' +
                            'data-action="createTransitionFromStage" data-stage-id="{{id}}">' +
                            '<span class="fas fa-arrow-right"></span> {{translate "createTransition" category="labels" scope="Journey"}}' +
                        '</button>' +
                        '{{/if}}' +
                    '</div>' +
                    '{{/each}}' +
                '</div>' +
                '{{/unless}}' +
                '{{/if}}' +
            '</div>',

        setup: function () {
            Dep.prototype.setup.call(this);

            this.loading = true;
            this.stages = [];
            this.enrollmentTransitions = [];
            this.journeyWideTransitions = [];
            this.canEdit = this.getAcl().check("JourneyStage", "edit");
            this._flowInFlight = false;
            this._flowQueued = false;
            this._flowStarted = false;

            this.actionList = [];

            this.addActionHandler("refreshFlow", () => this.actionRefreshFlow());
            this.addActionHandler("createStage", () => this.actionCreateStage());
            this.addActionHandler("createTransition", () => this.actionCreateTransition());
            this.addActionHandler("createTransitionFromStage", (e, el) => {
                const stageId = el && el.getAttribute("data-stage-id");
                this.actionCreateTransitionFromStage({ stageId: stageId });
            });
            this.addActionHandler("createAction", (e, el) => {
                const stageId = el && el.getAttribute("data-stage-id");
                this.actionCreateAction({ stageId: stageId });
            });
            this.addActionHandler("openStage", (e, el) => {
                this.actionOpenStage({ id: el && el.getAttribute("data-id") });
            });
            this.addActionHandler("openTransition", (e, el) => {
                this.actionOpenTransition({ id: el && el.getAttribute("data-id") });
            });
            this.addActionHandler("openAction", (e, el) => {
                this.actionOpenAction({ id: el && el.getAttribute("data-id") });
            });

            this.listenTo(this.model, "after:relate after:unrelate", () => {
                this.loadFlow();
            });

            // One-shot initial load after first paint (never from reRender of loadFlow).
            this.once("after:render", () => {
                if (!this._flowStarted) {
                    this._flowStarted = true;
                    this.loadFlow();
                }
            });
        },

        data: function () {
            return {
                loading: !!this.loading,
                stages: this.stages || [],
                hasStages: !!(this.stages && this.stages.length),
                enrollmentTransitions: this.enrollmentTransitions || [],
                hasEnrollment: !!(
                    this.enrollmentTransitions &&
                    this.enrollmentTransitions.length
                ),
                journeyWideTransitions: this.journeyWideTransitions || [],
                hasJourneyWide: !!(
                    this.journeyWideTransitions &&
                    this.journeyWideTransitions.length
                ),
                canEdit: this.canEdit,
            };
        },

        actionRefreshFlow: function () {
            this.loadFlow();
        },

        loadFlow: function () {
            const journeyId = this.model.id;
            if (!journeyId) {
                return;
            }

            if (this._flowInFlight) {
                this._flowQueued = true;
                return;
            }

            this._flowInFlight = true;
            this.loading = true;

            // Soft loading indicator without full reRender (avoids afterRender loops).
            if (this.isRendered() && this.$el) {
                const $load = this.$el.find(".journey-flow-loading");
                if ($load.length) {
                    $load.show();
                }
            }

            const stageReq = Espo.Ajax.getRequest("JourneyStage", {
                maxSize: 200,
                offset: 0,
                orderBy: "order",
                order: "asc",
                select: "id,name,order,style,stageType,isActive,maxDuration,journeyId",
                where: [
                    {
                        type: "equals",
                        attribute: "journeyId",
                        value: journeyId,
                    },
                ],
            });

            const transitionReq = Espo.Ajax.getRequest("JourneyTransition", {
                maxSize: 200,
                offset: 0,
                orderBy: "priority",
                order: "asc",
                select:
                    "id,name,priority,isActive,triggerType,eventCodes,waitPeriod," +
                    "scope,fromStageId,fromStageName,toStageId,toStageName,journeyId",
                where: [
                    {
                        type: "equals",
                        attribute: "journeyId",
                        value: journeyId,
                    },
                ],
            });

            Promise.all([stageReq, transitionReq])
                .then((results) => {
                    const stages = (results[0] && results[0].list) || [];
                    const transitions =
                        (results[1] && results[1].list) || [];
                    const stageIds = stages.map((s) => s.id);

                    const loadActions = stageIds.length
                        ? Espo.Ajax.getRequest("JourneyStageAction", {
                              maxSize: 200,
                              offset: 0,
                              orderBy: "order",
                              order: "asc",
                              select:
                                  "id,name,type,trigger,order,isActive,stageId",
                              where: [
                                  {
                                      type: "in",
                                      attribute: "stageId",
                                      value: stageIds,
                                  },
                              ],
                          })
                        : Promise.resolve({ list: [] });

                    return loadActions.then((actionRes) => {
                        this.buildViewModel(
                            stages,
                            transitions,
                            (actionRes && actionRes.list) || []
                        );
                        this.loading = false;
                        this._flowInFlight = false;

                        if (this._flowQueued) {
                            this._flowQueued = false;
                            this.loadFlow();
                            return;
                        }

                        if (this.isRendered()) {
                            this.reRender();
                        }
                    });
                })
                .catch(() => {
                    this.loading = false;
                    this.stages = [];
                    this.enrollmentTransitions = [];
                    this.journeyWideTransitions = [];
                    this._flowInFlight = false;

                    if (this._flowQueued) {
                        this._flowQueued = false;
                        this.loadFlow();
                        return;
                    }

                    if (this.isRendered()) {
                        this.reRender();
                    }
                });
        },

        buildViewModel: function (stages, transitions, actions) {
            const typeLabels =
                this.getLanguage().translate("type", "options", "JourneyStageAction") ||
                {};

            const actionsByStage = {};
            actions.forEach((a) => {
                if (!actionsByStage[a.stageId]) {
                    actionsByStage[a.stageId] = [];
                }
                actionsByStage[a.stageId].push(a);
            });

            const styleMap = {
                default: "default",
                success: "success",
                danger: "danger",
                warning: "warning",
                info: "info",
                primary: "primary",
            };

            const stageNameById = {};
            stages.forEach((s) => {
                stageNameById[s.id] = s.name;
            });

            this.enrollmentTransitions = transitions
                .filter((t) => this.scopeOf(t) === "enrollment")
                .map((t) => this.mapTransition(t, stageNameById));

            this.journeyWideTransitions = transitions
                .filter((t) => this.scopeOf(t) === "journey")
                .map((t) => this.mapTransition(t, stageNameById));

            this.stages = stages.map((s) => {
                const stageActions = actionsByStage[s.id] || [];
                const onEnter = stageActions
                    .filter((a) => a.trigger === "OnEnter" && a.isActive !== false)
                    .map((a) => ({
                        id: a.id,
                        name: a.name || a.type,
                        type: a.type,
                        typeLabel:
                            (typeLabels && typeLabels[a.type]) || a.type,
                    }));
                const onExit = stageActions
                    .filter((a) => a.trigger === "OnExit" && a.isActive !== false)
                    .map((a) => ({
                        id: a.id,
                        name: a.name || a.type,
                        type: a.type,
                        typeLabel:
                            (typeLabels && typeLabels[a.type]) || a.type,
                    }));

                // Also show inactive actions lightly
                stageActions
                    .filter((a) => a.isActive === false)
                    .forEach((a) => {
                        const bag = a.trigger === "OnExit" ? onExit : onEnter;
                        bag.push({
                            id: a.id,
                            name: a.name || a.type,
                            type: a.type,
                            typeLabel:
                                "∅ " +
                                ((typeLabels && typeLabels[a.type]) || a.type),
                        });
                    });

                const outTransitions = transitions
                    .filter((t) =>
                        this.scopeOf(t) === "stage" && t.fromStageId === s.id
                    )
                    .map((t) => this.mapTransition(t, stageNameById));

                const style = s.style || "default";
                return {
                    id: s.id,
                    name: s.name,
                    order: s.order,
                    stageType: s.stageType || "Normal",
                    isActive: s.isActive !== false,
                    maxDuration: s.maxDuration || null,
                    styleClass: styleMap[style] || "default",
                    headBg: this.headBgFor(s.stageType, style),
                    onEnter: onEnter,
                    onExit: onExit,
                    outTransitions: outTransitions,
                    hasOnEnter: onEnter.length > 0,
                    hasOnExit: onExit.length > 0,
                    hasOutTransitions: outTransitions.length > 0,
                };
            });
        },

        mapTransition: function (t, stageNameById) {
            let eventCodes = t.eventCodes;
            if (Array.isArray(eventCodes)) {
                eventCodes = eventCodes.join(", ");
            }
            return {
                id: t.id,
                name: t.name || t.triggerType,
                triggerType: t.triggerType,
                waitPeriod: t.waitPeriod || null,
                eventCodes: eventCodes || null,
                toStageName:
                    t.toStageName || stageNameById[t.toStageId] || t.toStageId,
                isActive: t.isActive !== false,
            };
        },

        scopeOf: function (transition) {
            if (
                transition.scope === "stage" ||
                transition.scope === "journey" ||
                transition.scope === "enrollment"
            ) {
                return transition.scope;
            }

            return transition.fromStageId ? "stage" : "enrollment";
        },

        headBgFor: function (stageType, style) {
            if (stageType === "Entry") {
                return "#f0f7ff";
            }
            if (stageType === "Success") {
                return "#f0fff4";
            }
            if (stageType === "Exit") {
                return "#fff5f5";
            }
            if (style === "primary") {
                return "#f5f8ff";
            }
            return "#fafafa";
        },

        actionCreateStage: function () {
            this.createRelated("JourneyStage", {
                journeyId: this.model.id,
                journeyName: this.model.get("name"),
            });
        },

        actionCreateTransition: function () {
            this.createRelated("JourneyTransition", {
                journeyId: this.model.id,
                journeyName: this.model.get("name"),
                scope: "enrollment",
            });
        },

        actionCreateTransitionFromStage: function (data) {
            const stageId = data.stageId;
            if (!stageId) {
                return;
            }
            const stage = (this.stages || []).find((s) => s.id === stageId);
            this.createRelated("JourneyTransition", {
                journeyId: this.model.id,
                journeyName: this.model.get("name"),
                scope: "stage",
                fromStageId: stageId,
                fromStageName: stage ? stage.name : null,
            });
        },

        actionCreateAction: function (data) {
            const stageId = data.stageId;
            if (!stageId) {
                return;
            }
            const stage = (this.stages || []).find((s) => s.id === stageId);
            this.createRelated("JourneyStageAction", {
                stageId: stageId,
                stageName: stage ? stage.name : null,
            });
        },

        actionOpenStage: function (data) {
            this.openRecord("JourneyStage", data.id);
        },

        actionOpenTransition: function (data) {
            this.openRecord("JourneyTransition", data.id);
        },

        actionOpenAction: function (data) {
            this.openRecord("JourneyStageAction", data.id);
        },

        createRelated: function (scope, attributes) {
            Espo.Ui.notify(" ... ");
            this.createView(
                "quickCreate",
                "views/modals/edit",
                {
                    scope: scope,
                    attributes: attributes || {},
                    fullFormDisabled: false,
                },
                (view) => {
                    view.render();
                    Espo.Ui.notify(false);
                    this.listenToOnce(view, "after:save", () => {
                        this.loadFlow();
                    });
                }
            );
        },

        openRecord: function (scope, id) {
            if (!id) {
                return;
            }
            this.createView(
                "quickEdit",
                "views/modals/detail",
                {
                    scope: scope,
                    id: id,
                    editDisabled: !this.getAcl().check(scope, "edit"),
                },
                (view) => {
                    view.render();
                    this.listenToOnce(view, "after:save", () => {
                        this.loadFlow();
                    });
                    // detail may open nested edit
                    this.listenTo(view, "remove", () => {
                        // refresh in case inline edits happened
                    });
                }
            );
        },
    });
});
