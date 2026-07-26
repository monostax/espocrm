define('feature-automation:views/fields/definition', ['views/fields/base'], function (Dep) {

    /**
     * Structured Batch/Machine definition editor with JSON fallthrough.
     * Stores Automation.definition as a json object.
     */
    return Dep.extend({

        type: 'jsonObject',

        detailTemplateContent:
            '<div class="automation-def-detail">' +
                '{{#if summaryHtml}}{{{summaryHtml}}}{{else}}' +
                '<pre class="pre-scrollable" style="max-height:320px;white-space:pre-wrap">{{jsonText}}</pre>' +
                '{{/if}}' +
            '</div>',

        editTemplateContent: '',

        jsonMode: false,

        setup: function () {
            Dep.prototype.setup.call(this);
            this.editTemplateContent = this.buildEditTemplate();
        },

        tLabel: function (key) {
            return this.translate(key, 'labels', 'Automation');
        },

        tMessage: function (key) {
            return this.translate(key, 'messages', 'Automation');
        },

        buildEditTemplate: function () {
            const t = (k) => this.tLabel(k);

            return '' +
                '<div class="automation-definition-builder">' +
                    '<div class="btn-group btn-group-sm margin-bottom" role="group">' +
                        '<button type="button" class="btn btn-default mode-builder{{#unless jsonMode}} active{{/unless}}" data-action="modeBuilder">' +
                            this.getHelper().escapeString(t('Builder')) +
                        '</button>' +
                        '<button type="button" class="btn btn-default mode-json{{#if jsonMode}} active{{/if}}" data-action="modeJson">' +
                            this.getHelper().escapeString(t('JSON')) +
                        '</button>' +
                    '</div>' +
                    '<div class="builder-pane" {{#if jsonMode}}style="display:none"{{/if}}>' +
                        '<div class="row">' +
                            '<div class="col-sm-6">' +
                                '<label class="control-label">' + this.getHelper().escapeString(t('Kind')) + '</label>' +
                                '<select class="form-control def-kind">' +
                                    '<option value="batch">' + this.getHelper().escapeString(t('Batch')) + '</option>' +
                                    '<option value="machine">' + this.getHelper().escapeString(t('Machine')) + '</option>' +
                                '</select>' +
                            '</div>' +
                            '<div class="col-sm-6 batch-only">' +
                                '<label class="control-label">' + this.getHelper().escapeString(t('Item mode')) + '</label>' +
                                '<select class="form-control def-item-mode">' +
                                    '<option value="allMatching">allMatching</option>' +
                                    '<option value="firstMatch">firstMatch</option>' +
                                '</select>' +
                            '</div>' +
                        '</div>' +
                        '<div class="batch-pane margin-top">' +
                            '<h5>' + this.getHelper().escapeString(t('Map steps')) + '</h5>' +
                            '<div class="map-steps"></div>' +
                            '<button type="button" class="btn btn-default btn-sm" data-action="addMapStep">' +
                                this.getHelper().escapeString(t('+ Map step')) +
                            '</button>' +
                            '<h5 class="margin-top">' + this.getHelper().escapeString(t('Actions')) + '</h5>' +
                            '<div class="action-list" data-list="actions"></div>' +
                            '<button type="button" class="btn btn-default btn-sm" data-action="addAction" data-list="actions">' +
                                this.getHelper().escapeString(t('+ Action')) +
                            '</button>' +
                            '<h5 class="margin-top">' + this.getHelper().escapeString(t('On failure')) + '</h5>' +
                            '<div class="action-list" data-list="onFailure"></div>' +
                            '<button type="button" class="btn btn-default btn-sm" data-action="addAction" data-list="onFailure">' +
                                this.getHelper().escapeString(t('+ Action')) +
                            '</button>' +
                            '<div class="row margin-top">' +
                                '<div class="col-sm-6">' +
                                    '<label>' + this.getHelper().escapeString(t('maxItems')) + '</label>' +
                                    '<input type="number" class="form-control def-max-items" min="1" max="50000">' +
                                '</div>' +
                                '<div class="col-sm-6">' +
                                    '<label>' + this.getHelper().escapeString(t('maxExpandPerParent')) + '</label>' +
                                    '<input type="number" class="form-control def-max-expand" min="1" max="5000">' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="machine-pane margin-top" style="display:none">' +
                            '<div class="row">' +
                                '<div class="col-sm-6">' +
                                    '<label>' + this.getHelper().escapeString(t('Initial state')) + '</label>' +
                                    '<input type="text" class="form-control def-initial" placeholder="Start">' +
                                '</div>' +
                            '</div>' +
                            '<h5 class="margin-top">' + this.getHelper().escapeString(t('States')) + '</h5>' +
                            '<div class="state-list"></div>' +
                            '<button type="button" class="btn btn-default btn-sm" data-action="addState">' +
                                this.getHelper().escapeString(t('+ State')) +
                            '</button>' +
                            '<h5 class="margin-top">' + this.getHelper().escapeString(t('Transitions')) + '</h5>' +
                            '<div class="transition-list"></div>' +
                            '<button type="button" class="btn btn-default btn-sm" data-action="addTransition">' +
                                this.getHelper().escapeString(t('+ Transition')) +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                    '<div class="json-pane" {{#unless jsonMode}}style="display:none"{{/unless}}>' +
                        '<textarea class="form-control def-json" rows="18" style="font-family:monospace;font-size:12px"></textarea>' +
                        '<div class="text-muted small margin-top-sm">' +
                            this.getHelper().escapeString(t('jsonHint')) +
                        '</div>' +
                    '</div>' +
                    '<div class="text-danger def-error margin-top-sm" style="display:none"></div>' +
                '</div>';
        },

        data: function () {
            const d = Dep.prototype.data.call(this);
            const def = this.getDefinition() || {};
            const kind = (def.kind || this.model.get('kind') || 'batch').toString().toLowerCase();

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

            this.$el.on('click', '[data-action="modeBuilder"]', () => this.switchMode(false));
            this.$el.on('click', '[data-action="modeJson"]', () => this.switchMode(true));
            this.$el.on('click', '[data-action="addMapStep"]', () => {
                this.readBuilderIntoMemory();
                this._def.map = this._def.map || [];
                this._def.map.push({
                    id: 's' + (this._def.map.length + 1),
                    entityType: 'Contact',
                    mode: this._def.map.length ? 'expand' : 'primary',
                    source: this._def.map.length ? 'relation' : 'query',
                    parent: this._def.map.length ? (this._def.map[0].id || 's1') : undefined,
                    where: {},
                });
                this.renderBuilderLists();
            });
            this.$el.on('click', '[data-action="addAction"]', (e) => {
                const list = $(e.currentTarget).data('list');
                this.readBuilderIntoMemory();
                this._def[list] = this._def[list] || [];
                this._def[list].push({type: 'notifyUser', params: {message: ''}});
                this.renderBuilderLists();
            });
            this.$el.on('click', '[data-action="addState"]', () => {
                this.readBuilderIntoMemory();
                this._def.states = this._def.states || [];
                const id = 'S' + (this._def.states.length + 1);
                this._def.states.push({id: id, type: 'normal', onEnter: [], onExit: []});
                if (!this._def.initial) {
                    this._def.initial = id;
                }
                this.renderBuilderLists();
            });
            this.$el.on('click', '[data-action="addTransition"]', () => {
                this.readBuilderIntoMemory();
                this._def.transitions = this._def.transitions || [];
                const from = (this._def.states && this._def.states[0]) ? this._def.states[0].id : '';
                this._def.transitions.push({from: from, to: from, when: '', waitPeriod: ''});
                this.renderBuilderLists();
            });
            this.$el.on('click', '[data-action="removeRow"]', (e) => {
                const $t = $(e.currentTarget);
                const bucket = $t.data('bucket');
                const idx = parseInt($t.data('index'), 10);
                this.readBuilderIntoMemory();
                if (Array.isArray(this._def[bucket])) {
                    this._def[bucket].splice(idx, 1);
                }
                this.renderBuilderLists();
            });
            this.$el.on('change', '.def-kind', () => {
                this.readBuilderIntoMemory();
                this.toggleKindPanes();
            });

            this._def = this.getDefinition() || this.defaultDefinition();
            this.seedBuilderFromDef();
            this.renderBuilderLists();
            this.toggleKindPanes();
            this.$el.find('.def-json').val(this.formatJson(this._def));
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
                    this._def = parsed && typeof parsed === 'object' ? parsed : this.defaultDefinition();
                    this.clearError();
                } catch (err) {
                    this.showError(this.tMessage('invalidJsonSwitchBuilder'));
                    return;
                }
                this.seedBuilderFromDef();
                this.renderBuilderLists();
                this.toggleKindPanes();
                this.$el.find('.json-pane').hide();
                this.$el.find('.builder-pane').show();
                this.$el.find('.mode-builder').addClass('active');
                this.$el.find('.mode-json').removeClass('active');
            }
            this.jsonMode = toJson;
        },

        toggleKindPanes: function () {
            const kind = this.$el.find('.def-kind').val() || 'batch';
            if (kind === 'machine') {
                this.$el.find('.batch-pane').hide();
                this.$el.find('.batch-only').hide();
                this.$el.find('.machine-pane').show();
            } else {
                this.$el.find('.machine-pane').hide();
                this.$el.find('.batch-pane').show();
                this.$el.find('.batch-only').show();
            }
        },

        defaultDefinition: function () {
            const kind = (this.model.get('kind') || 'Batch').toString().toLowerCase();
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

            if (kind === 'batch' || Array.isArray(def.map)) {
                const map = def.map || [];
                html += '<li>' + this.getHelper().escapeString(this.tLabel('Map')) + ': ' +
                    map.length + ' ' + this.getHelper().escapeString(this.tLabel('step(s)')) + '</li>';
                map.forEach((s) => {
                    html += '<li class="text-muted">• ' +
                        this.getHelper().escapeString((s && s.id) || '?') +
                        ' [' + this.getHelper().escapeString((s && s.mode) || '') +
                        '] ' + this.getHelper().escapeString((s && s.entityType) || '') +
                        (s && s.source ? ' src=' + this.getHelper().escapeString(s.source) : '') +
                        '</li>';
                });
                html += '<li>' + this.getHelper().escapeString(this.tLabel('Actions')) + ': ' +
                    ((def.actions || []).length) + '</li>';
            }

            if (kind === 'machine' || Array.isArray(def.states)) {
                html += '<li>' + this.getHelper().escapeString(this.tLabel('Initial')) + ': ' +
                    this.getHelper().escapeString(def.initial || '') + '</li>';
                html += '<li>' + this.getHelper().escapeString(this.tLabel('States')) + ': ' +
                    ((def.states || []).length) +
                    ', ' + this.getHelper().escapeString(this.tLabel('Transitions')) + ': ' +
                    ((def.transitions || []).length) + '</li>';
                (def.states || []).forEach((s) => {
                    html += '<li class="text-muted">• ' +
                        this.getHelper().escapeString((s && s.id) || '?') +
                        ' (' + this.getHelper().escapeString((s && s.type) || 'normal') + ')' +
                        (s && s.waitPeriod ? ' wait ' + this.getHelper().escapeString(s.waitPeriod) : '') +
                        '</li>';
                });
            }

            html += '</ul>';

            return html;
        },

        seedBuilderFromDef: function () {
            const def = this._def || {};
            const kind = ((def.kind || this.model.get('kind') || 'batch') + '').toLowerCase();
            this.$el.find('.def-kind').val(kind === 'machine' ? 'machine' : 'batch');
            this.$el.find('.def-item-mode').val(def.itemMode || 'allMatching');
            this.$el.find('.def-max-items').val((def.limits && def.limits.maxItems) || 5000);
            this.$el.find('.def-max-expand').val((def.limits && def.limits.maxExpandPerParent) || 500);
            this.$el.find('.def-initial').val(def.initial || '');
        },

        entityOptions: function () {
            return ['Tenant', 'User', 'Contact', 'Account', 'Lead', 'Opportunity', 'Task'];
        },

        modeOptions: function () {
            return ['primary', 'expand', 'loop', 'passThrough', 'groupBy'];
        },

        sourceOptions: function () {
            return ['query', 'relation', 'linkMultiple', 'ids', 'payload', 'report'];
        },

        actionTypeOptions: function () {
            const list = this.getMetadata().get(['app', 'automationActionTypes', 'typeList']) || [
                'notifyUser', 'sendWhatsAppMessage', 'createTask', 'startChildAutomation',
            ];

            return list;
        },

        renderBuilderLists: function () {
            this.renderMapSteps();
            this.renderActionList('actions');
            this.renderActionList('onFailure');
            this.renderStates();
            this.renderTransitions();
        },

        renderMapSteps: function () {
            const $c = this.$el.find('.map-steps').empty();
            const map = (this._def && this._def.map) || [];
            const entityOpts = this.entityOptions();
            const modeOpts = this.modeOptions();
            const sourceOpts = this.sourceOptions();
            const removeLbl = this.tLabel('remove');

            map.forEach((step, index) => {
                const $row = $('<div class="panel panel-default map-step" style="padding:8px;margin-bottom:8px">');
                $row.append(
                    '<div class="pull-right"><button type="button" class="btn btn-link btn-sm text-danger" ' +
                    'data-action="removeRow" data-bucket="map" data-index="' + index + '">' +
                    this.getHelper().escapeString(removeLbl) + '</button></div>'
                );
                $row.append(this.inputGroup(this.tLabel('id'), 'def-map-id-' + index, step.id || ''));
                $row.append(this.selectGroup(this.tLabel('mode'), 'def-map-mode-' + index, modeOpts, step.mode || 'primary'));
                $row.append(this.selectGroup(this.tLabel('source'), 'def-map-source-' + index, sourceOpts, step.source || 'query'));
                $row.append(this.selectGroup(this.tLabel('entityType'), 'def-map-et-' + index, entityOpts, step.entityType || 'Contact'));
                $row.append(this.inputGroup(this.tLabel('parent'), 'def-map-parent-' + index, step.parent || ''));
                $row.append(this.inputGroup(this.tLabel('relation'), 'def-map-rel-' + index, step.relation || ''));
                $row.append(this.inputGroup(this.tLabel('link (linkMultiple)'), 'def-map-link-' + index, step.link || step.linkMultiple || ''));
                $row.append(this.inputGroup(this.tLabel('payloadPath'), 'def-map-ppath-' + index, step.payloadPath || ''));
                $row.append(this.inputGroup(this.tLabel('idsPath'), 'def-map-idspath-' + index, step.idsPath || ''));
                $row.append(this.inputGroup(this.tLabel('ids JSON'), 'def-map-ids-' + index, this.formatJson(step.ids || []), true));
                $row.append(this.inputGroup(this.tLabel('reportId'), 'def-map-report-' + index, step.reportId || ''));
                $row.append(this.inputGroup(
                    this.tLabel('groupBy'),
                    'def-map-groupby-' + index,
                    Array.isArray(step.groupBy) ? step.groupBy.join(',') : (step.groupBy || '')
                ));
                $row.append(this.inputGroup(
                    this.tLabel('groupTargetEntityType'),
                    'def-map-gtarget-' + index,
                    step.groupTargetEntityType || ''
                ));
                $row.append(this.inputGroup(
                    this.tLabel('timeBucket JSON'),
                    'def-map-tb-' + index,
                    this.formatJson(step.timeBucket || null),
                    true
                ));
                $row.append(this.inputGroup(
                    this.tLabel('aggregates JSON'),
                    'def-map-agg-' + index,
                    this.formatJson(step.aggregates || null),
                    true
                ));
                $row.append(this.inputGroup(
                    this.tLabel('where JSON'),
                    'def-map-where-' + index,
                    this.formatJson(step.where || {}),
                    true
                ));
                $c.append($row);
            });
        },

        renderActionList: function (bucket) {
            const $c = this.$el.find('.action-list[data-list="' + bucket + '"]').empty();
            const list = (this._def && this._def[bucket]) || [];
            const types = this.actionTypeOptions();
            const removeLbl = this.tLabel('remove');

            list.forEach((action, index) => {
                const $row = $('<div class="panel panel-default" style="padding:8px;margin-bottom:8px">');
                $row.append(
                    '<div class="pull-right"><button type="button" class="btn btn-link btn-sm text-danger" ' +
                    'data-action="removeRow" data-bucket="' + bucket + '" data-index="' + index + '">' +
                    this.getHelper().escapeString(removeLbl) + '</button></div>'
                );
                $row.append(this.selectGroup(this.tLabel('type'), 'def-' + bucket + '-type-' + index, types, action.type || 'notifyUser'));
                $row.append(this.inputGroup(this.tLabel('when (formula)'), 'def-' + bucket + '-when-' + index, action.when || ''));
                $row.append(this.inputGroup(
                    this.tLabel('idempotencyKey'),
                    'def-' + bucket + '-idemp-' + index,
                    action.idempotencyKey === true ? 'true' :
                        (action.idempotencyKey === false || action.idempotencyKey == null ? '' : String(action.idempotencyKey))
                ));
                $row.append(this.inputGroup(
                    this.tLabel('debounce'),
                    'def-' + bucket + '-debounce-' + index,
                    action.debounce || ''
                ));
                $row.append(this.inputGroup(
                    this.tLabel('params JSON'),
                    'def-' + bucket + '-params-' + index,
                    this.formatJson(action.params || {}),
                    true
                ));
                $c.append($row);
            });
        },

        renderStates: function () {
            const $c = this.$el.find('.state-list').empty();
            const list = (this._def && this._def.states) || [];
            const types = ['normal', 'wait', 'join', 'final'];
            const removeLbl = this.tLabel('remove');

            list.forEach((state, index) => {
                const $row = $('<div class="panel panel-default" style="padding:8px;margin-bottom:8px">');
                $row.append(
                    '<div class="pull-right"><button type="button" class="btn btn-link btn-sm text-danger" ' +
                    'data-action="removeRow" data-bucket="states" data-index="' + index + '">' +
                    this.getHelper().escapeString(removeLbl) + '</button></div>'
                );
                $row.append(this.inputGroup(this.tLabel('id'), 'def-st-id-' + index, state.id || ''));
                $row.append(this.selectGroup(this.tLabel('type'), 'def-st-type-' + index, types, state.type || 'normal'));
                $row.append(this.inputGroup(
                    this.tLabel('waitPeriod / timeout'),
                    'def-st-wait-' + index,
                    state.waitPeriod || state.timeoutPeriod || ''
                ));
                $row.append(this.selectGroup(
                    this.tLabel('joinMode'),
                    'def-st-joinmode-' + index,
                    ['waitAll', 'waitAny'],
                    state.joinMode || 'waitAll'
                ));
                $row.append(this.inputGroup(
                    this.tLabel('onEnter actions JSON'),
                    'def-st-enter-' + index,
                    this.formatJson(state.onEnter || []),
                    true
                ));
                $row.append(this.inputGroup(
                    this.tLabel('onExit actions JSON'),
                    'def-st-exit-' + index,
                    this.formatJson(state.onExit || []),
                    true
                ));
                $c.append($row);
            });
        },

        renderTransitions: function () {
            const $c = this.$el.find('.transition-list').empty();
            const list = (this._def && this._def.transitions) || [];
            const removeLbl = this.tLabel('remove');

            list.forEach((t, index) => {
                const $row = $('<div class="panel panel-default" style="padding:8px;margin-bottom:8px">');
                $row.append(
                    '<div class="pull-right"><button type="button" class="btn btn-link btn-sm text-danger" ' +
                    'data-action="removeRow" data-bucket="transitions" data-index="' + index + '">' +
                    this.getHelper().escapeString(removeLbl) + '</button></div>'
                );
                $row.append(this.inputGroup(this.tLabel('from'), 'def-tr-from-' + index, t.from || ''));
                $row.append(this.inputGroup(this.tLabel('to'), 'def-tr-to-' + index, t.to || ''));
                $row.append(this.inputGroup(this.tLabel('when'), 'def-tr-when-' + index, t.when || ''));
                $row.append(this.inputGroup(this.tLabel('waitPeriod'), 'def-tr-wait-' + index, t.waitPeriod || ''));
                $c.append($row);
            });
        },

        inputGroup: function (label, cls, value, multi) {
            const tag = multi
                ? '<textarea class="form-control ' + cls + '" rows="3" style="font-family:monospace;font-size:12px">' +
                    this.getHelper().escapeString(value || '') + '</textarea>'
                : '<input type="text" class="form-control ' + cls + '" value="' +
                    this.getHelper().escapeString(value || '') + '">';

            return $('<div class="form-group" style="margin-bottom:6px">').append(
                $('<label class="control-label small">').text(label),
                $(tag)
            );
        },

        selectGroup: function (label, cls, options, selected) {
            let html = '<select class="form-control ' + cls + '">';
            options.forEach((o) => {
                const sel = o === selected ? ' selected' : '';
                html += '<option value="' + o + '"' + sel + '>' + o + '</option>';
            });
            html += '</select>';

            return $('<div class="form-group" style="margin-bottom:6px">').append(
                $('<label class="control-label small">').text(label),
                $(html)
            );
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

            const kind = this.$el.find('.def-kind').val() || 'batch';
            const def = {kind: kind};

            if (kind === 'batch') {
                def.itemMode = this.$el.find('.def-item-mode').val() || 'allMatching';
                def.limits = {
                    maxItems: parseInt(this.$el.find('.def-max-items').val(), 10) || 5000,
                    maxExpandPerParent: parseInt(this.$el.find('.def-max-expand').val(), 10) || 500,
                };
                def.map = [];
                const mapCount = this.$el.find('.map-steps .map-step').length;
                for (let i = 0; i < mapCount; i++) {
                    let where = {};
                    try {
                        where = this.parseJsonField(this.$el.find('.def-map-where-' + i).val(), {});
                    } catch (e) {
                        this.showError(this.formatMsg('mapStepError', {index: i, field: 'where', error: e.message}));
                        where = {};
                    }
                    const step = {
                        id: this.$el.find('.def-map-id-' + i).val() || ('s' + (i + 1)),
                        mode: this.$el.find('.def-map-mode-' + i).val() || 'primary',
                        source: this.$el.find('.def-map-source-' + i).val() || 'query',
                        entityType: this.$el.find('.def-map-et-' + i).val() || 'Contact',
                        where: where,
                    };
                    const parent = this.$el.find('.def-map-parent-' + i).val();
                    const relation = this.$el.find('.def-map-rel-' + i).val();
                    const link = this.$el.find('.def-map-link-' + i).val();
                    const payloadPath = this.$el.find('.def-map-ppath-' + i).val();
                    const idsPath = this.$el.find('.def-map-idspath-' + i).val();
                    const reportId = this.$el.find('.def-map-report-' + i).val();
                    const groupByRaw = this.$el.find('.def-map-groupby-' + i).val();
                    const gTarget = this.$el.find('.def-map-gtarget-' + i).val();
                    let ids = [];
                    let timeBucket = null;
                    let aggregates = null;
                    try {
                        ids = this.parseJsonField(this.$el.find('.def-map-ids-' + i).val(), []);
                    } catch (e) {
                        this.showError(this.formatMsg('mapStepError', {index: i, field: 'ids', error: e.message}));
                    }
                    try {
                        const tbVal = this.$el.find('.def-map-tb-' + i).val();
                        if (tbVal && tbVal.trim() && tbVal.trim() !== 'null') {
                            timeBucket = this.parseJsonField(tbVal, null);
                        }
                    } catch (e) {
                        this.showError(this.formatMsg('mapStepError', {index: i, field: 'timeBucket', error: e.message}));
                    }
                    try {
                        const agVal = this.$el.find('.def-map-agg-' + i).val();
                        if (agVal && agVal.trim() && agVal.trim() !== 'null') {
                            aggregates = this.parseJsonField(agVal, null);
                        }
                    } catch (e) {
                        this.showError(this.formatMsg('mapStepError', {index: i, field: 'aggregates', error: e.message}));
                    }
                    if (parent) step.parent = parent;
                    if (relation) step.relation = relation;
                    if (link) step.link = link;
                    if (payloadPath) step.payloadPath = payloadPath;
                    if (idsPath) step.idsPath = idsPath;
                    if (Array.isArray(ids) && ids.length) step.ids = ids;
                    if (reportId) step.reportId = reportId;
                    if (groupByRaw) {
                        const parts = groupByRaw.split(',').map(s => s.trim()).filter(Boolean);
                        step.groupBy = parts.length === 1 ? parts[0] : parts;
                    }
                    if (gTarget) step.groupTargetEntityType = gTarget;
                    if (timeBucket) step.timeBucket = timeBucket;
                    if (aggregates) step.aggregates = aggregates;
                    def.map.push(step);
                }
                def.actions = this.readActions('actions');
                def.onFailure = this.readActions('onFailure');
            } else {
                def.initial = this.$el.find('.def-initial').val() || '';
                def.states = [];
                const stCount = this.$el.find('.state-list .panel').length;
                for (let i = 0; i < stCount; i++) {
                    let onEnter = [];
                    let onExit = [];
                    try {
                        onEnter = this.parseJsonField(this.$el.find('.def-st-enter-' + i).val(), []);
                        onExit = this.parseJsonField(this.$el.find('.def-st-exit-' + i).val(), []);
                    } catch (e) {
                        this.showError(this.formatMsg('stateError', {index: i, error: e.message}));
                    }
                    const st = {
                        id: this.$el.find('.def-st-id-' + i).val() || ('S' + (i + 1)),
                        type: this.$el.find('.def-st-type-' + i).val() || 'normal',
                        onEnter: onEnter,
                        onExit: onExit,
                    };
                    const wait = this.$el.find('.def-st-wait-' + i).val();
                    if (wait) {
                        if (st.type === 'join') {
                            st.timeoutPeriod = wait;
                        } else {
                            st.waitPeriod = wait;
                        }
                    }
                    if (st.type === 'join') {
                        st.joinMode = this.$el.find('.def-st-joinmode-' + i).val() || 'waitAll';
                    }
                    def.states.push(st);
                }
                def.transitions = [];
                const trCount = this.$el.find('.transition-list .panel').length;
                for (let i = 0; i < trCount; i++) {
                    const t = {
                        from: this.$el.find('.def-tr-from-' + i).val() || '',
                        to: this.$el.find('.def-tr-to-' + i).val() || '',
                    };
                    const when = this.$el.find('.def-tr-when-' + i).val();
                    const wait = this.$el.find('.def-tr-wait-' + i).val();
                    if (when) t.when = when;
                    if (wait) t.waitPeriod = wait;
                    def.transitions.push(t);
                }
            }

            this._def = def;
            this.clearError();
        },

        readActions: function (bucket) {
            const out = [];
            const count = this.$el.find('.action-list[data-list="' + bucket + '"] .panel').length;
            for (let i = 0; i < count; i++) {
                let params = {};
                try {
                    params = this.parseJsonField(this.$el.find('.def-' + bucket + '-params-' + i).val(), {});
                } catch (e) {
                    this.showError(this.formatMsg('actionError', {bucket: bucket, index: i, error: e.message}));
                }
                const a = {
                    type: this.$el.find('.def-' + bucket + '-type-' + i).val() || 'notifyUser',
                    params: params,
                };
                const when = this.$el.find('.def-' + bucket + '-when-' + i).val();
                if (when) a.when = when;
                const idemp = (this.$el.find('.def-' + bucket + '-idemp-' + i).val() || '').trim();
                if (idemp === 'true') {
                    a.idempotencyKey = true;
                } else if (idemp) {
                    a.idempotencyKey = idemp;
                }
                const debounce = (this.$el.find('.def-' + bucket + '-debounce-' + i).val() || '').trim();
                if (debounce) a.debounce = debounce;
                out.push(a);
            }

            return out;
        },

        fetch: function () {
            if (!this.isEditMode()) {
                return;
            }

            try {
                if (this.jsonMode) {
                    const parsed = JSON.parse(this.$el.find('.def-json').val() || '{}');
                    this.model.set(this.name, parsed, {silent: true});
                } else {
                    this.readBuilderIntoMemory();
                    this.model.set(this.name, this._def, {silent: true});
                }
                this.clearError();
            } catch (e) {
                this.showError(e.message || this.tMessage('invalidDefinition'));
            }
        },

        validate: function () {
            this.fetch();
            const v = this.model.get(this.name);
            if (!v || typeof v !== 'object') {
                this.showValidationMessage(this.tMessage('definitionRequired'));
                return true;
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
