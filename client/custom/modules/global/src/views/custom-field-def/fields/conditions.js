define('global:views/custom-field-def/fields/conditions', ['views/fields/base', 'global:helpers/custom-field-conditions'], (Dep, Conditions) => Dep.extend({
    detailTemplateContent: '<div class="condition-editor"></div>',
    editTemplateContent: '<div class="condition-editor"></div>',

    setup() {
        Dep.prototype.setup.call(this);
        this.condition = Espo.Utils.cloneDeep(this.model.get(this.name) || null);
        this.choices = {};
        this.linkNames = {};
        this.wait(this.loadChoices());
        this.listenTo(this.model, 'change:entityType change:tenantId', () => {
            this.loadChoices().then(() => this.reRender());
        });
    },

    async loadChoices() {
        this.choiceRequest = (this.choiceRequest || 0) + 1;
        const request = this.choiceRequest;
        const scope = this.model.get('entityType');
        const {attributes} = await Espo.Ajax.getRequest('CustomField/action/conditionAttributes', {entityType: scope});
        if (request !== this.choiceRequest) return;
        this.choices = attributes;
        const requests = new Map();
        for (const leaf of Conditions.leaves(this.condition)) {
            const descriptor = attributes[leaf.attribute];
            if (descriptor?.type !== 'link' || leaf.value == null) continue;
            for (const id of Array.isArray(leaf.value) ? leaf.value : [leaf.value]) {
                const key = `${descriptor.entity}/${id}`;
                if (this.linkNames[key] || requests.has(key)) continue;
                requests.set(key, Espo.Ajax.getRequest(`${descriptor.entity}/${encodeURIComponent(id)}`)
                    .then(record => { this.linkNames[key] = this.recordLabel(record); })
                    .catch(() => { this.linkNames[key] = id; }));
            }
        }
        await Promise.all(requests.values());
    },

    afterRender() {
        const container = this.$el.find('.condition-editor').empty();
        const editable = this.isEditMode() && !this.readOnly;
        if (this.condition) {
            this.renderNode(container, this.condition, value => { this.condition = value; }, editable, 0);
        } else {
            container.append($('<span class="text-muted">').text(this.translate(
                this.name === 'appliesWhen' ? 'allRecords' : 'noConditionalRequirement', 'labels', 'CustomFieldDef')));
            if (editable && Object.keys(this.choices).length) {
                container.append(this.button('addCondition', () => {
                    this.condition = { all: [this.newLeaf()] };
                    this.changed();
                }));
            }
        }
    },

    newLeaf(attribute = Object.keys(this.choices)[0], operator = this.choices[attribute]?.operators[0]) {
        return ['equals', 'in', 'containsAny', 'containsAll'].includes(operator)
            ? {attribute, operator, value: operator === 'equals' ? '' : []}
            : {attribute, operator};
    },

    button(label, action) {
        return $('<button type="button" class="btn btn-default btn-sm" style="margin: 4px;">')
            .text(this.translate(label, 'labels', 'CustomFieldDef')).on('click', action);
    },

    changed(render = true) {
        this.trigger('change');
        if (render) this.reRender();
    },

    renderNode(container, node, replace, editable, depth) {
        const row = $('<div style="margin: 6px 0; padding-left: 12px; border-left: 2px solid #ddd;">').appendTo(container);
        const group = node.all ? 'all' : node.any ? 'any' : null;
        if (group) {
            const mode = $('<select class="form-control input-sm" style="width: auto; display: inline-block;">');
            for (const value of ['all', 'any']) mode.append($('<option>').val(value).text(this.translate(value, 'labels', 'CustomFieldDef')));
            mode.val(group).prop('disabled', !editable).appendTo(row).on('change', () => {
                replace({ [mode.val()]: node[group] }); this.changed();
            });
            node[group].forEach((child, index) => { this.renderNode(row, child, value => {
                if (value === null) node[group].splice(index, 1);
                else node[group][index] = value;
                if (!node[group].length) replace(null);
            }, editable, depth + 1); });
            if (editable && node[group].length < 20) {
                row.append(this.button('addCondition', () => { node[group].push(this.newLeaf()); this.changed(); }));
                if (depth < 3) row.append(this.button('addGroup', () => {
                    node[group].push({ all: [this.newLeaf()] }); this.changed();
                }));
            }
        } else {
            const scope = this.model.get('entityType');
            const descriptor = this.choices[node.attribute];
            const attribute = $('<select class="form-control input-sm" style="width: auto; display: inline-block;">');
            for (const key of new Set([...Object.keys(this.choices), node.attribute])) {
                attribute.append($('<option>').val(key).text(this.translate(this.choices[key]?.field || key, 'fields', scope)));
            }
            attribute.val(node.attribute).prop('disabled', !editable).appendTo(row).on('change', () => {
                replace(this.newLeaf(attribute.val())); this.changed();
            });
            const operator = $('<select class="form-control input-sm" style="width: auto; display: inline-block;">');
            for (const key of new Set([...(descriptor?.operators || []), node.operator])) {
                operator.append($('<option>').val(key).text(this.translate(key, 'labels', 'CustomFieldDef')));
            }
            operator.val(node.operator).prop('disabled', !editable).appendTo(row).on('change', () => {
                replace(this.newLeaf(node.attribute, operator.val())); this.changed();
            });
            if (node.value != null) this.renderValues(row, node, descriptor, replace, editable);
        }
        if (editable) row.append(this.button('removeCondition', () => { replace(null); this.changed(); }));
    },

    renderValues(row, node, descriptor, replace, editable) {
        const selected = node.operator === 'equals' ? [node.value].filter(Boolean) : node.value;
        const update = values => {
            replace({...node, value: node.operator === 'equals' ? values[0] || '' : values});
        };
        if (descriptor?.type === 'link') {
            for (const id of selected) {
                row.append($('<span class="label label-default" style="display: inline-block; margin: 4px;">')
                    .text(this.linkNames[`${descriptor.entity}/${id}`] || id));
                if (editable) row.append(this.button('removeCondition', () => {
                    update(selected.filter(value => value !== id)); this.changed();
                }));
            }
            if (editable && this.getAcl().checkScope(descriptor.entity, 'read')) {
                row.append(this.button('selectRecords', () => this.selectRecords(descriptor, node, selected, update)));
            }
            return;
        }
        if (descriptor?.allowCustomOptions && node.operator !== 'equals') {
            // Free-form tags remain strings, not record IDs or nested field paths.
            const input = $('<textarea class="form-control" rows="3">').val(selected.join('\n')).prop('disabled', !editable);
            row.append(input, $('<small class="text-muted">').text(this.translate('oneValuePerLine', 'labels', 'CustomFieldDef')));
            input.on('input', () => {
                update([...new Set(input.val().split('\n').map(value => value.trim()).filter(Boolean))]);
                this.changed(false);
            });
            return;
        }
        if (descriptor?.allowCustomOptions) {
            const input = $('<input type="text" class="form-control">').val(node.value).prop('disabled', !editable);
            input.appendTo(row).on('input', () => { update([input.val().trim()]); this.changed(false); });
            return;
        }
        const values = $('<select class="form-control input-sm" style="max-width: 500px;">')
            .prop('multiple', node.operator !== 'equals');
        const options = descriptor?.options || [];
        if (node.operator === 'equals') values.append($('<option>').val('').text(''));
        for (const id of new Set([...options, ...selected])) {
            values.append($('<option>').val(id).text(this.getLanguage().translateOption(id,
                descriptor?.field || node.attribute, this.model.get('entityType'))));
        }
        values.val(node.operator === 'equals' ? selected[0] || '' : selected).prop('disabled', !editable).appendTo(row)
            .on('change', () => {
                update(node.operator === 'equals' ? [values.val()] : values.val() || []);
                this.changed(false);
            });
    },

    recordLabel(record) {
        return record.funnelName ? `${record.funnelName} / ${record.name}` : record.name || record.id;
    },

    async selectRecords(descriptor, node, selected, update) {
        const filters = {};
        const tenantId = this.model.get('tenantId');
        if (tenantId && descriptor.tenantScoped && descriptor.entity !== 'OpportunityStage') {
            filters.tenant = {type: 'equals', attribute: 'tenantId', value: tenantId,
                data: {type: 'is', id: tenantId, name: this.model.get('tenantName') || tenantId}};
        }
        const view = await this.createView('conditionRecordPicker', 'views/modals/select-records', {
            scope: descriptor.entity, multiple: node.operator !== 'equals', createButton: false, filters,
        });
        this.listenToOnce(view, 'select', models => {
            if (!Array.isArray(models)) models = [models];
            for (const model of models) this.linkNames[`${descriptor.entity}/${model.id}`] = this.recordLabel(model.attributes);
            update(node.operator === 'equals' ? models.map(model => model.id)
                : [...new Set([...selected, ...models.map(model => model.id)])]);
            view.close();
            this.changed();
        });
        view.render();
    },

    fetch() {
        return { [this.name]: Espo.Utils.cloneDeep(this.condition) };
    },
}));
