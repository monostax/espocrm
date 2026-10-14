const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const ts = require('typescript');
const root = path.resolve(__dirname, '../..');
const prefix = 'client/custom/modules/';
const read = file => readFileSync(path.join(root, file), 'utf8');
const plain = value => JSON.parse(JSON.stringify(value));
const base = {prototype: {data() {return {};}, setup() {}}, extend: value => value};
function load(file, dependencies = {}, globals = {}) {
    let result;
    vm.runInNewContext(read(prefix + file), {define: (name, names, fn) => { result = fn(...names.map(n => dependencies[n])); },
        URL, FormData, console, ...globals});
    return result;
}
const definition = load('feature-meta-whatsapp-business/src/template-definition.js');

test('catalog consumes all pages, deduplicates IDs and reports progress without exposing partial results', async () => {
    const calls = []; const progress = [];
    const catalog = load('feature-meta-whatsapp-business/src/template-catalog.js', {}, {Espo: {Ajax: {getRequest: async (url, params) => {
        calls.push(params);
        return params.cursor ? {list: [{id: 'a'}, {id: 'b'}], hasMore: false, nextCursor: null} :
            {list: [{id: 'a'}], hasMore: true, nextCursor: 'next'};
    }}}});
    assert.deepEqual(plain(await catalog.load({credentialId: 'connection'}, (page, count) => progress.push([page, count]))),
        {list: [{id: 'a'}, {id: 'b'}], total: 2});
    assert.deepEqual(progress, [[1, 1], [2, 2]]);
    assert.equal(calls[1].credentialId, 'connection');
});

test('catalog fails on second-page errors and repeated cursors rather than returning a truncated catalog', async () => {
    for (const repeat of [false, true]) {
        let calls = 0;
        const catalog = load('feature-meta-whatsapp-business/src/template-catalog.js', {}, {Espo: {Ajax: {getRequest: async () => {
            if (++calls > 1 && !repeat) throw new Error('dependency');
            return {list: [{id: 'a'}], hasMore: true, nextCursor: 'same'};
        }}}});
        await assert.rejects(catalog.load({}), repeat ? /catalogIncomplete/ : /dependency/);
        assert.equal(calls, 2);
    }
});

test('header and body variables are independent and duplicate occurrences do not create duplicate mappings', () => {
    const picker = load('chatwoot/src/views/whatsapp-campaign/fields/template-name.js', {'views/fields/varchar': base});
    const attrs = {parameterMapping: {'1': '{{order}}'}, headerParameterMapping: {'1': '{{firstName}}'}};
    const view = Object.assign(Object.create(picker), {model: {get: key => attrs[key]},
        templateComponentsMap: {'same::pt_BR': [{type: 'HEADER', format: 'TEXT', text: 'Hi {{1}}'},
            {type: 'BODY', text: 'Order {{1}}, again {{1}}'}]}});
    view.parseTemplateParameters('same::pt_BR');
    assert.equal(view.currentTemplateParams.length, 2);
    assert.equal(view.currentTemplateParams.find(p => p.componentType === 'HEADER').currentValue, '{{firstName}}');
    assert.equal(view.currentTemplateParams.find(p => p.componentType === 'BODY').currentValue, '{{order}}');
    attrs.headerParameterMapping = null;
    view.parseTemplateParameters('same::pt_BR');
    assert.equal(view.currentTemplateParams.find(p => p.componentType === 'HEADER').currentValue, '');
});

test('picker save during loading, failure or a missing saved template retains both mappings and identity', () => {
    const picker = load('chatwoot/src/views/whatsapp-campaign/fields/template-name.js', {'views/fields/varchar': base});
    const attrs = {templateName: 'saved', templateLanguage: 'pt_BR', parameterMapping: {'1': 'body'},
        headerParameterMapping: {'1': 'header'}, templateHeader: 'Hi {{1}}'};
    for (const state of [{isLoading: true}, {loadError: 'failed'}, {}]) {
        const view = Object.assign(Object.create(picker), {name: 'templateName', model: {get: key => attrs[key]},
            templateOptions: [{name: 'other', language: 'pt_BR'}]}, state);
        const fetched = view.fetch();
        assert.equal(fetched.templateName, 'saved');
        assert.equal(fetched.templateLanguage, 'pt_BR');
        assert.deepEqual(fetched.headerParameterMapping, {'1': 'header'});
        assert.deepEqual(fetched.parameterMapping, {'1': 'body'});
    }
});

