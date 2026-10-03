const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');
const root = path.resolve(__dirname, '../..');
function load(file, imports, globals = {}) {
    const exports = {};
    const code = ts.transpileModule(fs.readFileSync(path.join(root, file), 'utf8'), {
        compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, esModuleInterop: false},
    }).outputText;
    vm.runInNewContext(code, {exports, require: name => imports[name], ...globals});
    return exports.default;
}

test('Document Markdown source fetch does not trim or serialize an editor snapshot', () => {
    class Lexical { fetch() { throw new Error('Lossy editor path used.'); } }
    const Field = load('client/custom/modules/feature-document-pages/src/views/document/fields/body.js', {
        'feature-knowledge-base-editor:views/fields/lexical-body': {default: Lexical}, 'views/fields/base': {default: class {}},
        'feature-record-knowledge:content': {sourceValue: (textarea, original) => textarea.value === original.replace(/\r\n?/g, '\n') ? original : textarea.value},
    });
    const view = new Field();
    const source = '\n---\ntitle: source\n---\n<!-- comment -->\n| A | B |\n| - | - |\n  \n';
    Object.assign(view, {name: 'body', model: {get: name => name === 'bodyAuthoringMode' ? 'Markdown' : source},
        isEditMode: () => true, isRendered: () => true, el: {querySelector: () => ({value: source})}});
    assert.equal(view.fetch().body, source);
    assert.equal(view.fetch().bodyEditorState, null);
    assert.equal(view.fetch().bodyFormat, 'Markdown');
});

test('shared setup uses metadata scopes for custom quick-edit containers and terminal pages', () => {
    const Handler = load('client/custom/modules/feature-record-knowledge/src/handlers/record-detail.js', {});
    const hidden = [];
    const view = {model: {id: 'project1', entityType: 'CustomProject', get: () => null}, bottomView: null,
        getMetadata: () => ({get: () => ['CustomProject', 'Document']}), hidePanel: name => hidden.push(name)};
    new Handler(view).process();
    assert.equal(view.bottomView, 'views/record/edit-bottom');
    view.model.entityType = 'Document';
    view.model.get = () => 'CustomProject';
    new Handler(view).process();
    assert.deepEqual(hidden, ['overview']);
});
