define('feature-automation:views/fields/entity-type-filter', [
    'views/fields/base',
    'feature-journey:helpers/custom-fields',
], function (Dep, CustomFieldsHelper) {
    /**
     * Nested Espo where-tree editor for records that start an automation.
     * The persisted shape intentionally matches CustomFieldsBag::matchWhereItem.
     */
    return Dep.extend({

        type: 'jsonObject',

        editTemplateContent:
            '<div class="automation-entity-filter">' +
                '<p class="text-muted small automation-filter-hint">' +
                    '{{translate "entityFilterHint" category="messages" scope="Automation"}}' +
                '</p>' +
                '{{#unless hasSubjectEntityType}}' +
                    '<div class="alert alert-info automation-filter-subject-warning">' +
                        '{{translate "entityFilterChooseSubject" category="messages" scope="Automation"}}' +
                    '</div>' +
                '{{/unless}}' +
                '{{#if formulaOverride}}' +
                    '<div class="alert alert-warning automation-filter-formula-warning">' +
                        '{{translate "entityFilterFormulaOverride" category="messages" scope="Automation"}}' +
                    '</div>' +
                '{{else}}' +
                    '<div class="alert alert-warning automation-filter-formula-warning hidden">' +
                        '{{translate "entityFilterFormulaOverride" category="messages" scope="Automation"}}' +
                    '</div>' +
                '{{/if}}' +
                '<div class="automation-filter-toolbar">' +
                    '<div class="btn-group btn-group-sm" role="group">' +
                        '<button type="button" class="btn btn-default" data-action="setRootOp" data-op="and">' +
                            '{{translate "matchAll" category="labels" scope="Automation"}}' +
                        '</button>' +
                        '<button type="button" class="btn btn-default" data-action="setRootOp" data-op="or">' +
                            '{{translate "matchAny" category="labels" scope="Automation"}}' +
                        '</button>' +
                    '</div>' +
                    '<button type="button" class="btn btn-default btn-sm" data-action="addRootCondition">' +
                        '<span class="fas fa-plus"></span> ' +
                        '{{translate "addCondition" category="labels" scope="Automation"}}' +
                    '</button>' +
                    '<button type="button" class="btn btn-default btn-sm" data-action="addRootGroup">' +
                        '<span class="fas fa-folder-plus"></span> ' +
                        '{{translate "addConditionGroup" category="labels" scope="Automation"}}' +
                    '</button>' +
                    '<button type="button" class="btn btn-link btn-sm" data-action="toggleAdvanced">' +
                        '{{translate "advancedJson" category="labels" scope="Automation"}}' +
                    '</button>' +
                '</div>' +
                '<div class="automation-filter-tree"></div>' +
                '<div class="automation-filter-raw-wrap hidden margin-top-sm">' +
                    '<textarea class="form-control automation-filter-raw" rows="10"></textarea>' +
                '</div>' +
            '</div>',

        detailTemplateContent:
            '{{#if isNotEmpty}}' +
                '<div class="automation-filter-detail">{{{summaryHtml}}}</div>' +
            '{{else}}' +
                '<span class="none-value">{{translate "None"}}</span>' +
            '{{/if}}',

        listTemplateContent:
            '{{#if isNotEmpty}}' +
                '<span class="text-muted">{{summary}}</span>' +
            '{{else}}' +
                '<span class="none-value">{{translate "None"}}</span>' +
            '{{/if}}',

        setup: function () {
            Dep.prototype.setup.call(this);

            this.cfHelper = new CustomFieldsHelper(this);
            this._legacyValue = this.getLegacyValue(this.model.get(this.name));
            this._tree = this.normalizeTree(this.model.get(this.name));
            this._attributeOptions = [];
            this._advancedOpen = false;
            this._rawDirty = false;
            this._dirty = false;

            this.listenTo(this.model, 'change:subjectEntityType', () => {
                if (!this.isRendered() || !this.isEditMode()) {
                    return;
                }

                this.loadAttributeOptions();
            });

            this.listenTo(this.model, 'change:entityTypeFilterFormula', () => {
                if (!this.isRendered() || !this.isEditMode()) {
                    return;
                }

                const hasFormula = !!String(this.model.get('entityTypeFilterFormula') || '').trim();
                this.$el.find('.automation-filter-formula-warning').toggleClass('hidden', !hasFormula);
            });
        },

        data: function () {
            const tree = this.isEditMode() ? this._tree : this.normalizeTree(this.model.get(this.name));
            const hasRules = this.hasRules(tree);

            return {
                ...Dep.prototype.data.call(this),
                hasSubjectEntityType: !!this.getSubjectEntityType(),
                formulaOverride: !!String(this.model.get('entityTypeFilterFormula') || '').trim(),
                isNotEmpty: hasRules,
                summary: this.summarize(tree),
                summaryHtml: this.renderSummaryHtml(tree),
            };
        },

        getSubjectEntityType: function () {
            return String(this.model.get('subjectEntityType') || '').trim();
        },

        getLegacyValue: function (value) {
            if (typeof value !== 'string') {
                return null;
            }

            const trimmed = value.trim();

            if (!trimmed || trimmed.charAt(0) === '{' || trimmed.charAt(0) === '[') {
                return null;
            }

            return trimmed;
        },

        normalizeTree: function (value) {
            let parsed = value;

            if (typeof parsed === 'string') {
                try {
                    parsed = JSON.parse(parsed);
                } catch (e) {
                    return this.createGroup('and');
                }
            }

            if (!parsed || typeof parsed !== 'object') {
                return this.createGroup('and');
            }

            if (Array.isArray(parsed)) {
                return {
                    type: 'and',
                    value: parsed.map((node) => this.normalizeNode(node)).filter(Boolean),
                };
            }

            if (parsed.type === 'and' || parsed.type === 'or') {
                return this.normalizeGroup(parsed);
            }

            if (parsed.attribute || parsed.type) {
                return {
                    type: 'and',
                    value: [this.normalizeNode(parsed)].filter(Boolean),
                };
            }

            return this.createGroup('and');
        },

        normalizeNode: function (node) {
            if (!node || typeof node !== 'object') {
                return null;
            }

            if (node.type === 'and' || node.type === 'or') {
                return this.normalizeGroup(node);
            }

            return {
                type: String(node.type || 'equals'),
                attribute: String(node.attribute || ''),
                ...(node.value !== undefined ? {value: node.value} : {}),
            };
        },

        normalizeGroup: function (group) {
            return {
                type: group.type === 'or' ? 'or' : 'and',
                value: Array.isArray(group.value)
                    ? group.value.map((node) => this.normalizeNode(node)).filter(Boolean)
                    : [],
            };
        },

        createGroup: function (type) {
            return {type: type === 'or' ? 'or' : 'and', value: []};
        },

        createLeaf: function () {
            return {type: 'equals', attribute: '', value: ''};
        },

        hasRules: function (node) {
            if (!node || typeof node !== 'object') {
                return false;
            }

            if (node.type === 'and' || node.type === 'or') {
                return (node.value || []).some((child) => this.hasRules(child));
            }

            return !!node.attribute;
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (!this.isEditMode()) {
                return;
            }

            this.$tree = this.$el.find('.automation-filter-tree');
            this.$rawWrap = this.$el.find('.automation-filter-raw-wrap');
            this.$raw = this.$el.find('.automation-filter-raw');
            this.bindEvents();
            this.renderTree();
            this.loadAttributeOptions();
        },

        bindEvents: function () {
            this.$el.off('.automationEntityFilter');

            this.$el.on('click.automationEntityFilter', '[data-action="setRootOp"]', (event) => {
                this._tree.type = $(event.currentTarget).data('op') === 'or' ? 'or' : 'and';
                this.renderTree();
                this.onChanged();
            });

            this.$el.on('click.automationEntityFilter', '[data-action="addRootCondition"]', () => {
                this._tree.value.push(this.createLeaf());
                this.renderTree();
                this.onChanged();
            });

            this.$el.on('click.automationEntityFilter', '[data-action="addRootGroup"]', () => {
                this._tree.value.push(this.createGroup('and'));
                this.renderTree();
                this.onChanged();
            });

            this.$el.on('click.automationEntityFilter', '[data-action="setGroupOp"]', (event) => {
                const group = this.getNode($(event.currentTarget).data('path'));

                if (!group) {
                    return;
                }

                group.type = $(event.currentTarget).data('op') === 'or' ? 'or' : 'and';
                this.renderTree();
                this.onChanged();
            });

            this.$el.on('click.automationEntityFilter', '[data-action="addGroupCondition"]', (event) => {
                const group = this.getNode($(event.currentTarget).data('path'));

                if (!group || !Array.isArray(group.value)) {
                    return;
                }

                group.value.push(this.createLeaf());
                this.renderTree();
                this.onChanged();
            });

            this.$el.on('click.automationEntityFilter', '[data-action="addNestedGroup"]', (event) => {
                const group = this.getNode($(event.currentTarget).data('path'));

                if (!group || !Array.isArray(group.value)) {
                    return;
                }

                group.value.push(this.createGroup('and'));
                this.renderTree();
                this.onChanged();
            });

            this.$el.on('click.automationEntityFilter', '[data-action="removeNode"]', (event) => {
                this.removeNode($(event.currentTarget).data('path'));
                this.renderTree();
                this.onChanged();
            });

            this.$el.on('click.automationEntityFilter', '[data-action="toggleAdvanced"]', () => {
                this._advancedOpen = !this._advancedOpen;
                this.$rawWrap.toggleClass('hidden', !this._advancedOpen);

                if (this._advancedOpen) {
                    this.$raw.val(JSON.stringify(this._tree, null, 2));
                }
            });

            this.$raw.on('input change', () => {
                this._rawDirty = true;
                this._dirty = true;

                try {
                    this._tree = this.normalizeTree(JSON.parse(String(this.$raw.val() || '{}')));
                    this._parseError = false;
                } catch (e) {
                    this._parseError = true;
                }

                this.trigger('change');
            });
        },

        loadAttributeOptions: function () {
            const entityType = this.getSubjectEntityType();
            const tenantId = this.model.get('tenantId') || (this.model.get('tenant') || {}).id || null;

            if (!entityType) {
                this._attributeOptions = [];
                this.renderTree();

                return Promise.resolve();
            }

            return this.cfHelper.loadAttributeOptions(entityType, tenantId).then((options) => {
                this._attributeOptions = options || [];
                this.renderTree();
            }).catch(() => {
                this._attributeOptions = [];
                this.renderTree();
            });
        },

        getNode: function (path) {
            const parts = String(path || '') === ''
                ? []
                : String(path).split('.').map((part) => parseInt(part, 10));
            let node = this._tree;

            for (const index of parts) {
                if (!node || !Array.isArray(node.value) || !node.value[index]) {
                    return null;
                }

                node = node.value[index];
            }

            return node;
        },

        removeNode: function (path) {
            const parts = String(path || '').split('.');
            const index = parseInt(parts.pop(), 10);
            const parent = this.getNode(parts.join('.'));

            if (parent && Array.isArray(parent.value) && !Number.isNaN(index)) {
                parent.value.splice(index, 1);
            }
        },

        renderTree: function () {
            if (!this.$tree) {
                return;
            }

            this.$tree.empty();
            this.$tree.append(this.buildGroup(this._tree, '', true));
            this.highlightRootOp();
        },

        highlightRootOp: function () {
            const op = this._tree.type === 'or' ? 'or' : 'and';

            this.$el.find('[data-action="setRootOp"]')
                .removeClass('active btn-primary')
                .addClass('btn-default');
            this.$el.find('[data-action="setRootOp"][data-op="' + op + '"]')
                .removeClass('btn-default')
                .addClass('active btn-primary');
        },

        buildGroup: function (group, path, isRoot) {
            const $group = $('<div>').addClass(
                isRoot ? 'automation-filter-root' : 'automation-filter-group panel panel-default'
            );
            const $header = $('<div>').addClass('automation-filter-group-header');
            const $op = $('<div>').addClass('btn-group btn-group-xs').attr('role', 'group');
            const activeOp = group.type === 'or' ? 'or' : 'and';

            ['and', 'or'].forEach((op) => {
                const label = this.translate(
                    op === 'and' ? 'matchAll' : 'matchAny',
                    'labels',
                    'Automation'
                );
                const $button = $('<button>')
                    .attr({
                        type: 'button',
                        'data-action': 'setGroupOp',
                        'data-path': path,
                        'data-op': op,
                    })
                    .addClass('btn btn-default')
                    .text(label);

                if (op === activeOp) {
                    $button.removeClass('btn-default').addClass('active btn-primary');
                }

                $op.append($button);
            });

            if (!isRoot) {
                $header.append(
                    $('<span>')
                        .addClass('automation-filter-group-title')
                        .text(this.translate('conditionGroup', 'labels', 'Automation'))
                );
            }

            $header.append($op);

            if (!isRoot) {
                $header.append(
                    $('<button>')
                        .attr({type: 'button', 'data-action': 'removeNode', 'data-path': path})
                        .addClass('btn btn-link btn-xs text-danger pull-right')
                        .text(this.translate('remove', 'labels', 'Automation'))
                );
            }

            const $body = $('<div>').addClass(isRoot ? 'automation-filter-root-body' : 'panel-body');
            const children = Array.isArray(group.value) ? group.value : [];

            if (!children.length) {
                $body.append(
                    $('<div>')
                        .addClass('text-muted small automation-filter-empty')
                        .text(this.translate('entityFilterEmpty', 'messages', 'Automation'))
                );
            }

            children.forEach((node, index) => {
                const childPath = path === '' ? String(index) : path + '.' + index;
                $body.append(
                    node.type === 'and' || node.type === 'or'
                        ? this.buildGroup(node, childPath, false)
                        : this.buildLeaf(node, childPath)
                );
            });

            const $footer = $('<div>').addClass('automation-filter-group-actions');
            $footer.append(
                $('<button>')
                    .attr({type: 'button', 'data-action': 'addGroupCondition', 'data-path': path})
                    .addClass('btn btn-default btn-xs')
                    .html('<span class="fas fa-plus"></span> ' + this.escapeString(
                        this.translate('addCondition', 'labels', 'Automation')
                    ))
            );
            $footer.append(
                $('<button>')
                    .attr({type: 'button', 'data-action': 'addNestedGroup', 'data-path': path})
                    .addClass('btn btn-link btn-xs')
                    .html('<span class="fas fa-folder-plus"></span> ' + this.escapeString(
                        this.translate('addConditionGroup', 'labels', 'Automation')
                    ))
            );
            $body.append($footer);

            if (!isRoot) {
                $group.append($header).append($body);

                return $group;
            }

            return $('<div>').append($body);
        },

        buildLeaf: function (leaf, path) {
            const $row = $('<div>').addClass('automation-filter-leaf row').attr('data-path', path);
            const $attrColumn = $('<div>').addClass('col-sm-5');
            const $operatorColumn = $('<div>').addClass('col-sm-3');
            const $valueColumn = $('<div>').addClass('col-sm-3');
            const $removeColumn = $('<div>').addClass('col-sm-1');
            const $attribute = $('<select>').addClass('form-control input-sm automation-filter-attribute');
            const $operator = $('<select>').addClass('form-control input-sm automation-filter-operator');
            const $value = $('<input>').attr('type', 'text').addClass('form-control input-sm automation-filter-value');

            $attribute.append($('<option>').val('').text('—'));
            this._attributeOptions.forEach((option) => {
                const $option = $('<option>').val(option.value).text(option.label);

                if (option.value === leaf.attribute) {
                    $option.prop('selected', true);
                }

                $attribute.append($option);
            });

            if (leaf.attribute && !this._attributeOptions.some((option) => option.value === leaf.attribute)) {
                $attribute.append(
                    $('<option>').val(leaf.attribute).text(leaf.attribute + ' *').prop('selected', true)
                );
            }

            this.cfHelper.whereOperators().forEach((operator) => {
                const $option = $('<option>').val(operator).text(this.operatorLabel(operator));

                if (operator === leaf.type) {
                    $option.prop('selected', true);
                }

                $operator.append($option);
            });

            const needsValue = this.cfHelper.operatorNeedsValue(leaf.type);
            $value
                .prop('disabled', !needsValue)
                .val(this.valueForDisplay(leaf.value));

            const sync = () => {
                leaf.attribute = $attribute.val() || '';
                leaf.type = $operator.val() || 'equals';

                if (this.cfHelper.operatorNeedsValue(leaf.type)) {
                    leaf.value = this.cfHelper.coerceValue($value.val(), leaf.type);
                    $value.prop('disabled', false);
                } else {
                    delete leaf.value;
                    $value.prop('disabled', true).val('');
                }

                this.onChanged();
            };

            $attribute.on('change', sync);
            $operator.on('change', sync);
            $value.on('input change', sync);

            $attrColumn.append($attribute);
            $operatorColumn.append($operator);
            $valueColumn.append($value);
            $removeColumn.append(
                $('<button>')
                    .attr({type: 'button', 'data-action': 'removeNode', 'data-path': path})
                    .addClass('btn btn-default btn-sm')
                    .html('<span class="fas fa-times"></span>')
            );

            return $row
                .append($attrColumn)
                .append($operatorColumn)
                .append($valueColumn)
                .append($removeColumn);
        },

        valueForDisplay: function (value) {
            if (Array.isArray(value)) {
                return value.join(', ');
            }

            return value === undefined || value === null ? '' : String(value);
        },

        onChanged: function () {
            this._dirty = true;
            this._rawDirty = false;

            if (this._advancedOpen && this.$raw) {
                this.$raw.val(JSON.stringify(this._tree, null, 2));
            }

            this.trigger('change');
        },

        cleanNode: function (node, root) {
            if (!node || typeof node !== 'object') {
                return null;
            }

            if (node.type === 'and' || node.type === 'or') {
                const children = (node.value || [])
                    .map((child) => this.cleanNode(child, false))
                    .filter(Boolean);

                if (!root && !children.length) {
                    return null;
                }

                return {
                    type: node.type,
                    value: children,
                };
            }

            if (!node.attribute) {
                return null;
            }

            const leaf = {
                type: node.type || 'equals',
                attribute: node.attribute,
            };

            if (this.cfHelper.operatorNeedsValue(leaf.type)) {
                leaf.value = node.value;
            }

            return leaf;
        },

        fetch: function () {
            const data = {};

            if (this._rawDirty && this._parseError) {
                data[this.name] = this.model.get(this.name);

                return data;
            }

            if (this._legacyValue && !this._dirty) {
                data[this.name] = this._legacyValue;

                return data;
            }

            const tree = this.cleanNode(this._tree, true);
            data[this.name] = tree && this.hasRules(tree) ? tree : null;

            return data;
        },

        validate: function () {
            if (this._rawDirty && this._parseError) {
                this.showValidationMessage(this.translate('jsonParseError', 'messages', 'Automation'));

                return true;
            }

            return false;
        },

        summarize: function (tree) {
            if (!this.hasRules(tree)) {
                return '';
            }

            return (tree.type === 'or' ? 'OR' : 'AND') + ' (' + (tree.value || []).length + ')';
        },

        renderSummaryHtml: function (tree) {
            if (!this.hasRules(tree)) {
                return '';
            }

            return '<div class="automation-filter-summary">' + this.renderSummaryNode(tree) + '</div>';
        },

        renderSummaryNode: function (node) {
            if (node.type === 'and' || node.type === 'or') {
                const op = node.type === 'or' ? ' OR ' : ' AND ';
                const children = (node.value || [])
                    .map((child) => this.renderSummaryNode(child))
                    .filter(Boolean);

                return children.length > 1 ? '(' + children.join(op) + ')' : (children[0] || '');
            }

            if (!node.attribute) {
                return '';
            }

            const value = node.value === undefined ? '' : ' ' + this.valueForDisplay(node.value);

            return this.escapeString(
                node.attribute + ' ' + this.operatorLabel(node.type || 'equals') + value
            );
        },

        operatorLabel: function (operator) {
            const key = 'op_' + String(operator || 'equals');
            const translated = this.translate(key, 'labels', 'Automation');

            if (translated && translated !== key) {
                return translated;
            }

            return String(operator || 'equals')
                .replace(/([A-Z])/g, ' $1')
                .replace(/^./, (s) => s.toUpperCase())
                .trim();
        },
    });
});