test('picker exposes catalog failure and recovers without losing the saved selection', async () => {
    const catalog = {load: () => Promise.reject(new Error('page two failed'))};
    const picker = load('chatwoot/src/views/whatsapp-campaign/fields/template-name.js', {
        'views/fields/varchar': base, 'feature-meta-whatsapp-business:template-catalog': catalog,
    });
    const attrs = {templateName: 'saved', templateLanguage: 'pt_BR', parameterMapping: {'1': 'body'},
        headerParameterMapping: {'1': 'header'}};
    const view = Object.assign(Object.create(picker), {name: 'templateName', model: {get: key => attrs[key]},
        reRender() {}, templateOptions: [{name: 'stale', language: 'en_US'}]});
    view.fetchTemplates({});
    await new Promise(setImmediate);
    assert.equal(view.isLoading, false);
    assert.equal(view.loadError, 'Failed to load templates.');
    assert.equal(view.templateOptions.length, 0);
    assert.equal(view.fetch().templateName, 'saved');
    assert.deepEqual(view.fetch().headerParameterMapping, {'1': 'header'});
    catalog.load = async () => ({list: [{name: 'available', language: 'pt_BR', status: 'APPROVED'}]});
    view.fetchTemplates({});
    await new Promise(setImmediate);
    assert.equal(view.loadError, null);
    assert.equal(view.templateOptions[0].name, 'available');
});

test('stale credential resolution cannot overwrite the active picker request', async () => {
    const calls = [];
    const picker = load('chatwoot/src/views/whatsapp-campaign/fields/template-name.js', {'views/fields/varchar': base}, {
        Espo: {Ajax: {getRequest: () => {
            let reject;
            const promise = new Promise((_, fail) => {reject = fail;});
            promise.abort = () => reject({statusText: 'abort'});
            calls.push({promise, reject});
            return promise;
        }}},
    });
    const view = Object.assign(Object.create(picker), {reRender() {}});
    view.resolveCredentialAndFetch('first');
    view.resolveCredentialAndFetch('second');
    await new Promise(setImmediate);
    assert.equal(view.currentRequest, calls[1].promise);
    assert.equal(view.isLoading, true);
    assert.equal(view.loadError, null);
    calls[1].reject({responseJSON: {message: 'Current credential inaccessible.'}});
    await new Promise(setImmediate);
    assert.equal(view.isLoading, false);
    assert.equal(view.loadError, 'Current credential inaccessible.');
});

test('editor validates positional examples, media boundaries and button grouping', () => {
    const d = {name: 'example', language: 'pt_BR', category: 'UTILITY', components: [
        {type: 'HEADER', format: 'TEXT', text: '{{1}}', example: {header_text: ['header']}},
        {type: 'BODY', text: '{{1}}', example: {body_text: [['body']]}},
    ]};
    definition.validate(d, null);
    d.components[0].text = '{{2}}';
    assert.throws(() => definition.validate(d, null), /invalidVariables/);
    d.components[0] = {type: 'HEADER', format: 'IMAGE'};
    definition.validate(d, {type: 'image/png', size: 5000000});
    assert.throws(() => definition.validate(d, {type: 'image/png', size: 5000001}), /invalidSample/);
    d.components.push({type: 'BUTTONS', buttons: [
        {type: 'QUICK_REPLY', text: 'One'}, {type: 'URL', text: 'Two', url: 'https://example.com'}, {type: 'QUICK_REPLY', text: 'Three'},
    ]});
    assert.throws(() => definition.validate(d, {type: 'image/png', size: 1}), /invalidButtons/);
});

