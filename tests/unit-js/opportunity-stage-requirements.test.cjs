const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const {EventEmitter} = require('node:events');

const source = readFileSync(path.join(__dirname,
    '../../client/custom/modules/global/src/helpers/opportunity-stage-requirements.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

function fixture({canEdit = true, code = 'opportunityStageRequirements', isNew = false} = {}) {
    const xhr = {responseText: JSON.stringify({code, canEdit, stageName: 'Qualified',
        fields: [{valueKey: 'budget', type: 'int'}], snapshot: {visitId: 'original'}})};
    let helper;
    const calls = [];
    const messages = [];
    vm.runInNewContext(source, {
        define: (name, deps, factory) => { helper = factory(); },
        Espo: {
            Utils: {clone: structuredClone},
            Ui: {error: message => messages.push(message)},
            Ajax: {getRequest: async () => ({customFields: {existing: 'latest'}, name: 'Deal'})},
        },
    });
    const dialog = new EventEmitter();
    dialog.render = () => {};
    let dialogOptions;
    const host = {
        translate: key => key,
        getAcl: () => ({getScopeForbiddenFieldList: () => []}),
        createView: async (name, type, options) => { dialogOptions = options; return dialog; },
    };
    const model = {
        id: 'deal',
        isNew: () => isNew,
        get: name => name === 'customFields' ? {existing: 'stale'} : 'Deal',
        save: async (values, options) => {
            calls.push(structuredClone(values));
            if (calls.length === 1) throw xhr;
            options.success?.();
            return values;
        },
    };
    helper.install(host, model);
    return {helper, host, model, calls, xhr, messages, dialog, get options() { return dialogOptions; }};
}

test('completion resumes the same save with current bag values and a concurrency snapshot', async () => {
    const f = fixture();
    let successes = 0;
    const saved = f.model.save({opportunityStageId: 'qualified'}, {success: () => successes++});
    await flush();
    assert.equal(f.calls.length, 1);
    assert.equal(successes, 0);
    assert.equal(f.options.values.existing, 'latest');
    f.dialog.emit('complete', {...f.options.values, budget: 0, approved: false});
    const result = await saved;
    assert.equal(result.opportunityStageId, 'qualified');
    assert.equal(result.customFields.existing, 'latest');
    assert.equal(result.customFields.budget, 0);
    assert.equal(result.customFields.approved, false);
    assert.equal(result.stageRequirementsSnapshot.visitId, 'original');
    assert.equal(successes, 1);
});

test('creation and full edits preserve the submitted custom-field draft', async () => {
    const f = fixture({isNew: true});
    const saved = f.model.save({name: 'Unsaved', opportunityStageId: 'qualified', customFields: {draft: 'keep'}});
    await flush();
    assert.equal(f.options.values.draft, 'keep');
    f.dialog.emit('complete', {...f.options.values, budget: 10});
    assert.equal((await saved).name, 'Unsaved');
});

test('cancelling performs no second save and installing twice does not double-wrap', async () => {
    const f = fixture();
    f.helper.install(f.host, f.model);
    const saved = f.model.save({opportunityStageId: 'qualified'});
    const rejected = assert.rejects(saved, error => error === f.xhr);
    await flush();
    f.dialog.emit('remove');
    await rejected;
    assert.equal(f.calls.length, 1);
    assert.equal(f.xhr.errorIsHandled, true);
});

test('uneditable requirements and invalid configurations show actionable errors without retry', async () => {
    for (const options of [{canEdit: false}, {code: 'opportunityStageConfiguration'}]) {
        const f = fixture(options);
        await assert.rejects(f.model.save({opportunityStageId: 'qualified'}));
        assert.equal(f.calls.length, 1);
        assert.equal(f.messages.length, 1);
        assert.equal(f.options, undefined);
    }
});
