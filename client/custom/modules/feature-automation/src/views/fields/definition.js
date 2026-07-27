define('feature-automation:views/fields/definition', [
    'views/fields/base',
    'model',
    'feature-journey:helpers/expression-input',
], function (Dep, Model, ExpressionInput) {

    /**
     * Structured Batch/Machine definition editor (Zapier/n8n-style builder + JSON).
     * Action params render from app.automationActionTypes.*.paramDefs.
     */
    return Dep.extend({

        type: 'jsonObject',

        detailTemplateContent:
            '<div class="automation-def-detail">' +
                '{{#if summaryHtml}}{{{summaryHtml}}}{{else}}' +
                '<pre class="pre-scrollable" style="max-height:320px;white-space:pre-wrap">{{jsonText}}</pre>' +
                '{{/if}}' +
            '</div>',

        editTemplateContent:
            '<div class="automation-definition-builder">' +
                '<div class="automation-definition-toolbar">' +
                    '<div class="btn-group btn-group-sm" role="group">' +
                        '<button type="button" class="btn btn-default mode-builder{{#unless jsonMode}} active{{/unless}}" data-action="modeBuilder">' +
                            '{{translate "Builder" category="labels" scope="Automation"}}' +
                        '</button>' +
                        '<button type="button" class="btn btn-default mode-json{{#if jsonMode}} active{{/if}}" data-action="modeJson">' +
                            '{{translate "JSON" category="labels" scope="Automation"}}' +
                        '</button>' +
                    '</div>' +
                    '<span class="text-muted small automation-builder-toolbar-hint">' +
                        '{{translate "builderToolbarHint" category="messages" scope="Automation"}}' +
                    '</span>' +
                '</div>' +
                '<div class="builder-pane" {{#if jsonMode}}style="display:none"{{/if}}>' +
                    '<div class="automation-def-section">' +
                        '<div class="alert alert-info automation-type-help def-kind-banner">' +
                            '<div class="automation-type-help-title def-kind-title"></div>' +
                            '<div class="automation-type-help-body def-kind-hint"></div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="batch-pane">' +
                        '<div class="automation-def-section">' +
                            '<div class="automation-def-section-head">' +
                                '<h5>{{translate "Stages" category="labels" scope="Automation"}}</h5>' +
                                '<p class="text-muted small automation-field-hint">' +
                                    '{{translate "stagesPipelineHint" category="messages" scope="Automation"}}' +
                                '</p>' +
                            '</div>' +
                            '<div class="stage-list"></div>' +
                            '<div class="btn-group btn-group-sm" style="margin-top:6px">' +
                                '<button type="button" class="btn btn-default" data-action="addStage" data-scope="forEach">' +
                                    '<span class="fas fa-plus"></span> {{translate "addForEachStage" category="labels" scope="Automation"}}' +
                                '</button>' +
                                '<button type="button" class="btn btn-default" data-action="addStage" data-scope="once">' +
                                    '<span class="fas fa-plus"></span> {{translate "addOnceStage" category="labels" scope="Automation"}}' +
                                '</button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="automation-def-section automation-def-limits">' +
                            '<div class="row">' +
                                '<div class="col-sm-6">' +
                                    '<label class="control-label">{{translate "maxItems" category="labels" scope="Automation"}}</label>' +
                                    '<input type="number" class="form-control def-max-items" min="1" max="50000">' +
                                    '<p class="text-muted small automation-field-hint">' +
                                        '{{translate "maxItemsHint" category="messages" scope="Automation"}}' +
                                    '</p>' +
                                '</div>' +
                                '<div class="col-sm-6">' +
                                    '<label class="control-label">{{translate "maxExpandPerParent" category="labels" scope="Automation"}}</label>' +
                                    '<input type="number" class="form-control def-max-expand" min="1" max="5000">' +
                                    '<p class="text-muted small automation-field-hint">' +
                                        '{{translate "maxExpandHint" category="messages" scope="Automation"}}' +
                                    '</p>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="machine-pane" style="display:none">' +
                        '<div class="automation-def-section">' +
                            '<div class="row">' +
                                '<div class="col-sm-6">' +
                                    '<label class="control-label">{{translate "Initial state" category="labels" scope="Automation"}}</label>' +
                                    '<select class="form-control def-initial"></select>' +
                                    '<p class="text-muted small automation-field-hint">' +
                                        '{{translate "initialStateHint" category="messages" scope="Automation"}}' +
                                    '</p>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="automation-def-section">' +
                            '<div class="automation-def-section-head">' +
                                '<h5>{{translate "States" category="labels" scope="Automation"}}</h5>' +
                                '<p class="text-muted small automation-field-hint">' +
                                    '{{translate "statesHint" category="messages" scope="Automation"}}' +
                                '</p>' +
                            '</div>' +
                            '<div class="state-list"></div>' +
                            '<button type="button" class="btn btn-default btn-sm" data-action="addState">' +
                                '<span class="fas fa-plus"></span> {{translate "addState" category="labels" scope="Automation"}}' +
                            '</button>' +
                        '</div>' +
                        '<div class="automation-def-section">' +
                            '<div class="automation-def-section-head">' +
                                '<h5>{{translate "Transitions" category="labels" scope="Automation"}}</h5>' +
                                '<p class="text-muted small automation-field-hint">' +
                                    '{{translate "transitionsHint" category="messages" scope="Automation"}}' +
                                '</p>' +
                            '</div>' +
                            '<div class="transition-list"></div>' +
                            '<button type="button" class="btn btn-default btn-sm" data-action="addTransition">' +
                                '<span class="fas fa-plus"></span> {{translate "addTransition" category="labels" scope="Automation"}}' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="json-pane" {{#unless jsonMode}}style="display:none"{{/unless}}>' +
                    '<textarea class="form-control def-json" rows="18" style="font-family:monospace;font-size:12px"></textarea>' +
                    '<div class="text-muted small margin-top-sm">' +
                        '{{translate "jsonHint" category="labels" scope="Automation"}}' +
                    '</div>' +
                '</div>' +
                '<div class="text-danger def-error margin-top-sm" style="display:none"></div>' +
            '</div>',

        jsonMode: false,

        setup: function () {
            Dep.prototype.setup.call(this);
            this._paramViewNames = [];
            this._helperModels = {};
            this._kindSwitchBusy = false;

            this.listenTo(this.model, 'change:kind', (model, value, options) => {
                if (!this.isRendered() || !this.isEditMode()) {
                    return;
                }

                if ((options || {}).fromField === this.name || this._kindSwitchBusy) {
                    this.syncUiToRecordKind(false);

                    return;
                }

                this.onRecordKindChanged();
            });
        },

        tLabel: function (key) {
            return this.translate(key, 'labels', 'Automation');
        },

        tMessage: function (key) {
            return this.translate(key, 'messages', 'Automation');
        },

        data: function () {
            const d = Dep.prototype.data.call(this);
            const def = this.getDefinition() || {};
            const kind = (this.model.get('kind') || def.kind || 'batch').toString().toLowerCase();

            d.kind = kind === 'machine' ? 'machine' : 'batch';
            d.jsonMode = this.jsonMode;
            d.jsonText = this.formatJson(def);
            d.summaryHtml = this.buildSummaryHtml(def);

            return d;
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (!this.isEditMode()) {
                return;
            }

            this.$el.off('.defBuilder');
            this.$el.on('click.defBuilder', '[data-action="modeBuilder"]', () => this.switchMode(false));
            this.$el.on('click.defBuilder', '[data-action="modeJson"]', () => this.switchMode(true));
            this.$el.on('click.defBuilder', '[data-action="addStage"]', (e) => {
                const scope = $(e.currentTarget).data('scope') || 'forEach';
                this.readBuilderIntoMemory();
                this.ensureStages();
                const n = this._def.stages.length;
                const stage = {
                    id: 's' + n,
                    scope: scope === 'once' ? 'once' : 'forEach',
                    map: scope === 'once' ? [] : [{
                        id: 't',
                        entityType: 'Tenant',
                        mode: 'primary',
                        source: 'query',
                        where: {},
                    }],
                    actions: [],
                    onFailure: [],
                    itemMode: 'allMatching',
                    exportToRunBag: false,
                    importRunBag: false,
                };
                this._def.stages.push(stage);
                this.mirrorStagesTopLevel();
                this.renderBuilderLists();
            });
            this.$el.on('click.defBuilder', '[data-action="addMapStep"]', (e) => {
                const stageIndex = parseInt($(e.currentTarget).data('stage'), 10);
                this.readBuilderIntoMemory();
                this.ensureStages();
                const stage = this._def.stages[stageIndex];
                if (!stage || stage.scope === 'once') {
                    return;
                }
                stage.map = stage.map || [];
                stage.map.push({
                    id: 'm' + (stage.map.length + 1),
                    entityType: 'Contact',
                    mode: stage.map.length ? 'expand' : 'primary',
                    source: stage.map.length ? 'relation' : 'query',
                    parent: stage.map.length ? (stage.map[0].id || 't') : undefined,
                    where: {},
                });
                this.mirrorStagesTopLevel();
                this.renderBuilderLists();
            });
            this.$el.on('click.defBuilder', '[data-action="addAction"]', (e) => {
                const $t = $(e.currentTarget);
                const list = $t.data('list');
                const stageIndex = parseInt($t.data('stage'), 10);
                this.readBuilderIntoMemory();
                this.ensureStages();
                if (!isNaN(stageIndex) && this._def.stages[stageIndex]) {
                    const stage = this._def.stages[stageIndex];
                    stage[list] = stage[list] || [];
                    stage[list].push({type: 'notifyUser', params: {message: ''}});
                } else {
                    this._def[list] = this._def[list] || [];
                    this._def[list].push({type: 'notifyUser', params: {message: ''}});
                }
                this.mirrorStagesTopLevel();
                this.renderBuilderLists();
            });
            this.$el.on('click.defBuilder', '[data-action="addNestedAction"]', (e) => {
                const $t = $(e.currentTarget);
                const stateIndex = parseInt($t.data('state'), 10);
                const bucket = $t.data('bucket');
                this.readBuilderIntoMemory();
                const st = (this._def.states || [])[stateIndex];
                if (!st) {
                    return;
                }
                st[bucket] = st[bucket] || [];
                st[bucket].push({type: 'notifyUser', params: {message: ''}});
                this.renderBuilderLists();
            });
            this.$el.on('click.defBuilder', '[data-action="addState"]', () => {
                this.readBuilderIntoMemory();
                this._def.states = this._def.states || [];
                const id = 'S' + (this._def.states.length + 1);
                this._def.states.push({id: id, type: 'normal', onEnter: [], onExit: []});
                if (!this._def.initial) {
                    this._def.initial = id;
                }
                this.renderBuilderLists();
            });
            this.$el.on('click.defBuilder', '[data-action="addTransition"]', () => {
                this.readBuilderIntoMemory();
                this._def.transitions = this._def.transitions || [];
                const from = (this._def.states && this._def.states[0]) ? this._def.states[0].id : '';
                this._def.transitions.push({from: from, to: from, when: '', waitPeriod: ''});
                this.renderBuilderLists();
            });
            this.$el.on('click.defBuilder', '[data-action="removeRow"]', (e) => {
                const $t = $(e.currentTarget);
                const bucket = $t.data('bucket');
                const idx = parseInt($t.data('index'), 10);
                const parent = $t.data('parent');
                const parentIndex = parseInt($t.data('parent-index'), 10);
                this.readBuilderIntoMemory();
                if (parent === 'state' && !isNaN(parentIndex)) {
                    const st = (this._def.states || [])[parentIndex];
                    if (st && Array.isArray(st[bucket])) {
                        st[bucket].splice(idx, 1);
                    }
                }
                else if (parent === 'stage' && !isNaN(parentIndex)) {
                    this.ensureStages();
                    const stg = (this._def.stages || [])[parentIndex];
                    if (stg && Array.isArray(stg[bucket])) {
                        stg[bucket].splice(idx, 1);
                    }
                }
                else if (bucket === 'stages' && Array.isArray(this._def.stages)) {
                    this._def.stages.splice(idx, 1);
                }
                else if (Array.isArray(this._def[bucket])) {
                    this._def[bucket].splice(idx, 1);
                }
                this.mirrorStagesTopLevel();
                this.renderBuilderLists();
            });
            this.$el.on('click.defBuilder', '[data-action="addWhereRow"]', (e) => {
                const $btn = $(e.currentTarget);
                const idx = parseInt($btn.data('index'), 10);
                const stageIndex = parseInt($btn.data('stage'), 10);
                let $card;
                if (!isNaN(stageIndex)) {
                    $card = this.$el.find(
                        '.batch-stage-card[data-stage-index="' + stageIndex + '"] ' +
                        '.map-step[data-index="' + idx + '"]'
                    );
                } else {
                    $card = this.$el.find('.map-step[data-index="' + idx + '"]');
                }
                const prefix = $card.attr('data-view-prefix') || ('map-' + idx);
                const $rows = $card.find('.def-map-where-rows').first().length
                    ? $card.find('.def-map-where-rows').first()
                    : this.$el.find('.def-map-where-rows-' + idx);
                const $row = this.buildKvRow('', '', '', prefix);
                $rows.append($row);
                this.mountWhereValueExpression($row, prefix, '', '');
            });
            this.$el.on('click.defBuilder', '[data-action="addAggregateRow"]', (e) => {
                const $btn = $(e.currentTarget);
                const idx = parseInt($btn.data('index'), 10);
                const stageIndex = parseInt($btn.data('stage'), 10);
                let $card;
                if (!isNaN(stageIndex)) {
                    $card = this.$el.find(
                        '.batch-stage-card[data-stage-index="' + stageIndex + '"] ' +
                        '.map-step[data-index="' + idx + '"]'
                    );
                } else {
                    $card = this.$el.find('.map-step[data-index="' + idx + '"]');
                }
                const $rows = $card.find('.def-map-agg-rows').first().length
                    ? $card.find('.def-map-agg-rows').first()
                    : this.$el.find('.def-map-agg-rows-' + idx);
                $rows.append(this.buildAggregateRow({}));
            });
            this.$el.on('change.defBuilder', '.def-stage-scope', (e) => {
                const $card = $(e.currentTarget).closest('.batch-stage-card');
                const stageIndex = parseInt($card.attr('data-stage-index'), 10);
                this.readBuilderIntoMemory();
                this.ensureStages();
                const stg = this._def.stages[stageIndex];
                if (!stg) {
                    return;
                }
                stg.scope = $(e.currentTarget).val() === 'once' ? 'once' : 'forEach';
                if (stg.scope === 'forEach' && (!stg.map || !stg.map.length)) {
                    stg.map = [{
                        id: 't',
                        entityType: 'Tenant',
                        mode: 'primary',
                        source: 'query',
                        where: {},
                    }];
                }
                this.mirrorStagesTopLevel();
                this.renderBuilderLists();
            });
            this.$el.on('change.defBuilder', '.def-stage-export-enabled, .def-stage-import-enabled', (e) => {
                const $card = $(e.currentTarget).closest('.batch-stage-card');
                const isExport = $(e.currentTarget).hasClass('def-stage-export-enabled');
                const on = $(e.currentTarget).is(':checked');
                $card.find(isExport ? '.def-stage-export-keys' : '.def-stage-import-keys').prop('disabled', !on);
            });
            this.$el.on('click.defBuilder', '[data-action="removeKvRow"]', (e) => {
                $(e.currentTarget).closest('.automation-kv-row').remove();
            });
            this.$el.on('click.defBuilder', '[data-action="toggleAdvanced"]', (e) => {
                const $btn = $(e.currentTarget);
                const $panel = $btn.closest('.automation-card, .automation-nested-card');
                const $adv = $panel.find($btn.data('target') || '.automation-advanced').first();
                const open = $adv.hasClass('hidden');
                $adv.toggleClass('hidden', !open);
                $btn.attr('aria-expanded', open ? 'true' : 'false');
                $btn.find('.automation-advanced-label').text(
                    open ? this.tLabel('hideAdvanced') : this.tLabel('showAdvanced')
                );
            });
            this.$el.on('change.defBuilder', '.def-map-source, .def-map-mode, .def-map-parent', (e) => {
                const $card = $(e.currentTarget).closest('.map-step');
                this.applyMapStepVisibility($card);
                this.updateMapStepTitle($card);
            });
            this.$el.on('change.defBuilder input.defBuilder', '.def-map-id, .def-map-et', (e) => {
                const $card = $(e.currentTarget).closest('.map-step');
                this.applyMapStepVisibility($card);
                this.updateMapStepTitle($card);
            });
            this.$el.on('input.defBuilder change.defBuilder', '.def-map-id', () => {
                this.refreshParentSelects();
            });
            this.$el.on('change.defBuilder', '.def-action-type', (e) => {
                const $card = $(e.currentTarget).closest('[data-action-card]');
                const type = $(e.currentTarget).val();
                const prevPrefix = $card.attr('data-view-prefix');
                let preservedWhen = '';
                if (prevPrefix) {
                    preservedWhen = this.readFormulaField(prevPrefix, '__when');
                }
                this.mountActionParams($card, type, {}, {});
                const prefix = $card.attr('data-view-prefix');
                const $whenHost = $card.find('.def-action-when-host').empty();
                if (prefix && $whenHost.length) {
                    this.mountExpression(prefix, '__when', $whenHost, {
                        multiline: true,
                        rows: 2,
                        placeholder: "entity\\attribute('status') == 'Approved'",
                        expressionPlaceholder: this.tMessage('whenExpressionPlaceholder'),
                        fixedValue: preservedWhen,
                        expressionValue: preservedWhen,
                        mode: preservedWhen ? 'expression' : 'fixed',
                        snippets: this.buildAutomationSnippets(),
                    });
                }
                this.updateActionTitle($card);
            });
            this.$el.on('change.defBuilder', '.def-st-type', (e) => {
                const $card = $(e.currentTarget).closest('.state-card');
                this.applyStateVisibility($card);
                this.updateStateTypeHelp($card);
                this.updateStateCardTitle($card);
            });
            this.$el.on('change.defBuilder', '.def-st-wait-mode', (e) => {
                this.applyStateVisibility($(e.currentTarget).closest('.state-card'));
            });
            this.$el.on('change.defBuilder', '.def-tr-wait-mode', (e) => {
                this.applyTransitionWaitVisibility($(e.currentTarget).closest('.automation-card'));
            });
            this.$el.on('input.defBuilder change.defBuilder', '.def-st-id', (e) => {
                this.refreshStateSelects();
                this.updateStateCardTitle($(e.currentTarget).closest('.state-card'));
            });

            this._def = this.definitionForRecordKind(this.getDefinition());
            this.seedBuilderFromDef();
            this.renderBuilderLists();
            this.syncUiToRecordKind(false);
            this.$el.find('.def-json').val(this.formatJson(this._def));
        },

        onRemove: function () {
            this.clearParamViews();
            Dep.prototype.onRemove.call(this);
        },

        clearParamViews: function () {
            (this._paramViewNames || []).forEach((name) => {
                this.clearView(name);
            });
            this._paramViewNames = [];
            this._helperModels = {};
            this._expressionInputs = {};
        },

        switchMode: function (toJson) {
            if (toJson) {
                this.readBuilderIntoMemory();
                this.$el.find('.def-json').val(this.formatJson(this._def));
                this.$el.find('.builder-pane').hide();
                this.$el.find('.json-pane').show();
                this.$el.find('.mode-json').addClass('active');
                this.$el.find('.mode-builder').removeClass('active');
            } else {
                try {
                    const parsed = JSON.parse(this.$el.find('.def-json').val() || '{}');
                    this._def = parsed && typeof parsed === 'object'
                        ? this.definitionForRecordKind(parsed)
                        : this.defaultDefinition();
                    this.syncRecordKind(this._def.kind || 'batch');
                    this.clearError();
                } catch (err) {
                    this.showError(this.tMessage('invalidJsonSwitchBuilder'));
                    return;
                }
                this.seedBuilderFromDef();
                this.renderBuilderLists();
                this.syncUiToRecordKind(false);
                this.$el.find('.json-pane').hide();
                this.$el.find('.builder-pane').show();
                this.$el.find('.mode-builder').addClass('active');
                this.$el.find('.mode-json').removeClass('active');
            }
            this.jsonMode = toJson;
        },

        recordKindKey: function () {
            const kind = (this.model.get('kind') || 'Batch').toString().toLowerCase();

            return kind === 'machine' ? 'machine' : 'batch';
        },

        definitionKindKey: function (definition) {
            if (!definition || typeof definition !== 'object') {
                return null;
            }

            const actualKind = (definition.kind || '').toString().toLowerCase();
            if (actualKind === 'machine' || Array.isArray(definition.states)) {
                return 'machine';
            }
            if (actualKind === 'batch' || Array.isArray(definition.map)) {
                return 'batch';
            }

            return null;
        },

        hasSubstantiveDefinition: function (definition) {
            if (!definition || typeof definition !== 'object') {
                return false;
            }

            if (Array.isArray(definition.map) && definition.map.length) {
                return true;
            }
            if (Array.isArray(definition.actions) && definition.actions.length) {
                return true;
            }
            if (Array.isArray(definition.states) && definition.states.length) {
                return true;
            }
            if (Array.isArray(definition.transitions) && definition.transitions.length) {
                return true;
            }

            return false;
        },

        currentWorkingDefinition: function () {
            if (this.jsonMode) {
                try {
                    return JSON.parse(this.$el.find('.def-json').val() || '{}');
                } catch (e) {
                    return this._def || this.getDefinition();
                }
            }

            if (this.isRendered() && this.isEditMode()) {
                try {
                    this.readBuilderIntoMemory();
                } catch (e) {
                    // keep prior memory
                }
            }

            return this._def || this.getDefinition();
        },

        onRecordKindChanged: function () {
            const expected = this.recordKindKey();
            const current = this.currentWorkingDefinition();
            const actual = this.definitionKindKey(current);
            const needsReset = actual && actual !== expected;

            if (!needsReset) {
                this._def = this.definitionForRecordKind(current);
                this.seedBuilderFromDef();
                this.renderBuilderLists();
                this.syncUiToRecordKind(false);
                this.$el.find('.def-json').val(this.formatJson(this._def));

                return;
            }

            if (!this.hasSubstantiveDefinition(current)) {
                this.applyRecordKindReset();

                return;
            }

            Espo.Ui.confirm(this.tMessage('confirmKindSwitchReset'), {
                confirmText: this.translate('Yes'),
                cancelText: this.translate('Cancel'),
                confirmStyle: 'danger',
                cancelCallback: () => {
                    this._kindSwitchBusy = true;
                    this.syncRecordKind(actual);
                    this._kindSwitchBusy = false;
                    this.syncUiToRecordKind(false);
                },
            }).then(() => {
                this.applyRecordKindReset();
            });
        },

        applyRecordKindReset: function () {
            this._def = this.defaultDefinition(this.recordKindKey());
            this.seedBuilderFromDef();
            this.renderBuilderLists();
            this.syncUiToRecordKind(false);
            this.$el.find('.def-json').val(this.formatJson(this._def));
            this.trigger('change');
        },

        syncUiToRecordKind: function () {
            const kind = this.recordKindKey();

            if (kind === 'machine') {
                this.$el.find('.batch-pane').hide();
                this.$el.find('.batch-only').hide();
                this.$el.find('.machine-pane').show();
                this.$el.find('.def-kind-title').text(this.tLabel('Machine'));
                this.$el.find('.def-kind-hint').text(this.tMessage('kindHintMachine'));
            } else {
                this.$el.find('.machine-pane').hide();
                this.$el.find('.batch-pane').show();
                this.$el.find('.batch-only').show();
                this.$el.find('.def-kind-title').text(this.tLabel('Batch'));
                this.$el.find('.def-kind-hint').text(this.tMessage('kindHintBatch'));
            }
        },

        defaultDefinition: function (requestedKind) {
            const kind = (requestedKind || this.model.get('kind') || 'Batch').toString().toLowerCase();
            if (kind === 'machine') {
                return {
                    kind: 'machine',
                    initial: 'Start',
                    states: [
                        {id: 'Start', type: 'normal', onEnter: [], onExit: []},
                        {id: 'Done', type: 'final', onEnter: [], onExit: []},
                    ],
                    transitions: [{from: 'Start', to: 'Done'}],
                };
            }

            return {
                kind: 'batch',
                stages: [{
                    id: 's0',
                    scope: 'forEach',
                    map: [{id: 't', entityType: 'Tenant', mode: 'primary', source: 'query', where: {}}],
                    itemMode: 'allMatching',
                    actions: [],
                    onFailure: [],
                }],
                map: [{id: 't', entityType: 'Tenant', mode: 'primary', source: 'query', where: {}}],
                itemMode: 'allMatching',
                actions: [],
                onFailure: [],
                limits: {maxItems: 5000, maxExpandPerParent: 500},
            };
        },

        getDefinition: function () {
            let v = this.model.get(this.name);
            if (!v) {
                return null;
            }
            if (typeof v === 'string') {
                try {
                    v = JSON.parse(v);
                } catch (e) {
                    return null;
                }
            }

            return v;
        },

        definitionForRecordKind: function (definition) {
            const kind = (this.model.get('kind') || 'Batch').toString().toLowerCase();
            const expectedKind = kind === 'machine' ? 'machine' : 'batch';

            if (!definition || typeof definition !== 'object') {
                return this.defaultDefinition(expectedKind);
            }

            const actualKind = (definition.kind || '').toString().toLowerCase();
            const isCompatible = expectedKind === 'machine'
                ? (actualKind === 'machine' || Array.isArray(definition.states))
                : (actualKind === 'batch' || Array.isArray(definition.map) || Array.isArray(definition.stages));

            if (!isCompatible) {
                return this.defaultDefinition(expectedKind);
            }

            if (expectedKind === 'batch') {
                return this.normalizeStagesDef(definition);
            }

            return definition;
        },

        /**
         * Expand legacy {map, actions} into stages[] for the builder.
         */
        normalizeStagesDef: function (definition) {
            let def;
            try {
                def = JSON.parse(JSON.stringify(definition || {}));
            } catch (e) {
                def = {kind: 'batch'};
            }
            def.kind = 'batch';

            if (!Array.isArray(def.stages) || !def.stages.length) {
                def.stages = [{
                    id: 's0',
                    scope: 'forEach',
                    map: Array.isArray(def.map) && def.map.length
                        ? def.map
                        : [{id: 't', entityType: 'Tenant', mode: 'primary', source: 'query', where: {}}],
                    actions: Array.isArray(def.actions) ? def.actions : [],
                    onFailure: Array.isArray(def.onFailure) ? def.onFailure : [],
                    itemMode: def.itemMode || 'allMatching',
                }];
            }

            def.stages = def.stages.map((s, i) => {
                const scope = (s && (s.scope || s.type)) === 'once' ? 'once' : 'forEach';
                return {
                    id: (s && s.id) || ('s' + i),
                    scope: scope,
                    map: scope === 'forEach'
                        ? (Array.isArray(s.map) && s.map.length ? s.map : (
                            i === 0 && Array.isArray(def.map) ? def.map : []
                        ))
                        : (Array.isArray(s.map) ? s.map : []),
                    actions: Array.isArray(s.actions) ? s.actions : [],
                    onFailure: Array.isArray(s.onFailure) ? s.onFailure : [],
                    itemMode: (s && s.itemMode) || 'allMatching',
                    exportToRunBag: this.normalizeRunBagFlag(s && s.exportToRunBag),
                    importRunBag: this.normalizeRunBagFlag(s && s.importRunBag),
                };
            });

            this.applyStagesMirrors(def);

            return def;
        },

        ensureStages: function () {
            if (!this._def) {
                this._def = this.defaultDefinition('batch');
            }
            if (!Array.isArray(this._def.stages) || !this._def.stages.length) {
                this._def = this.normalizeStagesDef(this._def);
            }
        },

        /**
         * Keep true | false | string[] | config object for run bag flags.
         */
        normalizeRunBagFlag: function (raw) {
            if (raw === true || raw === 1 || raw === '1' || raw === 'true') {
                return true;
            }
            if (raw === false || raw === null || raw === undefined || raw === 0 || raw === '0' || raw === '') {
                return false;
            }
            if (typeof raw === 'string') {
                const keys = raw.split(/[\s,;]+/).map(s => s.trim()).filter(Boolean);
                return keys.length ? keys : false;
            }
            if (Array.isArray(raw)) {
                const keys = raw.map(s => String(s).trim()).filter(Boolean);
                return keys.length ? keys : false;
            }
            if (typeof raw === 'object') {
                return raw;
            }
            return false;
        },

        runBagFlagEnabled: function (raw) {
            const v = this.normalizeRunBagFlag(raw);
            if (v === false) {
                return false;
            }
            return true;
        },

        runBagKeysString: function (raw) {
            const v = this.normalizeRunBagFlag(raw);
            if (v === true || v === false) {
                return '';
            }
            if (Array.isArray(v)) {
                return v.join(', ');
            }
            if (v && typeof v === 'object') {
                const keys = v.keys;
                if (keys === true || keys === '*' || keys === 'all' || keys === undefined) {
                    return '';
                }
                if (Array.isArray(keys)) {
                    return keys.join(', ');
                }
                if (typeof keys === 'string') {
                    return keys;
                }
            }
            return '';
        },

        readRunBagFlagFromCard: function ($stage, kind) {
            const enabled = $stage.find('.def-stage-' + kind + '-enabled').is(':checked');
            if (!enabled) {
                return false;
            }
            const keysRaw = ($stage.find('.def-stage-' + kind + '-keys').val() || '').trim();
            if (!keysRaw) {
                return true;
            }
            const keys = keysRaw.split(/[\s,;]+/).map(s => s.trim()).filter(Boolean);
            return keys.length ? keys : true;
        },

        mirrorStagesTopLevel: function () {
            if (!this._def || this._def.kind === 'machine') {
                return;
            }
            this.applyStagesMirrors(this._def);
        },

        applyStagesMirrors: function (def) {
            if (!def || !Array.isArray(def.stages)) {
                return;
            }
            let mirror = null;
            def.stages.forEach((s) => {
                if (!mirror && s && s.scope === 'forEach') {
                    mirror = s;
                }
            });
            if (!mirror) {
                mirror = def.stages[0] || null;
            }
            if (!mirror) {
                return;
            }
            def.map = mirror.map || [];
            def.actions = mirror.actions || [];
            def.onFailure = mirror.onFailure || [];
            def.itemMode = mirror.itemMode || 'allMatching';
        },

        syncRecordKind: function (kind) {
            const normalized = String(kind || '').toLowerCase() === 'machine' ? 'Machine' : 'Batch';

            if (this.model.get('kind') !== normalized) {
                this.model.set('kind', normalized, {ui: true, fromField: this.name});
            }
        },

        formatJson: function (obj) {
            try {
                return JSON.stringify(obj || {}, null, 2);
            } catch (e) {
                return '{}';
            }
        },

        buildSummaryHtml: function (def) {
            if (!def || typeof def !== 'object') {
                return '';
            }
            const kind = (def.kind || '').toString();
            let html = '<div><strong>' + this.getHelper().escapeString(kind || '?') + '</strong></div><ul class="list-unstyled" style="margin:6px 0 0">';

            if (kind === 'batch' || Array.isArray(def.map) || Array.isArray(def.stages)) {
                const stages = Array.isArray(def.stages) && def.stages.length
                    ? def.stages
                    : [{
                        id: 's0',
                        scope: 'forEach',
                        map: def.map || [],
                        actions: def.actions || [],
                    }];
                html += '<li>' + this.getHelper().escapeString(this.tLabel('Stages')) + ': ' +
                    stages.length + '</li>';
                stages.forEach((st, i) => {
                    const scope = (st && st.scope) || 'forEach';
                    const scopeLabel = scope === 'once'
                        ? this.tLabel('scopeOnce')
                        : this.tLabel('scopeForEach');
                    html += '<li class="text-muted">• ' + (i + 1) + '. ' +
                        this.getHelper().escapeString((st && st.id) || ('s' + i)) +
                        ' · ' + this.getHelper().escapeString(scopeLabel) +
                        ' · map ' + ((st && st.map) || []).length +
                        ' · actions ' + ((st && st.actions) || []).length +
                        '</li>';
                });
            }

            if (kind === 'machine' || Array.isArray(def.states)) {
                html += '<li>' + this.getHelper().escapeString(this.tLabel('Initial')) + ': ' +
                    this.getHelper().escapeString(def.initial || '') + '</li>';
                html += '<li>' + this.getHelper().escapeString(this.tLabel('States')) + ': ' +
                    ((def.states || []).length) +
                    ', ' + this.getHelper().escapeString(this.tLabel('Transitions')) + ': ' +
                    ((def.transitions || []).length) + '</li>';
                (def.states || []).forEach((s) => {
                    let waitBit = '';
                    if (s && s.waitPeriod) {
                        waitBit = ' wait ' + this.getHelper().escapeString(s.waitPeriod);
                    } else if (s && (s.waitUntil || s.waitUntilFormula)) {
                        waitBit = ' until ' + this.getHelper().escapeString(
                            s.waitUntilFormula ? 'fx' : s.waitUntil
                        );
                    }
                    html += '<li class="text-muted">• ' +
                        this.getHelper().escapeString((s && s.id) || '?') +
                        ' (' + this.getHelper().escapeString((s && s.type) || 'normal') + ')' +
                        waitBit +
                        '</li>';
                });
            }

            html += '</ul>';

            return html;
        },

        seedBuilderFromDef: function () {
            const def = this._def || {};
            this.$el.find('.def-max-items').val((def.limits && def.limits.maxItems) || 5000);
            this.$el.find('.def-max-expand').val((def.limits && def.limits.maxExpandPerParent) || 500);
        },

        entityOptions: function () {
            const fromMeta = this.getMetadata().get(['entityDefs']) || {};
            const hard = ['Tenant', 'User', 'Contact', 'Account', 'Lead', 'Opportunity', 'Task', 'Case', 'Meeting', 'Call'];
            const keys = Object.keys(fromMeta).filter((k) => {
                if (k.charAt(0) === '_') {
                    return false;
                }
                const d = fromMeta[k] || {};
                return !d.disabled && !d.entityManager?.excludeFromAutomation;
            });
            const merged = [...new Set(hard.concat(keys))].sort((a, b) => a.localeCompare(b));

            return merged.length ? merged : hard;
        },

        modeOptions: function () {
            return [
                {value: 'primary', label: this.tLabel('modePrimary')},
                {value: 'expand', label: this.tLabel('modeExpand')},
                {value: 'loop', label: this.tLabel('modeLoop')},
                {value: 'passThrough', label: this.tLabel('modePassThrough')},
                {value: 'groupBy', label: this.tLabel('modeGroupBy')},
            ];
        },

        sourceOptions: function () {
            return [
                {value: 'query', label: this.tLabel('sourceQuery')},
                {value: 'relation', label: this.tLabel('sourceRelation')},
                {value: 'linkMultiple', label: this.tLabel('sourceLinkMultiple')},
                {value: 'ids', label: this.tLabel('sourceIds')},
                {value: 'payload', label: this.tLabel('sourcePayload')},
                {value: 'report', label: this.tLabel('sourceReport')},
            ];
        },

        actionTypeOptions: function () {
            return this.getMetadata().get(['app', 'automationActionTypes', 'typeList']) || [
                'notifyUser', 'sendWhatsAppMessage', 'createTask', 'startChildAutomation',
            ];
        },

        actionTypeLabel: function (type) {
            const translated = this.translate(type, 'labels', 'Automation');
            if (translated && translated !== type) {
                return translated;
            }

            return type
                .replace(/([A-Z])/g, ' $1')
                .replace(/^./, (s) => s.toUpperCase())
                .trim();
        },

        getActionParamDefs: function (type) {
            const meta = this.getMetadata().get(['app', 'automationActionTypes', 'types', type]) || {};

            return meta.paramDefs || [];
        },

        renderBuilderLists: function () {
            this.clearParamViews();
            this.renderStages();
            this.renderStates();
            this.renderTransitions();
            this.refreshStateSelects();
        },

        // ─── Batch stages ────────────────────────────────────────────

        renderStages: function () {
            const $list = this.$el.find('.stage-list');
            if (!$list.length) {
                return;
            }
            $list.empty();

            // Clear map expression hosts
            Object.keys(this._expressionInputs || {}).forEach((key) => {
                if (key.indexOf('map-') === 0 || key.indexOf('stage-') === 0) {
                    delete this._expressionInputs[key];
                }
            });

            this.ensureStages();
            const stages = this._def.stages || [];

            stages.forEach((stage, stageIndex) => {
                const scope = stage.scope === 'once' ? 'once' : 'forEach';
                const $card = $('<div class="automation-card batch-stage-card panel panel-default">')
                    .attr('data-stage-index', stageIndex);

                const scopeLabel = scope === 'once'
                    ? this.tLabel('scopeOnce')
                    : this.tLabel('scopeForEach');

                $card.append(
                    $('<div class="automation-card-header">').append(
                        $('<div class="automation-card-title">').text(
                            (stageIndex + 1) + '. ' + (stage.id || ('s' + stageIndex)) + ' · ' + scopeLabel
                        ),
                        $('<button type="button" class="btn btn-link btn-sm text-danger">')
                            .attr({
                                'data-action': 'removeRow',
                                'data-bucket': 'stages',
                                'data-index': stageIndex,
                            })
                            .text(this.tLabel('remove'))
                    )
                );

                const $body = $('<div class="automation-card-body">');

                $body.append(
                    $('<div class="row">').append(
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('stageId'),
                                $('<input type="text" class="form-control def-stage-id">')
                                    .val(stage.id || ('s' + stageIndex))
                                    .attr('placeholder', 's' + stageIndex)
                            )
                        ),
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('stageScope'),
                                this.buildSelect(
                                    'form-control def-stage-scope',
                                    [
                                        {value: 'forEach', label: this.tLabel('scopeForEach')},
                                        {value: 'once', label: this.tLabel('scopeOnce')},
                                    ],
                                    scope
                                ),
                                this.tMessage('stageScopeHint')
                            )
                        ),
                        $('<div class="col-sm-4 stage-f-itemmode">').append(
                            this.fieldGroup(
                                this.tLabel('Item mode'),
                                this.buildSelect(
                                    'form-control def-stage-item-mode',
                                    [
                                        {value: 'allMatching', label: this.tLabel('itemModeAllMatching')},
                                        {value: 'firstMatch', label: this.tLabel('itemModeFirstMatch')},
                                    ],
                                    stage.itemMode || 'allMatching'
                                ),
                                this.tMessage('itemModeHint')
                            )
                        )
                    )
                );

                // Cross-stage run data bag (opt-in)
                const exportOn = this.runBagFlagEnabled(stage.exportToRunBag);
                const importOn = this.runBagFlagEnabled(stage.importRunBag);
                $body.append(
                    $('<div class="row stage-runbag-row">').append(
                        $('<div class="col-sm-6">').append(
                            this.fieldGroup(
                                this.tLabel('exportToRunBagSection'),
                                $('<div>').append(
                                    $('<label class="checkbox-inline">').append(
                                        $('<input type="checkbox" class="def-stage-export-enabled">')
                                            .prop('checked', exportOn),
                                        ' ' + this.getHelper().escapeString(this.tLabel('exportToRunBagEnable'))
                                    ),
                                    $('<input type="text" class="form-control def-stage-export-keys" style="margin-top:6px;">')
                                        .val(this.runBagKeysString(stage.exportToRunBag))
                                        .attr('placeholder', this.tLabel('runBagKeysPlaceholder'))
                                        .prop('disabled', !exportOn)
                                ),
                                this.tMessage('exportToRunBagHint')
                            )
                        ),
                        $('<div class="col-sm-6">').append(
                            this.fieldGroup(
                                this.tLabel('importRunBagSection'),
                                $('<div>').append(
                                    $('<label class="checkbox-inline">').append(
                                        $('<input type="checkbox" class="def-stage-import-enabled">')
                                            .prop('checked', importOn),
                                        ' ' + this.getHelper().escapeString(this.tLabel('importRunBagEnable'))
                                    ),
                                    $('<input type="text" class="form-control def-stage-import-keys" style="margin-top:6px;">')
                                        .val(this.runBagKeysString(stage.importRunBag))
                                        .attr('placeholder', this.tLabel('runBagKeysPlaceholder'))
                                        .prop('disabled', !importOn)
                                ),
                                this.tMessage('importRunBagHint')
                            )
                        )
                    )
                );

                // Map subblock (forEach only)
                const $mapBlock = $('<div class="automation-subblock stage-map-block">');
                $mapBlock.append(
                    $('<div class="automation-subblock-title">').text(this.tLabel('Map steps')),
                    $('<p class="text-muted small automation-field-hint">').text(this.tMessage('mapStepsHint')),
                    $('<div class="map-steps">'),
                    $('<button type="button" class="btn btn-default btn-sm">')
                        .attr({'data-action': 'addMapStep', 'data-stage': stageIndex})
                        .html('<span class="fas fa-plus"></span> ' +
                            this.getHelper().escapeString(this.tLabel('addMapStep')))
                );
                $body.append($mapBlock);

                // Actions forEach / once
                const actionsTitle = scope === 'once'
                    ? this.tLabel('onceActions')
                    : this.tLabel('forEachActions');
                const actionsHint = scope === 'once'
                    ? this.tMessage('onceActionsHint')
                    : this.tMessage('actionsHint');

                const $actionsBlock = $('<div class="automation-subblock stage-actions-block">');
                $actionsBlock.append(
                    $('<div class="automation-subblock-title">').text(actionsTitle),
                    $('<p class="text-muted small automation-field-hint">').text(actionsHint),
                    $('<div class="action-list">').attr('data-list', 'actions'),
                    $('<button type="button" class="btn btn-default btn-sm">')
                        .attr({
                            'data-action': 'addAction',
                            'data-list': 'actions',
                            'data-stage': stageIndex,
                        })
                        .html('<span class="fas fa-plus"></span> ' +
                            this.getHelper().escapeString(this.tLabel('addAction')))
                );
                $body.append($actionsBlock);

                const $failBlock = $('<div class="automation-subblock stage-onfailure-block">');
                $failBlock.append(
                    $('<div class="automation-subblock-title">').text(this.tLabel('On failure')),
                    $('<p class="text-muted small automation-field-hint">').text(this.tMessage('onFailureHint')),
                    $('<div class="action-list">').attr('data-list', 'onFailure'),
                    $('<button type="button" class="btn btn-default btn-sm">')
                        .attr({
                            'data-action': 'addAction',
                            'data-list': 'onFailure',
                            'data-stage': stageIndex,
                        })
                        .html('<span class="fas fa-plus"></span> ' +
                            this.getHelper().escapeString(this.tLabel('addAction')))
                );
                $body.append($failBlock);

                $card.append($body);
                $list.append($card);

                // Visibility
                $card.find('.stage-map-block').toggle(scope === 'forEach');
                $card.find('.stage-f-itemmode').toggle(scope === 'forEach');

                if (scope === 'forEach') {
                    this.renderMapSteps(
                        $card.find('.map-steps'),
                        stage.map || [],
                        stageIndex
                    );
                }

                this.renderActionList(
                    'actions',
                    $card.find('.action-list[data-list="actions"]'),
                    stage.actions || [],
                    {stageIndex: stageIndex, bucket: 'actions', parent: 'stage'}
                );
                this.renderActionList(
                    'onFailure',
                    $card.find('.action-list[data-list="onFailure"]'),
                    stage.onFailure || [],
                    {stageIndex: stageIndex, bucket: 'onFailure', parent: 'stage'}
                );
            });
        },

        // ─── Map steps ───────────────────────────────────────────────

        renderMapSteps: function ($container, mapList, stageIndex) {
            const $c = ($container && $container.length)
                ? $container.empty()
                : this.$el.find('.map-steps').first().empty();

            if (!$c.length) {
                return;
            }

            const map = mapList || ((this._def && this._def.map) || []);
            const entityOpts = this.entityOptions();
            const modeOpts = this.modeOptions();
            const sourceOpts = this.sourceOptions();
            const parentOpts = map.map((s, i) => ({
                value: s.id || ('s' + (i + 1)),
                label: (s.id || ('s' + (i + 1))) + (s.entityType ? ' · ' + s.entityType : ''),
            }));
            const stageAttr = (stageIndex === undefined || stageIndex === null || isNaN(stageIndex))
                ? {}
                : {'data-stage': stageIndex};

            map.forEach((step, index) => {
                const prefix = 'map-' + (stageIndex != null ? stageIndex + '-' : '') +
                    index + '-' + Math.floor(Math.random() * 1e6);
                const $card = $('<div class="automation-card map-step panel panel-default">')
                    .attr('data-index', index)
                    .attr('data-view-prefix', prefix)
                    .data('originalStep', step);

                if (stageIndex != null && !isNaN(stageIndex)) {
                    $card.attr('data-stage-index', stageIndex);
                }

                $card.append(
                    $('<div class="automation-card-header">').append(
                        $('<div class="automation-card-title def-map-title">').text(this.mapStepTitle(step, index)),
                        $('<button type="button" class="btn btn-link btn-sm text-danger">')
                            .attr(Object.assign({
                                'data-action': 'removeRow',
                                'data-bucket': 'map',
                                'data-index': index,
                            }, (stageIndex != null && !isNaN(stageIndex)) ? {
                                'data-parent': 'stage',
                                'data-parent-index': stageIndex,
                            } : {}))
                            .text(this.tLabel('remove'))
                    )
                );

                const $body = $('<div class="automation-card-body">');

                $body.append(
                    $('<div class="row">').append(
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('id'),
                                $('<input type="text" class="form-control def-map-id">')
                                    .val(step.id || '')
                                    .attr('placeholder', 's' + (index + 1)),
                                this.tMessage('mapIdHint')
                            )
                        ),
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('mode'),
                                this.buildSelect('form-control def-map-mode', modeOpts, step.mode || 'primary'),
                                this.tMessage('mapModeHint')
                            )
                        ),
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('source'),
                                this.buildSelect('form-control def-map-source', sourceOpts, step.source || 'query'),
                                this.tMessage('mapSourceHint')
                            )
                        )
                    )
                );

                $body.append(
                    $('<div class="row">').append(
                        $('<div class="col-sm-4 map-f-entity">').append(
                            this.fieldGroup(
                                this.tLabel('entityType'),
                                this.buildSelect(
                                    'form-control def-map-et',
                                    entityOpts.map((e) => ({value: e, label: e})),
                                    step.entityType || 'Contact'
                                )
                            )
                        ),
                        $('<div class="col-sm-4 map-f-parent">').append(
                            this.fieldGroup(
                                this.tLabel('parent'),
                                this.buildSelect(
                                    'form-control def-map-parent',
                                    [{value: '', label: '—'}].concat(
                                        parentOpts.filter((o) => o.value !== (step.id || ''))
                                    ),
                                    step.parent || ''
                                ),
                                this.tMessage('mapParentHint')
                            )
                        ),
                        $('<div class="col-sm-4 map-f-relation">').append(
                            this.fieldGroup(
                                this.tLabel('relation'),
                                $('<input type="text" class="form-control def-map-rel">')
                                    .val(step.relation || '')
                                    .attr('placeholder', 'users'),
                                this.tMessage('mapRelationHint')
                            )
                        )
                    )
                );

                $body.append(
                    $('<div class="row">').append(
                        $('<div class="col-sm-4 map-f-link">').append(
                            this.fieldGroup(
                                this.tLabel('linkField'),
                                $('<input type="text" class="form-control def-map-link">')
                                    .val(step.link || step.linkMultiple || '')
                                    .attr('placeholder', 'contacts'),
                                this.tMessage('mapLinkHint')
                            )
                        ),
                        $('<div class="col-sm-4 map-f-payload">').append(
                            this.fieldGroup(
                                this.tLabel('payloadPath'),
                                $('<input type="text" class="form-control def-map-ppath">')
                                    .val(step.payloadPath || '')
                                    .attr('placeholder', 'items'),
                                this.tMessage('mapPayloadPathHint')
                            )
                        ),
                        $('<div class="col-sm-4 map-f-idspath">').append(
                            this.fieldGroup(
                                this.tLabel('idsPath'),
                                $('<input type="text" class="form-control def-map-idspath">')
                                    .val(step.idsPath || '')
                                    .attr('placeholder', 'ids'),
                                this.tMessage('mapIdsPathHint')
                            )
                        )
                    )
                );

                $body.append(
                    $('<div class="row">').append(
                        $('<div class="col-sm-6 map-f-ids">').append(
                            this.fieldGroup(
                                this.tLabel('idsList'),
                                $('<textarea class="form-control def-map-ids" rows="2">')
                                    .val(this.formatIdsList(step.ids))
                                    .attr('placeholder', this.tMessage('idsListPlaceholder')),
                                this.tMessage('mapIdsHint')
                            )
                        ),
                        $('<div class="col-sm-4 map-f-report">').append(
                            this.fieldGroup(
                                this.tLabel('reportId'),
                                $('<input type="text" class="form-control def-map-report">')
                                    .val(step.reportId || ''),
                                this.tMessage('mapReportHint')
                            )
                        ),
                        $('<div class="col-sm-2 map-f-maxrows">').append(
                            this.fieldGroup(
                                this.tLabel('maxRows'),
                                $('<input type="number" min="1" max="10000" class="form-control def-map-maxrows">')
                                    .val(step.maxRows || '')
                                    .attr('placeholder', '500'),
                                this.tMessage('mapMaxRowsHint')
                            )
                        )
                    )
                );

                $body.append(
                    $('<div class="row">').append(
                        $('<div class="col-sm-4 map-f-requireroles">').append(
                            this.fieldGroup(
                                this.tLabel('requireRoles'),
                                $('<input type="text" class="form-control def-map-requireroles">')
                                    .val(this.formatStringList(step.requireRoles))
                                    .attr('placeholder', 'tenant-admin'),
                                this.tMessage('mapRequireRolesHint')
                            )
                        ),
                        $('<div class="col-sm-4 map-f-fk">').append(
                            this.fieldGroup(
                                this.tLabel('foreignKey'),
                                $('<input type="text" class="form-control def-map-fk">')
                                    .val(step.foreignKey || '')
                                    .attr('placeholder', 'tenantId'),
                                this.tMessage('mapForeignKeyHint')
                            )
                        ),
                        $('<div class="col-sm-4 map-f-ignoreparent">').append(
                            this.fieldGroup(
                                this.tLabel('ignoreParent'),
                                $('<div>').append(
                                    $('<label class="checkbox-inline">').append(
                                        $('<input type="checkbox" class="def-map-ignoreparent">')
                                            .prop('checked', !!step.ignoreParent),
                                        ' ' + this.getHelper().escapeString(this.tLabel('ignoreParentEnable'))
                                    )
                                ),
                                this.tMessage('mapIgnoreParentHint')
                            )
                        )
                    )
                );

                // groupBy block
                const $group = $('<div class="map-f-groupby automation-subblock">');
                $group.append($('<div class="automation-subblock-title">').text(this.tLabel('groupBySection')));
                $group.append(
                    $('<div class="row">').append(
                        $('<div class="col-sm-6">').append(
                            this.fieldGroup(
                                this.tLabel('groupBy'),
                                $('<input type="text" class="form-control def-map-groupby">')
                                    .val(Array.isArray(step.groupBy) ? step.groupBy.join(', ') : (step.groupBy || ''))
                                    .attr('placeholder', 'assignedUserId'),
                                this.tMessage('mapGroupByHint')
                            )
                        ),
                        $('<div class="col-sm-6">').append(
                            this.fieldGroup(
                                this.tLabel('groupTargetEntityType'),
                                this.buildSelect(
                                    'form-control def-map-gtarget',
                                    [{value: '', label: '—'}].concat(
                                        entityOpts.map((e) => ({value: e, label: e}))
                                    ),
                                    step.groupTargetEntityType || ''
                                )
                            )
                        )
                    )
                );

                const tb = step.timeBucket && typeof step.timeBucket === 'object' ? step.timeBucket : {};
                $group.append(
                    $('<div class="row">').append(
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('timeBucketField'),
                                $('<input type="text" class="form-control def-map-tb-field">')
                                    .val(tb.field || '')
                                    .attr('placeholder', 'closeDate')
                            )
                        ),
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('timeBucketSize'),
                                this.buildSelect(
                                    'form-control def-map-tb-size',
                                    [
                                        {value: '', label: '—'},
                                        {value: '1 hour', label: this.tLabel('tb1hour')},
                                        {value: '1 day', label: this.tLabel('tb1day')},
                                        {value: '1 week', label: this.tLabel('tb1week')},
                                    ],
                                    tb.size || ''
                                )
                            )
                        ),
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('timeBucketTimezone'),
                                $('<input type="text" class="form-control def-map-tb-tz">')
                                    .val(tb.timezone || '')
                                    .attr('placeholder', 'America/Sao_Paulo')
                            )
                        )
                    )
                );

                const $aggRows = $('<div class="def-map-agg-rows def-map-agg-rows-' + index + '">');
                const aggs = Array.isArray(step.aggregates) ? step.aggregates : [];
                if (aggs.length) {
                    aggs.forEach((a) => {
                        $aggRows.append(this.buildAggregateRow(a || {}));
                    });
                }
                $group.append(
                    this.fieldGroup(
                        this.tLabel('aggregates'),
                        $aggRows,
                        this.tMessage('mapAggregatesHint')
                    )
                );
                $group.append(
                    $('<button type="button" class="btn btn-default btn-xs">')
                        .attr(Object.assign({'data-action': 'addAggregateRow', 'data-index': index}, stageAttr))
                        .html('<span class="fas fa-plus"></span> ' + this.getHelper().escapeString(this.tLabel('addAggregate')))
                );
                $body.append($group);

                // where (+ optional whereFormulas per field)
                const whereInfo = this.inspectWhere(step.where);
                const whereFormulas = (step.whereFormulas && typeof step.whereFormulas === 'object')
                    ? step.whereFormulas
                    : {};
                const $whereBlock = $('<div class="map-f-where automation-subblock">');
                $whereBlock.append($('<div class="automation-subblock-title">').text(this.tLabel('filters')));
                if (whereInfo.advanced) {
                    $whereBlock.append(
                        $('<div class="alert alert-warning automation-map-where-advanced-warn">')
                            .text(this.tMessage('mapWhereAdvancedLoaded'))
                    );
                }
                const $whereRows = $('<div class="def-map-where-rows def-map-where-rows-' + index + '">');
                const whereKeys = Object.keys(whereInfo.simple || {});
                const formulaKeys = Object.keys(whereFormulas).filter((k) => whereKeys.indexOf(k) === -1);
                const allKeys = whereKeys.concat(formulaKeys);
                if (allKeys.length) {
                    allKeys.forEach((k) => {
                        const formula = whereFormulas[k] || '';
                        const fixed = Object.prototype.hasOwnProperty.call(whereInfo.simple || {}, k)
                            ? whereInfo.simple[k]
                            : '';
                        const $row = this.buildKvRow(k, fixed, formula, prefix);
                        $whereRows.append($row);
                        this.mountWhereValueExpression($row, prefix, fixed, formula);
                    });
                } else if (!whereInfo.advanced) {
                    const $row = this.buildKvRow('', '', '', prefix);
                    $whereRows.append($row);
                    this.mountWhereValueExpression($row, prefix, '', '');
                }
                if (!whereInfo.advanced) {
                    $whereBlock.append($whereRows);
                    $whereBlock.append(
                        $('<button type="button" class="btn btn-default btn-xs margin-top-sm">')
                            .attr(Object.assign({'data-action': 'addWhereRow', 'data-index': index}, stageAttr))
                            .html('<span class="fas fa-plus"></span> ' + this.getHelper().escapeString(this.tLabel('addFilter')))
                    );
                    $whereBlock.append(
                        $('<p class="text-muted small automation-field-hint">').text(this.tMessage('mapWhereHint'))
                    );
                }
                $body.append($whereBlock);

                // advanced JSON leftover (rarely needed)
                const advancedOpen = !!whereInfo.advanced;
                $body.append(
                    $('<div class="automation-advanced-toggle">').append(
                        $('<a role="button" tabindex="0" data-action="toggleAdvanced" data-target=".automation-map-advanced">')
                            .attr('aria-expanded', advancedOpen ? 'true' : 'false')
                            .append(
                                $('<span class="automation-advanced-label">').text(
                                    advancedOpen ? this.tLabel('hideAdvanced') : this.tLabel('showAdvanced')
                                )
                            )
                    )
                );
                $body.append(
                    $('<div class="automation-advanced automation-map-advanced' + (advancedOpen ? '' : ' hidden') + '">').append(
                        this.fieldGroup(
                            this.tLabel('whereAdvancedJson'),
                            $('<textarea class="form-control def-map-where-json" rows="3" style="font-family:monospace;font-size:12px">')
                                .val(whereInfo.advancedText || ''),
                            this.tMessage('whereAdvancedHint')
                        )
                    )
                );

                $card.append($body);
                $c.append($card);
                this.applyMapStepVisibility($card);
            });
        },

        mapStepTitle: function (step, index) {
            const id = (step && step.id) || ('s' + (index + 1));
            const mode = (step && step.mode) || 'primary';
            const source = (step && step.source) || 'query';
            const et = (step && step.entityType) || '';

            return (index + 1) + '. ' + id + ' · ' + mode + ' · ' + source + (et ? ' · ' + et : '');
        },

        updateMapStepTitle: function ($card) {
            if (!$card || !$card.length) {
                return;
            }
            const index = parseInt($card.attr('data-index'), 10) || 0;
            const step = {
                id: $card.find('.def-map-id').val(),
                mode: $card.find('.def-map-mode').val(),
                source: $card.find('.def-map-source').val(),
                entityType: $card.find('.def-map-et').val(),
            };
            $card.find('.def-map-title').text(this.mapStepTitle(step, index));
        },

        applyMapStepVisibility: function ($card) {
            const source = $card.find('.def-map-source').val() || 'query';
            const mode = $card.find('.def-map-mode').val() || 'primary';
            const entityType = $card.find('.def-map-et').val() || '';
            const hasParent = !!($card.find('.def-map-parent').val() || '');

            $card.find('.map-f-entity').toggle(source !== 'payload' || mode === 'groupBy');
            $card.find('.map-f-parent').toggle(source === 'relation' || source === 'linkMultiple' || source === 'query');
            $card.find('.map-f-relation').toggle(source === 'relation');
            $card.find('.map-f-link').toggle(source === 'linkMultiple');
            $card.find('.map-f-payload').toggle(source === 'payload');
            $card.find('.map-f-idspath').toggle(source === 'ids');
            $card.find('.map-f-ids').toggle(source === 'ids');
            $card.find('.map-f-report').toggle(source === 'report');
            $card.find('.map-f-maxrows').toggle(source === 'report');
            $card.find('.map-f-groupby').toggle(mode === 'groupBy');
            $card.find('.map-f-where').toggle(['query', 'relation', 'linkMultiple', 'report'].indexOf(source) !== -1);
            // requireRoles is a User-only post-filter (see AutomationDefinitionValidator).
            $card.find('.map-f-requireroles').toggle(entityType === 'User');
            // foreignKey / ignoreParent only affect parent-scoped query steps.
            $card.find('.map-f-fk').toggle(source === 'query' && hasParent);
            $card.find('.map-f-ignoreparent').toggle(source === 'query' && hasParent);
        },

        refreshParentSelects: function () {
            const ids = [];
            this.$el.find('.map-step').each((i, el) => {
                const id = $(el).find('.def-map-id').val() || ('s' + (i + 1));
                const et = $(el).find('.def-map-et').val() || '';
                ids.push({value: id, label: id + (et ? ' · ' + et : '')});
            });
            this.$el.find('.map-step').each((i, el) => {
                const $sel = $(el).find('.def-map-parent');
                const cur = $sel.val();
                const selfId = $(el).find('.def-map-id').val();
                $sel.empty().append($('<option>').val('').text('—'));
                ids.forEach((o) => {
                    if (o.value === selfId) {
                        return;
                    }
                    $sel.append($('<option>').val(o.value).text(o.label));
                });
                if (cur) {
                    $sel.val(cur);
                }
            });
        },

        buildKvRow: function (key, value, formula, prefix) {
            let display = value;
            if (typeof value === 'boolean') {
                display = value ? 'true' : 'false';
            } else if (value === null || value === undefined) {
                display = '';
            } else if (typeof value === 'object') {
                display = JSON.stringify(value);
            }

            const rowUid = 'wr-' + Math.floor(Math.random() * 1e9);
            const $row = $('<div class="automation-kv-row row">')
                .attr('data-row-uid', rowUid)
                .attr('data-view-prefix', prefix || '');

            $row.append(
                $('<div class="col-sm-4">').append(
                    $('<input type="text" class="form-control input-sm kv-key">')
                        .val(key || '')
                        .attr('placeholder', this.tLabel('fieldName'))
                ),
                $('<div class="col-sm-6">').append(
                    $('<div class="journey-expression-host automation-expression-host kv-value-host">')
                ),
                $('<div class="col-sm-2">').append(
                    $('<button type="button" class="btn btn-link btn-sm text-danger">')
                        .attr('data-action', 'removeKvRow')
                        .html('<span class="fas fa-times"></span>')
                )
            );

            // Store defaults for subsequent mount (caller may pass fixed/formula).
            $row.data('kvFixed', display === undefined || display === null ? '' : String(display));
            $row.data('kvFormula', formula || '');

            return $row;
        },

        mountWhereValueExpression: function ($row, prefix, fixed, formula) {
            if (!$row || !$row.length) {
                return;
            }
            const rowUid = $row.attr('data-row-uid');
            if (!rowUid) {
                return;
            }
            const exprPrefix = prefix || $row.attr('data-view-prefix') || 'map';
            const fixedVal = fixed !== undefined && fixed !== null
                ? (typeof fixed === 'boolean'
                    ? (fixed ? 'true' : 'false')
                    : (typeof fixed === 'object' ? JSON.stringify(fixed) : String(fixed)))
                : ($row.data('kvFixed') || '');
            const formulaVal = formula || $row.data('kvFormula') || '';
            const $host = $row.find('.kv-value-host').empty();
            const snippets = this.buildAutomationSnippets();
            this.mountExpression(exprPrefix, 'whereVal-' + rowUid, $host, {
                multiline: false,
                placeholder: this.tLabel('fieldValue'),
                expressionPlaceholder: this.tMessage('mapWhereValueExpressionPlaceholder'),
                fixedValue: fixedVal,
                expressionValue: formulaVal,
                mode: formulaVal ? 'expression' : 'fixed',
                snippets: snippets,
            });
        },

        buildAggregateRow: function (agg) {
            return $('<div class="automation-kv-row row">').append(
                $('<div class="col-sm-3">').append(
                    this.buildSelect(
                        'form-control input-sm agg-op',
                        [
                            {value: 'count', label: 'count'},
                            {value: 'sum', label: 'sum'},
                            {value: 'avg', label: 'avg'},
                            {value: 'min', label: 'min'},
                            {value: 'max', label: 'max'},
                            {value: 'collectIds', label: 'collectIds'},
                        ],
                        agg.op || 'count'
                    )
                ),
                $('<div class="col-sm-4">').append(
                    $('<input type="text" class="form-control input-sm agg-field">')
                        .val(agg.field || '')
                        .attr('placeholder', this.tLabel('aggField'))
                ),
                $('<div class="col-sm-3">').append(
                    $('<input type="text" class="form-control input-sm agg-as">')
                        .val(agg.as || '')
                        .attr('placeholder', this.tLabel('aggAs'))
                ),
                $('<div class="col-sm-2">').append(
                    $('<button type="button" class="btn btn-link btn-sm text-danger">')
                        .attr('data-action', 'removeKvRow')
                        .html('<span class="fas fa-times"></span>')
                )
            );
        },

        formatIdsList: function (ids) {
            if (!ids) {
                return '';
            }
            if (Array.isArray(ids)) {
                return ids.join('\n');
            }
            if (typeof ids === 'string') {
                return ids;
            }

            return '';
        },

        parseIdsList: function (text) {
            const t = (text || '').trim();
            if (!t) {
                return [];
            }
            if (t.charAt(0) === '[') {
                try {
                    const parsed = JSON.parse(t);

                    return Array.isArray(parsed) ? parsed.map(String) : [];
                } catch (e) {
                    // fall through
                }
            }

            return t.split(/[\n,]+/).map((s) => s.trim()).filter(Boolean);
        },

        formatStringList: function (list) {
            if (!list) {
                return '';
            }
            if (Array.isArray(list)) {
                return list.join(', ');
            }
            if (typeof list === 'string') {
                return list;
            }

            return '';
        },

        parseStringList: function (text) {
            const t = (text || '').trim();
            if (!t) {
                return [];
            }
            if (t.charAt(0) === '[') {
                try {
                    const parsed = JSON.parse(t);

                    return Array.isArray(parsed) ? parsed.map(String).map((s) => s.trim()).filter(Boolean) : [];
                } catch (e) {
                    // fall through
                }
            }

            return t.split(/[\n,]+/).map((s) => s.trim()).filter(Boolean);
        },

        normalizeWhereObject: function (where) {
            return this.inspectWhere(where).simple;
        },
        inspectWhere: function (where) {
            if (!where) {
                return {simple: {}, advanced: false, advancedText: ''};
            }

            let value = where;
            if (typeof value === 'string') {
                try {
                    value = JSON.parse(value);
                } catch (e) {
                    return {
                        simple: {},
                        advanced: true,
                        advancedText: String(where),
                    };
                }
            }

            if (Array.isArray(value)) {
                return {
                    simple: {},
                    advanced: true,
                    advancedText: this.formatJson(value),
                };
            }

            if (value && typeof value === 'object') {
                const simple = Object.assign({}, value);
                const complex = Object.keys(simple).some((key) => {
                    const item = simple[key];

                    return item !== null && typeof item === 'object';
                });

                if (complex) {
                    return {
                        simple: {},
                        advanced: true,
                        advancedText: this.formatJson(simple),
                    };
                }

                return {simple: simple, advanced: false, advancedText: ''};
            }

            return {simple: {}, advanced: false, advancedText: ''};
        },

        readWhereFromCard: function ($card) {
            const advanced = ($card.find('.def-map-where-json').val() || '').trim();
            if (advanced) {
                return {
                    where: this.parseJsonField(advanced, {}),
                    whereFormulas: {},
                };
            }
            const out = {};
            const outFormulas = {};
            const prefix = $card.attr('data-view-prefix') || '';
            const exprMap = this._expressionInputs || {};

            $card.find('.def-map-where-rows .automation-kv-row').each((_, el) => {
                const $row = $(el);
                const k = ($row.find('.kv-key').val() || '').trim();
                if (!k) {
                    return;
                }
                const rowUid = $row.attr('data-row-uid') || '';
                const ex = prefix && rowUid ? exprMap[prefix + '::whereVal-' + rowUid] : null;

                if (ex) {
                    const state = ex.getState();
                    if (state.mode === 'expression') {
                        if (state.expression && String(state.expression).trim() !== '') {
                            outFormulas[k] = String(state.expression).trim();
                        }

                        return;
                    }
                    if (state.fixed !== null && state.fixed !== undefined && String(state.fixed).trim() !== '') {
                        out[k] = this.coerceScalar(state.fixed);
                    }

                    return;
                }

                // Fallback plain .kv-value (legacy DOM)
                const $legacy = $row.find('.kv-value');
                if ($legacy.length) {
                    out[k] = this.coerceScalar($legacy.val());
                }
            });

            return {where: out, whereFormulas: outFormulas};
        },

        readAggregatesFromCard: function ($card) {
            const out = [];
            $card.find('.def-map-agg-rows .automation-kv-row').each((_, el) => {
                const op = $(el).find('.agg-op').val() || 'count';
                const field = ($(el).find('.agg-field').val() || '').trim();
                const as = ($(el).find('.agg-as').val() || '').trim();
                if (!as && op === 'count' && !field) {
                    // skip empty placeholder
                    if (!$(el).find('.agg-as').val() && !field) {
                        // still allow explicit empty rows to skip
                    }
                }
                if (!as && !field && op === 'count') {
                    return;
                }
                const row = {op: op};
                if (field) row.field = field;
                if (as) row.as = as;
                else row.as = op === 'count' ? 'count' : op;
                out.push(row);
            });

            return out.length ? out : null;
        },

        coerceScalar: function (raw) {
            const s = String(raw ?? '').trim();
            if (s === '') {
                return '';
            }
            if (s === 'true') {
                return true;
            }
            if (s === 'false') {
                return false;
            }
            if (s === 'null') {
                return null;
            }
            if ((s.charAt(0) === '{' || s.charAt(0) === '[') && s.length > 1) {
                try {
                    return JSON.parse(s);
                } catch (e) {
                    // keep string
                }
            }
            if (s !== '' && !isNaN(Number(s)) && /^-?\d+(\.\d+)?$/.test(s)) {
                return Number(s);
            }

            return s;
        },

        // ─── Actions ─────────────────────────────────────────────────

        renderActionList: function (bucket, $container, list, nestedMeta) {
            const $c = $container || this.$el.find('.action-list[data-list="' + bucket + '"]');
            $c.empty();
            const items = list || ((this._def && this._def[bucket]) || []);
            const types = this.actionTypeOptions();

            items.forEach((action, index) => {
                const $card = $('<div class="automation-card panel panel-default">')
                    .attr('data-action-card', '1')
                    .attr('data-bucket', bucket)
                    .attr('data-index', index);

                if (nestedMeta) {
                    $card.addClass('automation-nested-card');
                    if (nestedMeta.parent === 'stage') {
                        $card.attr('data-parent', 'stage');
                        $card.attr('data-parent-index', nestedMeta.stageIndex);
                    } else {
                        $card.attr('data-parent', 'state');
                        $card.attr('data-parent-index', nestedMeta.stateIndex);
                    }
                    $card.attr('data-nested-bucket', nestedMeta.bucket);
                }

                const typeOpts = types.map((t) => ({value: t, label: this.actionTypeLabel(t)}));

                $card.append(
                    $('<div class="automation-card-header">').append(
                        $('<div class="automation-card-title def-action-title">')
                            .text(this.actionTitle(action, index)),
                        $('<button type="button" class="btn btn-link btn-sm text-danger">')
                            .attr(Object.assign({
                                'data-action': 'removeRow',
                                'data-bucket': nestedMeta ? nestedMeta.bucket : bucket,
                                'data-index': index,
                            }, nestedMeta ? (
                                nestedMeta.parent === 'stage' ? {
                                    'data-parent': 'stage',
                                    'data-parent-index': nestedMeta.stageIndex,
                                } : {
                                    'data-parent': 'state',
                                    'data-parent-index': nestedMeta.stateIndex,
                                }
                            ) : {}))
                            .text(this.tLabel('remove'))
                    )
                );

                const $body = $('<div class="automation-card-body">');
                $body.append(
                    this.fieldGroup(
                        this.tLabel('type'),
                        this.buildSelect('form-control def-action-type', typeOpts, action.type || 'notifyUser')
                    )
                );

                const $paramsHost = $('<div class="def-action-params automation-params-host">');
                $body.append($paramsHost);

                $body.append(
                    $('<div class="automation-advanced-toggle">').append(
                        $('<a role="button" tabindex="0" data-action="toggleAdvanced" data-target=".automation-action-advanced">')
                            .attr('aria-expanded', 'false')
                            .append($('<span class="automation-advanced-label">').text(this.tLabel('showAdvanced')))
                    )
                );

                const $adv = $('<div class="automation-advanced automation-action-advanced hidden">');
                const $whenHost = $('<div class="journey-expression-host automation-expression-host def-action-when-host">');
                $adv.append(
                    this.fieldGroup(
                        this.tLabel('whenFormula'),
                        $whenHost,
                        this.tMessage('whenFormulaHint')
                    )
                );
                $adv.append(
                    $('<div class="row">').append(
                        $('<div class="col-sm-6">').append(
                            this.fieldGroup(
                                this.tLabel('idempotencyKey'),
                                $('<input type="text" class="form-control def-action-idemp">')
                                    .val(
                                        action.idempotencyKey === true ? 'true' :
                                            (action.idempotencyKey === false || action.idempotencyKey == null
                                                ? '' : String(action.idempotencyKey))
                                    )
                                    .attr('placeholder', 'true  |  digest|{targetId}|{type}'),
                                this.tMessage('idempotencyHint')
                            )
                        ),
                        $('<div class="col-sm-6">').append(
                            this.fieldGroup(
                                this.tLabel('debounce'),
                                $('<input type="text" class="form-control def-action-debounce">')
                                    .val(action.debounce || '')
                                    .attr('placeholder', '20 hours'),
                                this.tMessage('debounceHint')
                            )
                        )
                    )
                );
                $adv.append(
                    this.fieldGroup(
                        this.tLabel('paramsAdvancedJson'),
                        $('<textarea class="form-control def-action-params-json" rows="3" style="font-family:monospace;font-size:12px">')
                            .val(''),
                        this.tMessage('paramsAdvancedHint')
                    )
                );
                $body.append($adv);
                $card.append($body);
                $c.append($card);

                this.mountActionParams(
                    $card,
                    action.type || 'notifyUser',
                    action.params || {},
                    action.paramFormulas || {}
                );

                const actPrefix = $card.attr('data-view-prefix');
                const whenFormula = (action.when && String(action.when).trim()) || '';
                this.mountExpression(actPrefix, '__when', $whenHost, {
                    multiline: true,
                    rows: 2,
                    placeholder: "entity\\attribute('status') == 'Approved'",
                    expressionPlaceholder: this.tMessage('whenExpressionPlaceholder'),
                    fixedValue: whenFormula,
                    expressionValue: whenFormula,
                    mode: whenFormula ? 'expression' : 'fixed',
                    snippets: this.buildAutomationSnippets(),
                });
            });
        },

        actionTitle: function (action, index) {
            const type = (action && action.type) || 'notifyUser';

            return (index + 1) + '. ' + this.actionTypeLabel(type);
        },

        updateActionTitle: function ($card) {
            const index = parseInt($card.attr('data-index'), 10) || 0;
            const type = $card.find('.def-action-type').val() || 'notifyUser';
            $card.find('.def-action-title').text(this.actionTitle({type: type}, index));
        },

        mountActionParams: function ($card, type, params, paramFormulas) {
            const $host = $card.find('.def-action-params').first();
            $host.empty();

            // Tear down previous views scoped to this card via unique prefix stored on card
            const prevPrefix = $card.attr('data-view-prefix');
            if (prevPrefix) {
                this._paramViewNames = (this._paramViewNames || []).filter((name) => {
                    if (name.indexOf(prevPrefix) === 0) {
                        this.clearView(name);

                        return false;
                    }

                    return true;
                });
                delete this._helperModels[prevPrefix];
                this.clearExpressionInputs(prevPrefix);
            }

            const prefix = 'ap-' + Math.floor(Math.random() * 1e9);
            $card.attr('data-view-prefix', prefix);

            const defs = this.getActionParamDefs(type);
            const formulas = paramFormulas && typeof paramFormulas === 'object'
                ? paramFormulas
                : {};

            if (!defs.length) {
                $host.append(
                    $('<p class="text-muted small">').text(this.tMessage('noParamDefs'))
                );
                $host.append(
                    $('<textarea class="form-control def-action-params-fallback" rows="3" style="font-family:monospace;font-size:12px">')
                        .val(this.formatJson(params || {}))
                );

                return;
            }

            const helper = new Model();
            helper.name = 'AutomationActionParams';
            helper.urlRoot = null;
            helper.set(params || {});
            this._helperModels[prefix] = helper;

            const snippets = this.buildAutomationSnippets();

            defs.forEach((def) => {
                this.renderParamDef($host, def, params || {}, formulas, helper, prefix, snippets);
            });
        },

        clearExpressionInputs: function (prefix) {
            const map = this._expressionInputs || {};
            Object.keys(map).forEach((key) => {
                if (key.indexOf(prefix + '::') === 0) {
                    delete map[key];
                }
            });
            this._expressionInputs = map;
        },

        buildAutomationSnippets: function () {
            const entityType = this.model.get('subjectEntityType') || null;
            const base = ExpressionInput.defaultSnippets(this, entityType) || [];
            const t = (k) => this.tLabel(k);
            const tm = (k) => this.tMessage(k);

            const automationVars = {
                kind: 'variable',
                label: t('snippetGroupAutomation'),
                items: [
                    {
                        label: t('snippetPayload'),
                        chip: 'payload',
                        insert: '$payload',
                        tone: 'purple',
                        description: tm('docVarPayload'),
                    },
                    {
                        label: t('snippetTenantId'),
                        chip: 'tenantId',
                        insert: '$tenantId',
                        tone: 'purple',
                        description: tm('docVarTenantId'),
                    },
                    {
                        label: t('snippetAutomationId'),
                        chip: 'automation',
                        insert: '$automationId',
                        tone: 'purple',
                        description: tm('docVarAutomationId'),
                    },
                    {
                        label: t('snippetRunItemId'),
                        chip: 'runItem',
                        insert: '$runItemId',
                        tone: 'purple',
                        description: tm('docVarRunItemId'),
                    },
                    {
                        label: t('snippetPayloadReport'),
                        chip: 'report',
                        insert: "object\\get($payload, 'report')",
                        tone: 'purple',
                        description: tm('docVarPayloadReport'),
                    },
                    {
                        label: t('snippetPayloadReportCount'),
                        chip: 'count',
                        insert: "object\\get(object\\get(object\\get($payload, 'report'), 'totals'), 'count')",
                        tone: 'purple',
                        description: tm('docVarPayloadReportCount'),
                    },
                    {
                        label: t('snippetPayloadVars'),
                        chip: 'vars',
                        insert: "object\\get($payload, 'vars')",
                        tone: 'purple',
                        description: tm('docVarPayloadVars'),
                    },
                ],
            };

            // Replace Journey variable group when present; else prepend automation vars.
            const out = [];
            let replaced = false;
            base.forEach((group) => {
                if (group && group.kind === 'variable') {
                    out.push(automationVars);
                    replaced = true;
                } else {
                    out.push(group);
                }
            });
            if (!replaced) {
                out.unshift(automationVars);
            }

            return out;
        },

        renderParamDef: function ($host, def, params, formulas, helper, prefix, snippets) {
            const name = def.name;
            const $group = $('<div class="form-group automation-param">').attr('data-param', name);
            const $label = $('<label class="control-label small">').text(def.label || name);
            if (def.required) {
                $label.append(' <span class="text-danger">*</span>');
            }
            $group.append($label);

            if (def.hint || def.tooltip) {
                $group.append(
                    $('<p class="text-muted small automation-field-hint">').text(def.hint || def.tooltip)
                );
            }

            const supportsExpr = ExpressionInput.supportsType(def.type);

            if (def.type === 'bool') {
                if (helper.get(name) === undefined && def.default !== undefined) {
                    helper.set(name, def.default);
                }
                const $field = $('<div class="field">').attr('data-name', name);
                $group.append($field);
                $host.append($group);
                this.createParamView(prefix, name, 'views/fields/bool', {
                    model: helper,
                    name: name,
                    el: this.getSelector() + ' [data-view-prefix="' + prefix + '"] [data-param="' + name + '"] .field',
                    mode: 'edit',
                    defs: {name: name, type: 'bool'},
                });

                return;
            }

            if (def.type === 'enum') {
                if (helper.get(name) === undefined && def.default !== undefined) {
                    helper.set(name, def.default);
                }
                const $field = $('<div class="field">').attr('data-name', name);
                $group.append($field);
                $host.append($group);
                this.createParamView(prefix, name, 'views/fields/enum', {
                    model: helper,
                    name: name,
                    el: this.getSelector() + ' [data-view-prefix="' + prefix + '"] [data-param="' + name + '"] .field',
                    mode: 'edit',
                    defs: {name: name, type: 'enum'},
                    params: {options: def.options || []},
                });

                return;
            }

            if (def.type === 'link') {
                const base = name.replace(/Id$/, '');
                const nameKey = def.nameKey || (base + 'Name');
                const hasFx = !!(formulas && formulas[name]);

                helper.set(base + 'Id', hasFx ? null : (params[name] || null));
                helper.set(base + 'Name', hasFx ? null : (params[nameKey] || null));

                const $field = $('<div class="field">').attr('data-name', base);
                $group.append($field);

                // Optional fx path under the picker for dynamic report/inbox IDs.
                const $exHost = $('<div class="journey-expression-host automation-expression-host" style="margin-top:6px">');
                $group.append($exHost);
                $group.append(
                    $('<p class="text-muted small automation-field-hint">').text(this.tMessage('linkExpressionHint'))
                );
                $host.append($group);

                const linkParams = {};
                if (def.primaryFilter) {
                    linkParams.primaryFilter = def.primaryFilter;
                }
                this.createParamView(prefix, name, 'views/fields/link', {
                    model: helper,
                    name: base,
                    foreignScope: def.entity || 'User',
                    el: this.getSelector() + ' [data-view-prefix="' + prefix + '"] [data-param="' + name + '"] .field',
                    mode: 'edit',
                    defs: {name: base, type: 'link'},
                    params: linkParams,
                });

                this.mountExpression(prefix, name, $exHost, {
                    multiline: false,
                    placeholder: def.placeholder || 'entity\\attribute(\'id\')',
                    fixedValue: '',
                    expressionValue: formulas[name] || '',
                    mode: hasFx ? 'expression' : 'fixed',
                    snippets: snippets,
                });

                // Hide fixed row of expression (links use real picker); show only when fx on.
                // Users toggle fx → use formula instead of picker.
                const ex = this._expressionInputs[prefix + '::' + name];
                if (ex && ex.$root) {
                    const syncLinkMode = () => {
                        const fxOn = ex.getMode() === 'expression';
                        $field.toggleClass('hidden', fxOn);
                        $field.closest('.field').toggleClass('hidden', fxOn);
                        $group.find('.journey-expression-fixed-row').addClass('hidden');
                        if (!fxOn) {
                            // Keep fx button available when staying in fixed mode.
                            $group.find('.journey-expression-fx').first().removeClass('hidden');
                        }
                    };
                    // Always hide the expression fixed input — picker is the fixed mode.
                    $group.find('.journey-expression-fixed-row').addClass('hidden');
                    if (hasFx) {
                        $field.addClass('hidden');
                    }
                    // Re-bind change
                    const prevOnChange = ex.onChange;
                    ex.onChange = () => {
                        syncLinkMode();
                        if (typeof prevOnChange === 'function') {
                            prevOnChange();
                        }
                    };
                }

                $group.attr('data-link-id-key', name);
                $group.attr('data-link-name-key', nameKey);
                $group.attr('data-link-base', base);

                return;
            }

            if (
                supportsExpr &&
                (def.type === 'text' || def.type === 'json' || def.type === 'varchar' || !def.type)
            ) {
                let fixed = params[name];
                if (def.type === 'json' && fixed && typeof fixed === 'object') {
                    fixed = JSON.stringify(fixed, null, 2);
                }
                if (fixed === undefined || fixed === null) {
                    fixed = def.default !== undefined ? def.default : '';
                }
                const $exHost = $('<div class="journey-expression-host automation-expression-host">');
                $group.append($exHost);
                $host.append($group);
                this.mountExpression(prefix, name, $exHost, {
                    multiline: def.type === 'text' || def.type === 'json',
                    rows: def.type === 'json' ? 4 : 3,
                    placeholder: def.placeholder || '',
                    fixedValue: fixed === false || fixed === true ? String(fixed) : fixed,
                    expressionValue: formulas[name] || '',
                    mode: formulas[name] ? 'expression' : 'fixed',
                    snippets: snippets,
                });
                if (def.type === 'json') {
                    $group.append(
                        $('<p class="text-muted small automation-field-hint">').text(this.tMessage('jsonParamHint'))
                    );
                }

                return;
            }

            if (def.type === 'text' || def.type === 'json') {
                let val = params[name];
                if (def.type === 'json' && val && typeof val === 'object') {
                    val = JSON.stringify(val, null, 2);
                }
                if (val === undefined || val === null) {
                    val = def.default !== undefined ? def.default : '';
                }
                $group.append(
                    $('<textarea class="form-control def-param-input">')
                        .attr({'data-name': name, 'data-type': def.type, rows: def.type === 'json' ? 4 : 3})
                        .val(val === false || val === true ? String(val) : val)
                        .css(def.type === 'json' ? {'font-family': 'monospace', 'font-size': '12px'} : {})
                );
                if (def.type === 'json') {
                    $group.append(
                        $('<p class="text-muted small automation-field-hint">').text(this.tMessage('jsonParamHint'))
                    );
                }
                $host.append($group);

                return;
            }

            // varchar default
            let v = params[name];
            if (v === undefined || v === null) {
                v = def.default !== undefined ? def.default : '';
            }
            $group.append(
                $('<input type="text" class="form-control def-param-input">')
                    .attr({'data-name': name, 'data-type': def.type || 'varchar'})
                    .val(v)
                    .attr('placeholder', def.placeholder || '')
            );
            $host.append($group);
        },

        mountExpression: function (prefix, key, $host, options) {
            const ex = new ExpressionInput(
                this,
                Object.assign(
                    {
                        paramKey: key,
                        snippets: options.snippets || [],
                        onChange: () => {
                            this.trigger('change');
                        },
                    },
                    options || {}
                )
            );
            ex.mount($host);
            this._expressionInputs = this._expressionInputs || {};
            this._expressionInputs[prefix + '::' + key] = ex;

            return ex;
        },

        /**
         * Read ExpressionInput value: prefer expression mode formula, else fixed text.
         * Used for action/transition "when" gates (always formulas / empty).
         */
        readFormulaField: function (prefix, key) {
            if (!prefix) {
                return '';
            }
            const ex = (this._expressionInputs || {})[prefix + '::' + key];
            if (!ex || typeof ex.getState !== 'function') {
                return '';
            }
            const state = ex.getState();
            if (state.mode === 'expression') {
                return (state.expression || '').trim();
            }

            return (state.fixed || '').trim();
        },

        createParamView: function (prefix, name, viewClass, options) {
            const viewName = prefix + '-' + name;
            this._paramViewNames.push(viewName);
            this.createView(viewName, viewClass, options, (view) => {
                view.render();
            });
        },

        readActionParamsFromCard: function ($card) {
            const advanced = ($card.find('.def-action-params-json').val() || '').trim();
            if (advanced) {
                const parsed = this.parseJsonField(advanced, {});
                const formulas = parsed.paramFormulas && typeof parsed.paramFormulas === 'object'
                    ? parsed.paramFormulas
                    : {};
                delete parsed.paramFormulas;

                return {params: parsed, paramFormulas: formulas};
            }

            const $fallback = $card.find('.def-action-params-fallback');
            if ($fallback.length) {
                return {
                    params: this.parseJsonField($fallback.val(), {}),
                    paramFormulas: {},
                };
            }

            const type = $card.find('.def-action-type').val() || 'notifyUser';
            const defs = this.getActionParamDefs(type);
            const prefix = $card.attr('data-view-prefix');
            const helper = prefix ? this._helperModels[prefix] : null;
            const out = {};
            const outFormulas = {};
            const exprMap = this._expressionInputs || {};

            defs.forEach((def) => {
                const name = def.name;
                const ex = prefix ? exprMap[prefix + '::' + name] : null;

                if (ex) {
                    const state = ex.getState();
                    if (state.mode === 'expression') {
                        if (state.expression) {
                            outFormulas[name] = state.expression;
                        }

                        return;
                    }
                    // fixed via expression input (varchar/text/json)
                    if (def.type !== 'link' && state.fixed !== '') {
                        if (def.type === 'json') {
                            try {
                                out[name] = JSON.parse(state.fixed);
                            } catch (e) {
                                out[name] = state.fixed;
                            }
                        } else {
                            out[name] = state.fixed;
                        }

                        return;
                    }
                    // link in expression fixed mode falls through to link view
                }

                if (def.type === 'link') {
                    const base = name.replace(/Id$/, '');
                    const nameKey = def.nameKey || (base + 'Name');
                    const view = prefix ? this.getView(prefix + '-' + name) : null;
                    if (view && typeof view.fetch === 'function') {
                        const fetched = view.fetch() || {};
                        if (fetched[base + 'Id']) {
                            out[name] = fetched[base + 'Id'];
                            if (nameKey && fetched[base + 'Name']) {
                                out[nameKey] = fetched[base + 'Name'];
                            }
                        }
                    } else if (helper) {
                        const id = helper.get(base + 'Id');
                        if (id) {
                            out[name] = id;
                            const nm = helper.get(base + 'Name');
                            if (nameKey && nm) {
                                out[nameKey] = nm;
                            }
                        }
                    }

                    return;
                }

                if (def.type === 'bool' || def.type === 'enum') {
                    const view = prefix ? this.getView(prefix + '-' + name) : null;
                    if (view && typeof view.fetch === 'function') {
                        const fetched = view.fetch();
                        if (Object.prototype.hasOwnProperty.call(fetched, name)) {
                            if (def.type === 'bool') {
                                out[name] = !!fetched[name];
                            } else if (fetched[name] !== null && fetched[name] !== undefined && fetched[name] !== '') {
                                out[name] = fetched[name];
                            }
                        }
                    } else if (helper) {
                        const val = helper.get(name);
                        if (def.type === 'bool') {
                            out[name] = !!val;
                        } else if (val !== null && val !== undefined && val !== '') {
                            out[name] = val;
                        }
                    }

                    return;
                }

                const $input = $card.find('.def-param-input[data-name="' + name + '"]');
                if (!$input.length) {
                    return;
                }
                const raw = $input.val();
                if (raw === null || raw === undefined || String(raw).trim() === '') {
                    return;
                }
                if (def.type === 'json') {
                    try {
                        out[name] = JSON.parse(String(raw));
                    } catch (e) {
                        out[name] = raw;
                    }
                } else {
                    out[name] = raw;
                }
            });

            return {params: out, paramFormulas: outFormulas};
        },

        readActionsFromContainer: function ($c) {
            const out = [];
            $c.children('[data-action-card]').each((_, el) => {
                const $card = $(el);
                let params = {};
                let paramFormulas = {};
                try {
                    const read = this.readActionParamsFromCard($card);
                    params = read.params || {};
                    paramFormulas = read.paramFormulas || {};
                } catch (e) {
                    this.showError(e.message);
                    params = {};
                    paramFormulas = {};
                }
                const a = {
                    type: $card.find('.def-action-type').val() || 'notifyUser',
                    params: params,
                };
                if (paramFormulas && Object.keys(paramFormulas).length) {
                    a.paramFormulas = paramFormulas;
                }
                const actPrefix = $card.attr('data-view-prefix');
                let when = this.readFormulaField(actPrefix, '__when');
                if (!when) {
                    when = ($card.find('.def-action-when').val() || '').trim();
                }
                if (when) a.when = when;
                const idemp = ($card.find('.def-action-idemp').val() || '').trim();
                if (idemp === 'true') {
                    a.idempotencyKey = true;
                } else if (idemp) {
                    a.idempotencyKey = idemp;
                }
                const debounce = ($card.find('.def-action-debounce').val() || '').trim();
                if (debounce) a.debounce = debounce;
                out.push(a);
            });

            return out;
        },

        // ─── States / transitions ────────────────────────────────────

        renderStates: function () {
            const $c = this.$el.find('.state-list').empty();
            const list = (this._def && this._def.states) || [];
            const types = [
                {value: 'normal', label: this.tLabel('stateNormal')},
                {value: 'wait', label: this.tLabel('stateWait')},
                {value: 'join', label: this.tLabel('stateJoin')},
                {value: 'final', label: this.tLabel('stateFinal')},
            ];

            list.forEach((state, index) => {
                const $card = $('<div class="automation-card state-card panel panel-default">')
                    .attr('data-index', index);

                $card.append(
                    $('<div class="automation-card-header">').append(
                        $('<div class="automation-card-title def-st-title">')
                            .text(this.stateCardTitle(state, index)),
                        $('<button type="button" class="btn btn-link btn-sm text-danger">')
                            .attr({
                                'data-action': 'removeRow',
                                'data-bucket': 'states',
                                'data-index': index,
                            })
                            .text(this.tLabel('remove'))
                    )
                );

                const $body = $('<div class="automation-card-body">');
                $body.append(
                    $('<div class="row">').append(
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('stateId'),
                                $('<input type="text" class="form-control def-st-id">')
                                    .val(state.id || '')
                                    .attr('placeholder', 'Start'),
                                this.tMessage('stateIdHint')
                            )
                        ),
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('stateType'),
                                this.buildSelect('form-control def-st-type', types, state.type || 'normal')
                            )
                        ),
                        $('<div class="col-sm-4 st-f-join-timeout">').append(
                            this.fieldGroup(
                                this.tLabel('timeoutPeriod'),
                                $('<input type="text" class="form-control def-st-timeout">')
                                    .val(state.timeoutPeriod || state.waitPeriod || '')
                                    .attr('placeholder', '2 hours')
                            )
                        )
                    )
                );

                const waitMode = state.waitUntil || state.waitUntilFormula ? 'until' : 'duration';
                $body.append(
                    $('<div class="row st-f-wait">').append(
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('waitMode'),
                                this.buildSelect(
                                    'form-control def-st-wait-mode',
                                    [
                                        {value: 'duration', label: this.tLabel('waitModeDuration')},
                                        {value: 'until', label: this.tLabel('waitModeUntil')},
                                    ],
                                    waitMode
                                ),
                                this.tMessage('stateWaitModeHint')
                            )
                        ),
                        $('<div class="col-sm-4 st-f-wait-duration">').append(
                            this.fieldGroup(
                                this.tLabel('waitPeriod'),
                                $('<input type="text" class="form-control def-st-wait">')
                                    .val(state.waitPeriod || '')
                                    .attr('placeholder', '1 day'),
                                this.tMessage('stateWaitHint')
                            )
                        ),
                        $('<div class="col-sm-4 st-f-wait-until">').append(
                            this.fieldGroup(
                                this.tLabel('waitUntil'),
                                $('<input type="text" class="form-control def-st-wait-until">')
                                    .val(state.waitUntil || '')
                                    .attr('placeholder', '2026-08-01 14:00:00'),
                                this.tMessage('stateWaitUntilHint')
                            )
                        ),
                        $('<div class="col-sm-4 st-f-wait-until">').append(
                            this.fieldGroup(
                                this.tLabel('waitUntilTimezone'),
                                $('<input type="text" class="form-control def-st-wait-tz">')
                                    .val(state.waitUntilTimezone || '')
                                    .attr('placeholder', 'America/Sao_Paulo'),
                                this.tMessage('stateWaitUntilTzHint')
                            )
                        ),
                        $('<div class="col-sm-12 st-f-wait-until">').append(
                            this.fieldGroup(
                                this.tLabel('waitUntilFormula'),
                                $('<input type="text" class="form-control def-st-wait-until-fx">')
                                    .val(state.waitUntilFormula || '')
                                    .attr('placeholder', "entity\\attribute('closeDate')"),
                                this.tMessage('stateWaitUntilFormulaHint')
                            )
                        )
                    )
                );

                $body.append(
                    $('<div class="automation-type-help def-st-type-help">')
                );

                $body.append(
                    $('<div class="st-f-join">').append(
                        this.fieldGroup(
                            this.tLabel('joinMode'),
                            this.buildSelect(
                                'form-control def-st-joinmode',
                                [
                                    {value: 'waitAll', label: this.tLabel('joinWaitAll')},
                                    {value: 'waitAny', label: this.tLabel('joinWaitAny')},
                                ],
                                state.joinMode || 'waitAll'
                            ),
                            this.tMessage('joinModeHint')
                        )
                    )
                );

                const $enterBlock = $('<div class="automation-subblock st-actions-enter">');
                $enterBlock.append(
                    $('<div class="automation-subblock-title">').text(this.tLabel('onEnter'))
                );
                $enterBlock.append(
                    $('<p class="text-muted small automation-field-hint">').text(this.tMessage('onEnterHint'))
                );
                const $enter = $('<div class="nested-action-list def-st-enter-list">');
                $enterBlock.append($enter);
                $enterBlock.append(
                    $('<button type="button" class="btn btn-default btn-sm">')
                        .attr({
                            'data-action': 'addNestedAction',
                            'data-state': index,
                            'data-bucket': 'onEnter',
                        })
                        .html('<span class="fas fa-plus"></span> ' +
                            this.getHelper().escapeString(this.tLabel('addOnEnterAction')))
                );
                $body.append($enterBlock);

                const $exitBlock = $('<div class="automation-subblock st-actions-exit">');
                $exitBlock.append(
                    $('<div class="automation-subblock-title">').text(this.tLabel('onExit'))
                );
                $exitBlock.append(
                    $('<p class="text-muted small automation-field-hint">').text(this.tMessage('onExitHint'))
                );
                const $exit = $('<div class="nested-action-list def-st-exit-list">');
                $exitBlock.append($exit);
                $exitBlock.append(
                    $('<button type="button" class="btn btn-default btn-sm">')
                        .attr({
                            'data-action': 'addNestedAction',
                            'data-state': index,
                            'data-bucket': 'onExit',
                        })
                        .html('<span class="fas fa-plus"></span> ' +
                            this.getHelper().escapeString(this.tLabel('addOnExitAction')))
                );
                $body.append($exitBlock);

                $card.append($body);
                $c.append($card);

                this.renderActionList('onEnter', $enter, state.onEnter || [], {stateIndex: index, bucket: 'onEnter'});
                this.renderActionList('onExit', $exit, state.onExit || [], {stateIndex: index, bucket: 'onExit'});
                this.applyStateVisibility($card);
                this.updateStateTypeHelp($card);
            });
        },

        stateCardTitle: function (state, index) {
            const id = (state && state.id) || ('S' + (index + 1));
            const type = (state && state.type) || 'normal';
            const typeLabel = this.stateTypeShortLabel(type);

            return (index + 1) + '. ' + id + ' · ' + typeLabel;
        },

        stateTypeShortLabel: function (type) {
            const map = {
                normal: this.tLabel('stateNormalShort'),
                wait: this.tLabel('stateWaitShort'),
                join: this.tLabel('stateJoinShort'),
                final: this.tLabel('stateFinalShort'),
            };

            return map[type] || type;
        },

        updateStateCardTitle: function ($card) {
            if (!$card || !$card.length) {
                return;
            }
            const index = parseInt($card.attr('data-index'), 10) || 0;
            $card.find('.def-st-title').text(this.stateCardTitle({
                id: $card.find('.def-st-id').val(),
                type: $card.find('.def-st-type').val(),
            }, index));
        },

        updateStateTypeHelp: function ($card) {
            const type = $card.find('.def-st-type').val() || 'normal';
            const helpKey = {
                normal: 'stateTypeHelpNormal',
                wait: 'stateTypeHelpWait',
                join: 'stateTypeHelpJoin',
                final: 'stateTypeHelpFinal',
            }[type] || 'stateTypeHelpNormal';
            const exampleKey = {
                normal: 'stateTypeExampleNormal',
                wait: 'stateTypeExampleWait',
                join: 'stateTypeExampleJoin',
                final: 'stateTypeExampleFinal',
            }[type];

            const $box = $card.find('.def-st-type-help').empty();
            $box.append(
                $('<div class="automation-type-help-title">').text(this.stateTypeShortLabel(type))
            );
            $box.append(
                $('<div class="automation-type-help-body">').text(this.tMessage(helpKey))
            );
            if (exampleKey) {
                $box.append(
                    $('<div class="automation-type-help-example">').text(this.tMessage(exampleKey))
                );
            }
        },

        applyStateVisibility: function ($card) {
            const type = $card.find('.def-st-type').val() || 'normal';
            $card.find('.st-f-wait').toggle(type === 'wait');
            $card.find('.st-f-join').toggle(type === 'join');
            $card.find('.st-f-join-timeout').toggle(type === 'join');
            if (type === 'wait') {
                const mode = $card.find('.def-st-wait-mode').val() || 'duration';
                $card.find('.st-f-wait-duration').toggle(mode === 'duration');
                $card.find('.st-f-wait-until').toggle(mode === 'until');
            }
        },

        applyTransitionWaitVisibility: function ($card) {
            const mode = $card.find('.def-tr-wait-mode').val() || '';
            $card.find('.tr-f-wait-duration').toggle(mode === 'duration');
            $card.find('.tr-f-wait-until').toggle(mode === 'until');
        },

        renderTransitions: function () {
            const $c = this.$el.find('.transition-list').empty();
            // Drop prior transition when expressions.
            Object.keys(this._expressionInputs || {}).forEach((key) => {
                if (key.indexOf('tr-') === 0) {
                    delete this._expressionInputs[key];
                }
            });
            const list = (this._def && this._def.transitions) || [];
            const stateIds = ((this._def && this._def.states) || []).map((s) => s.id).filter(Boolean);
            const stateOpts = stateIds.map((id) => ({value: id, label: id}));

            list.forEach((t, index) => {
                const prefix = 'tr-' + index + '-' + Math.floor(Math.random() * 1e6);
                const $card = $('<div class="automation-card panel panel-default">')
                    .attr('data-index', index)
                    .attr('data-view-prefix', prefix);

                $card.append(
                    $('<div class="automation-card-header">').append(
                        $('<div class="automation-card-title">')
                            .text((t.from || '?') + ' → ' + (t.to || '?')),
                        $('<button type="button" class="btn btn-link btn-sm text-danger">')
                            .attr({
                                'data-action': 'removeRow',
                                'data-bucket': 'transitions',
                                'data-index': index,
                            })
                            .text(this.tLabel('remove'))
                    )
                );

                const $body = $('<div class="automation-card-body">');
                $body.append(
                    $('<div class="row">').append(
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('from'),
                                this.buildSelect('form-control def-tr-from', stateOpts, t.from || '')
                            )
                        ),
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('to'),
                                this.buildSelect('form-control def-tr-to', stateOpts, t.to || '')
                            )
                        ),
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('waitMode'),
                                this.buildSelect(
                                    'form-control def-tr-wait-mode',
                                    [
                                        {value: '', label: this.tLabel('waitModeNone')},
                                        {value: 'duration', label: this.tLabel('waitModeDuration')},
                                        {value: 'until', label: this.tLabel('waitModeUntil')},
                                    ],
                                    t.waitUntil || t.waitUntilFormula
                                        ? 'until'
                                        : (t.waitPeriod ? 'duration' : '')
                                )
                            )
                        )
                    )
                );
                $body.append(
                    $('<div class="row tr-f-wait-duration">').append(
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('waitPeriod'),
                                $('<input type="text" class="form-control def-tr-wait">')
                                    .val(t.waitPeriod || '')
                                    .attr('placeholder', '30 minutes')
                            )
                        )
                    )
                );
                $body.append(
                    $('<div class="row tr-f-wait-until">').append(
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('waitUntil'),
                                $('<input type="text" class="form-control def-tr-wait-until">')
                                    .val(t.waitUntil || '')
                                    .attr('placeholder', '2026-08-01 14:00:00')
                            )
                        ),
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('waitUntilTimezone'),
                                $('<input type="text" class="form-control def-tr-wait-tz">')
                                    .val(t.waitUntilTimezone || '')
                                    .attr('placeholder', 'America/Sao_Paulo')
                            )
                        ),
                        $('<div class="col-sm-4">').append(
                            this.fieldGroup(
                                this.tLabel('waitUntilFormula'),
                                $('<input type="text" class="form-control def-tr-wait-until-fx">')
                                    .val(t.waitUntilFormula || '')
                                    .attr('placeholder', "entity\\attribute('closeDate')")
                            )
                        )
                    )
                );
                this.applyTransitionWaitVisibility($card);
                const $whenHost = $('<div class="journey-expression-host automation-expression-host def-tr-when-host">');
                $body.append(
                    this.fieldGroup(
                        this.tLabel('when'),
                        $whenHost,
                        this.tMessage('transitionWhenHint')
                    )
                );
                $card.append($body);
                $c.append($card);

                const whenFormula = (t.when && String(t.when).trim()) || '';
                this.mountExpression(prefix, '__when', $whenHost, {
                    multiline: true,
                    rows: 2,
                    placeholder: this.tMessage('transitionWhenPlaceholder'),
                    expressionPlaceholder: this.tMessage('whenExpressionPlaceholder'),
                    fixedValue: whenFormula,
                    expressionValue: whenFormula,
                    mode: whenFormula ? 'expression' : 'fixed',
                    snippets: this.buildAutomationSnippets(),
                });
            });
        },

        refreshStateSelects: function () {
            const ids = [];
            this.$el.find('.state-card').each((_, el) => {
                const id = ($(el).find('.def-st-id').val() || '').trim();
                if (id) {
                    ids.push(id);
                }
            });

            const fill = ($sel, current) => {
                const cur = current !== undefined ? current : $sel.val();
                $sel.empty();
                ids.forEach((id) => {
                    $sel.append($('<option>').val(id).text(id));
                });
                if (cur && ids.indexOf(cur) !== -1) {
                    $sel.val(cur);
                } else if (ids.length) {
                    $sel.val(ids[0]);
                }
            };

            const initialCur = this._def && this._def.initial;
            fill(this.$el.find('.def-initial'), initialCur);
            this.$el.find('.def-tr-from').each((_, el) => fill($(el)));
            this.$el.find('.def-tr-to').each((_, el) => fill($(el)));
        },

        // ─── Shared form helpers ─────────────────────────────────────

        fieldGroup: function (label, $control, hint) {
            const $g = $('<div class="form-group" style="margin-bottom:10px">');
            if (label) {
                $g.append($('<label class="control-label small">').text(label));
            }
            $g.append($control);
            if (hint) {
                $g.append($('<p class="text-muted small automation-field-hint">').text(hint));
            }

            return $g;
        },

        buildSelect: function (cls, options, selected) {
            const $sel = $('<select>').addClass(cls);
            (options || []).forEach((o) => {
                const value = typeof o === 'string' ? o : o.value;
                const label = typeof o === 'string' ? o : (o.label || o.value);
                const $opt = $('<option>').val(value).text(label);
                if (String(value) === String(selected)) {
                    $opt.prop('selected', true);
                }
                $sel.append($opt);
            });

            return $sel;
        },

        inputGroup: function (label, cls, value, multi) {
            const $control = multi
                ? $('<textarea>').addClass('form-control ' + cls).attr('rows', 3)
                    .css({'font-family': 'monospace', 'font-size': '12px'})
                    .val(value || '')
                : $('<input type="text">').addClass('form-control ' + cls).val(value || '');

            return this.fieldGroup(label, $control);
        },

        selectGroup: function (label, cls, options, selected) {
            const opts = (options || []).map((o) =>
                typeof o === 'string' ? {value: o, label: o} : o
            );

            return this.fieldGroup(label, this.buildSelect('form-control ' + cls, opts, selected));
        },

        parseJsonField: function (text, fallback) {
            const t = (text || '').trim();
            if (t === '') {
                return fallback;
            }
            try {
                return JSON.parse(t);
            } catch (e) {
                throw new Error(this.tMessage('invalidJson') + ': ' + t.slice(0, 40));
            }
        },

        formatMsg: function (key, vars) {
            let msg = this.tMessage(key);
            Object.keys(vars || {}).forEach((k) => {
                msg = msg.replace(new RegExp('\\{' + k + '\\}', 'g'), String(vars[k]));
            });

            return msg;
        },

        // Keys the builder renders and therefore owns on save. Anything absent
        // from this set is preserved verbatim from the loaded definition.
        MAP_STEP_KNOWN_KEYS: {
            id: true, mode: true, source: true, entityType: true,
            where: true, whereFormulas: true,
            parent: true, relation: true, link: true, linkMultiple: true,
            payloadPath: true, idsPath: true, ids: true,
            reportId: true, maxRows: true,
            requireRoles: true, foreignKey: true, ignoreParent: true,
            groupBy: true, groupTargetEntityType: true,
            timeBucket: true, aggregates: true,
        },

        readMapStepFromCard: function ($card, i) {
            let where = {};
            let whereFormulas = {};
            try {
                const read = this.readWhereFromCard($card);
                where = read.where || {};
                whereFormulas = read.whereFormulas || {};
            } catch (e) {
                this.showError(this.formatMsg('mapStepError', {index: i, field: 'where', error: e.message}));
                where = {};
                whereFormulas = {};
            }
            const step = {
                id: $card.find('.def-map-id').val() || ('s' + (i + 1)),
                mode: $card.find('.def-map-mode').val() || 'primary',
                source: $card.find('.def-map-source').val() || 'query',
                entityType: $card.find('.def-map-et').val() || 'Contact',
                where: where,
            };
            if (whereFormulas && Object.keys(whereFormulas).length) {
                step.whereFormulas = whereFormulas;
            }
            const parent = $card.find('.def-map-parent').val();
            const relation = $card.find('.def-map-rel').val();
            const link = $card.find('.def-map-link').val();
            const payloadPath = $card.find('.def-map-ppath').val();
            const idsPath = $card.find('.def-map-idspath').val();
            const reportId = $card.find('.def-map-report').val();
            const groupByRaw = $card.find('.def-map-groupby').val();
            const gTarget = $card.find('.def-map-gtarget').val();
            const ids = this.parseIdsList($card.find('.def-map-ids').val());

            const tbField = ($card.find('.def-map-tb-field').val() || '').trim();
            const tbSize = ($card.find('.def-map-tb-size').val() || '').trim();
            const tbTz = ($card.find('.def-map-tb-tz').val() || '').trim();
            let timeBucket = null;
            if (tbField || tbSize) {
                timeBucket = {};
                if (tbField) timeBucket.field = tbField;
                if (tbSize) timeBucket.size = tbSize;
                if (tbTz) timeBucket.timezone = tbTz;
            }

            const aggregates = this.readAggregatesFromCard($card);

            const requireRoles = this.parseStringList($card.find('.def-map-requireroles').val());
            const foreignKey = ($card.find('.def-map-fk').val() || '').trim();
            const maxRowsRaw = ($card.find('.def-map-maxrows').val() || '').trim();
            const ignoreParent = $card.find('.def-map-ignoreparent').prop('checked');

            if (parent) step.parent = parent;
            if (relation) step.relation = relation;
            if (link) step.link = link;
            if (payloadPath) step.payloadPath = payloadPath;
            if (idsPath) step.idsPath = idsPath;
            if (ids.length) step.ids = ids;
            if (reportId) step.reportId = reportId;
            if (maxRowsRaw !== '' && !isNaN(parseInt(maxRowsRaw, 10))) {
                step.maxRows = parseInt(maxRowsRaw, 10);
            }
            if (requireRoles.length) step.requireRoles = requireRoles;
            if (foreignKey) step.foreignKey = foreignKey;
            if (ignoreParent) step.ignoreParent = true;
            if (groupByRaw) {
                const parts = groupByRaw.split(',').map(s => s.trim()).filter(Boolean);
                step.groupBy = parts.length === 1 ? parts[0] : parts;
            }
            if (gTarget) step.groupTargetEntityType = gTarget;
            if (timeBucket) step.timeBucket = timeBucket;
            if (aggregates) step.aggregates = aggregates;

            // Carry over any keys the builder does not model, so that editing a
            // definition in builder mode never silently drops backend-only options.
            const original = $card.data('originalStep');
            if (original && typeof original === 'object') {
                Object.keys(original).forEach((key) => {
                    if (!Object.prototype.hasOwnProperty.call(step, key) && !this.MAP_STEP_KNOWN_KEYS[key]) {
                        step[key] = original[key];
                    }
                });
            }

            return step;
        },

        readBuilderIntoMemory: function () {
            if (this.jsonMode) {
                try {
                    this._def = JSON.parse(this.$el.find('.def-json').val() || '{}');
                    this.clearError();
                } catch (e) {
                    this.showError(e.message);
                }

                return;
            }

            const kind = this.recordKindKey();
            const def = {kind: kind};

            if (kind === 'batch') {
                def.limits = {
                    maxItems: parseInt(this.$el.find('.def-max-items').val(), 10) || 5000,
                    maxExpandPerParent: parseInt(this.$el.find('.def-max-expand').val(), 10) || 500,
                };
                def.stages = [];
                this.$el.find('.stage-list .batch-stage-card').each((si, stageEl) => {
                    const $stage = $(stageEl);
                    const scope = $stage.find('.def-stage-scope').val() === 'once' ? 'once' : 'forEach';
                    const stage = {
                        id: $stage.find('.def-stage-id').val() || ('s' + si),
                        scope: scope,
                        map: [],
                        actions: this.readActionsFromContainer(
                            $stage.find('.action-list[data-list="actions"]')
                        ),
                        onFailure: this.readActionsFromContainer(
                            $stage.find('.action-list[data-list="onFailure"]')
                        ),
                        itemMode: $stage.find('.def-stage-item-mode').val() || 'allMatching',
                        exportToRunBag: this.readRunBagFlagFromCard($stage, 'export'),
                        importRunBag: this.readRunBagFlagFromCard($stage, 'import'),
                    };

                    if (scope === 'forEach') {
                        $stage.find('.map-steps .map-step').each((i, el) => {
                            stage.map.push(this.readMapStepFromCard($(el), i));
                        });
                    }

                    def.stages.push(stage);
                });

                if (!def.stages.length) {
                    def.stages = [{
                        id: 's0',
                        scope: 'forEach',
                        map: [{id: 't', entityType: 'Tenant', mode: 'primary', source: 'query', where: {}}],
                        actions: [],
                        onFailure: [],
                        itemMode: 'allMatching',
                    }];
                }

                this.applyStagesMirrors(def);
            } else {
                def.initial = this.$el.find('.def-initial').val() || '';
                def.states = [];
                this.$el.find('.state-list .state-card').each((i, el) => {
                    const $card = $(el);
                    const st = {
                        id: $card.find('.def-st-id').val() || ('S' + (i + 1)),
                        type: $card.find('.def-st-type').val() || 'normal',
                        onEnter: this.readActionsFromContainer($card.find('.def-st-enter-list')),
                        onExit: this.readActionsFromContainer($card.find('.def-st-exit-list')),
                    };
                    if (st.type === 'join') {
                        st.joinMode = $card.find('.def-st-joinmode').val() || 'waitAll';
                        const timeout = ($card.find('.def-st-timeout').val() || '').trim();
                        if (timeout) {
                            st.timeoutPeriod = timeout;
                        }
                    }
                    if (st.type === 'wait') {
                        const mode = $card.find('.def-st-wait-mode').val() || 'duration';
                        if (mode === 'until') {
                            const until = ($card.find('.def-st-wait-until').val() || '').trim();
                            const untilFx = ($card.find('.def-st-wait-until-fx').val() || '').trim();
                            const tz = ($card.find('.def-st-wait-tz').val() || '').trim();
                            if (until) st.waitUntil = until;
                            if (untilFx) st.waitUntilFormula = untilFx;
                            if (tz) st.waitUntilTimezone = tz;
                        } else {
                            const wait = ($card.find('.def-st-wait').val() || '').trim();
                            if (wait) st.waitPeriod = wait;
                        }
                    }
                    def.states.push(st);
                });
                def.transitions = [];
                this.$el.find('.transition-list .automation-card').each((_, el) => {
                    const $card = $(el);
                    const t = {
                        from: $card.find('.def-tr-from').val() || '',
                        to: $card.find('.def-tr-to').val() || '',
                    };
                    const trPrefix = $card.attr('data-view-prefix');
                    let when = this.readFormulaField(trPrefix, '__when');
                    if (!when) {
                        when = ($card.find('.def-tr-when').val() || '').trim();
                    }
                    if (when) t.when = when;
                    const waitMode = $card.find('.def-tr-wait-mode').val() || '';
                    if (waitMode === 'duration') {
                        const wait = ($card.find('.def-tr-wait').val() || '').trim();
                        if (wait) t.waitPeriod = wait;
                    } else if (waitMode === 'until') {
                        const until = ($card.find('.def-tr-wait-until').val() || '').trim();
                        const untilFx = ($card.find('.def-tr-wait-until-fx').val() || '').trim();
                        const tz = ($card.find('.def-tr-wait-tz').val() || '').trim();
                        if (until) t.waitUntil = until;
                        if (untilFx) t.waitUntilFormula = untilFx;
                        if (tz) t.waitUntilTimezone = tz;
                    }
                    def.transitions.push(t);
                });
            }

            this._def = def;
            this.syncRecordKind(def.kind);
            this.clearError();
        },

        fetch: function () {
            const data = {};

            if (!this.isEditMode()) {
                return data;
            }

            try {
                if (this.jsonMode) {
                    const parsed = JSON.parse(this.$el.find('.def-json').val() || '{}');
                    const normalized = this.definitionForRecordKind(
                        parsed && typeof parsed === 'object' ? parsed : null
                    );
                    data[this.name] = normalized;
                    this.syncRecordKind(normalized.kind || this.recordKindKey());
                } else {
                    this.readBuilderIntoMemory();
                    data[this.name] = this._def;
                }
                this.clearError();
            } catch (e) {
                this.showError(e.message || this.tMessage('invalidDefinition'));
            }

            return data;
        },

        validate: function () {
            if (!this.isEditMode()) {
                return false;
            }

            let def;
            try {
                if (this.jsonMode) {
                    const parsed = JSON.parse(this.$el.find('.def-json').val() || '{}');
                    def = this.definitionForRecordKind(
                        parsed && typeof parsed === 'object' ? parsed : null
                    );
                } else {
                    this.readBuilderIntoMemory();
                    def = this._def;
                }
            } catch (e) {
                this.showValidationMessage(e.message || this.tMessage('invalidDefinition'));

                return true;
            }

            if (!def || typeof def !== 'object') {
                this.showValidationMessage(this.tMessage('definitionRequired'));

                return true;
            }

            const kind = this.definitionKindKey(def) || this.recordKindKey();
            if (kind === 'batch') {
                const stages = Array.isArray(def.stages) ? def.stages : [];
                const hasLegacyMap = Array.isArray(def.map) && def.map.length;
                if (!stages.length && !hasLegacyMap) {
                    this.showValidationMessage(this.tMessage('definitionStagesRequired'));

                    return true;
                }
                for (let i = 0; i < stages.length; i++) {
                    const st = stages[i] || {};
                    if ((st.scope || 'forEach') === 'forEach' &&
                        (!Array.isArray(st.map) || !st.map.length)
                    ) {
                        this.showValidationMessage(this.tMessage('definitionMapRequired'));

                        return true;
                    }
                }
            } else {
                if (!Array.isArray(def.states) || !def.states.length) {
                    this.showValidationMessage(this.tMessage('definitionStatesRequired'));

                    return true;
                }
                if (!(def.initial || '').toString().trim()) {
                    this.showValidationMessage(this.tMessage('definitionInitialRequired'));

                    return true;
                }
            }

            return false;
        },

        showError: function (msg) {
            this.$el.find('.def-error').text(msg).show();
        },

        clearError: function () {
            this.$el.find('.def-error').hide().text('');
        },
    });
});