test('journey serialization metadata retains additive header fields; generic template CRUD stays disabled', () => {
    const actions = JSON.parse(read('custom/Espo/Modules/FeatureJourney/Resources/metadata/app/journeyActionTypes.json'));
    const values = Object.values(actions).flatMap(v => Object.values(v));
    const send = values.find(v => v?.implementationClassName?.endsWith('SendWhatsAppTemplate'));
    const keys = send.paramDefs.find(p => p.type === 'whatsappTemplate').managedKeys;
    assert.ok(keys.includes('headerParameterMapping')); assert.ok(keys.includes('templateHeader')); assert.ok(keys.includes('parameterMapping'));
    for (const kind of ['clientDefs', 'recordDefs']) {
        const defs = JSON.parse(read(`custom/Espo/Modules/FeatureMetaWhatsAppBusiness/Resources/metadata/${kind}/WhatsAppBusinessAccountMessageTemplate.json`));
        assert.equal(defs.createDisabled, true); assert.equal(defs.editDisabled, true); assert.equal(defs.deleteDisabled, true);
    }
});

test('journey form serialization preserves independent header/body mappings through save and reload', () => {
    const params = load('feature-journey/src/views/journey-stage-action/fields/params.js', {
        'views/fields/base': base, 'views/fields/json-object': base,
    });
    const saved = {templateName: 'order', templateLanguage: 'pt_BR', templateHeader: 'Hi {{1}}',
        templateBody: 'Order {{1}}', parameterMapping: {'1': '{{order}}'}, headerParameterMapping: {'1': '{{firstName}}'}};
    const keys = Object.keys(saved);
    const view = Object.assign(Object.create(params), {
        getTypeMeta: () => ({paramDefs: [{name: 'templateName', type: 'whatsappTemplate', managedKeys: keys}]}),
        getParamsObject: () => saved, getParamFormulas: () => ({}), getView: () => ({fetch: () => saved}),
    });
    const serialized = plain(view.fetchFromForm());
    assert.deepEqual(serialized.parameterMapping, saved.parameterMapping);
    assert.deepEqual(serialized.headerParameterMapping, saved.headerParameterMapping);
    const reload = Object.assign(Object.create(params), {
        getTypeMeta: view.getTypeMeta, getParamsObject: () => serialized, getParamFormulas: () => ({}),
        getView: () => null, _helperModel: {get: key => serialized[key]},
    });
    assert.deepEqual(plain(reload.fetchFromForm()), serialized);
});

test('native Ajax POST preserves multipart bytes and browser-generated boundary while retaining JSON behavior', async () => {
    const sent = [];
    class Xhr {
        constructor() {this.headers = {}; this.status = 200; this.responseText = '{}';}
        open(method, url) {this.method = method; this.url = url;}
        setRequestHeader(key, value) {this.headers[key] = value;}
        send(body) {sent.push({body, headers: this.headers}); this.onload();}
    }
    const imports = {jquery: {}, utils: {obtainBaseUrl: () => 'https://crm.test/', trimSlash: x => x},
        'util/ajax': {Xhr, AjaxPromise: Promise}};
    const exports = {};
    vm.runInNewContext(ts.transpileModule(read('client/src/ajax.ts'), {compilerOptions: {module: ts.ModuleKind.CommonJS}}).outputText,
        {exports, require: id => imports[id], URL, FormData, Espo: {}});
    const ajax = exports.default;
    ajax.configure({apiUrl: 'api/v1', timeout: 125000, onSuccess() {}, beforeSend() {}});
    const data = new FormData(); data.append('payload', '{}'); data.append('sample', new Blob(['sample']), 'sample.pdf');
    await ajax.postRequest('Template/action/create', data);
    assert.equal(sent[0].body, data);
    assert.equal(sent[0].headers['Content-Type'], undefined);
    await ajax.postRequest('Template/action/create', {definition: 'text'});
    assert.equal(sent[1].headers['Content-Type'], 'application/json');
    assert.equal(sent[1].body, '{"definition":"text"}');
});
