const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const babel = require('@babel/core');

const base = path.resolve(__dirname, '../../client/custom/modules/feature-knowledge-base-editor/src/views/fields');
function load(name, imports) {
    const code = babel.transformSync(fs.readFileSync(path.join(base, name), 'utf8'), {
        presets: [['@babel/preset-env', {targets: {node: 'current'}, modules: 'commonjs'}]],
    }).code;
    const module = {exports: {}};
    vm.runInNewContext('(function(require, module, exports) {' + code + '\n})', {window: {location: {origin: 'https://crm.test', pathname: '/'}}})(
        name => imports[name], module, module.exports,
    );
    return module.exports.default;
}
const Body = load('lexical-body.js', {'views/fields/base': class {}});
const Prompt = load('ai-prompt.js', {'feature-knowledge-base-editor:views/fields/lexical-body': Body});

function field(Class, name, values = {}) {
    const instance = new Class();
    instance.name = name;
    instance.model = {get: key => values[key]};
    instance.isEditMode = () => true;
    instance.kbEditor = {
        getHtml: () => '<p>Prompt</p>', getMarkdown: () => 'Prompt',
        getEditorStateJSON: () => '{"root":{"children":[]}}', isEmpty: () => false,
    };
    return instance;
}

test('prompt save submits only prompt attributes and always uses HTML', () => {
    const prompt = field(Prompt, 'aiPrompt', {bodyFormat: 'Markdown'});
    assert.deepEqual([...prompt.getAttributeList()], ['aiPrompt', 'aiPromptEditorState']);
    const result = prompt.fetch();
    assert.deepEqual(Object.keys(result).sort(), ['aiPrompt', 'aiPromptEditorState']);
    assert.equal(result.aiPrompt, '<p>Prompt</p>');
});

test('articles keep their configurable Markdown projection and canonical JSON', () => {
    const article = field(Body, 'body', {bodyFormat: 'Markdown'});
    const result = article.fetch();
    assert.equal(result.body, 'Prompt');
    assert.equal(result.bodyFormat, 'Markdown');
    assert.equal(result.bodyEditorState, '{"root":{"children":[]}}');
});

test('saving source imports it before collecting canonical state, including empty values', () => {
    const prompt = field(Prompt, 'aiPrompt');
    prompt.sourceMode = true;
    prompt.$source = {val: () => ''};
    let imported = false;
    prompt.kbEditor.setHtml = value => { assert.equal(value, ''); imported = true; };
    prompt.kbEditor.getEditorStateJSON = () => { assert.equal(imported, true); return '{}'; };
    const result = prompt.fetch();
    assert.equal(result.aiPrompt, null);
    assert.equal(result.aiPromptEditorState, null);
});

test('invalid canonical state falls back to the latest field projection', () => {
    const prompt = field(Prompt, 'aiPrompt', {aiPromptEditorState: 'invalid', aiPrompt: '<p>New API value</p>'});
    let loaded;
    prompt.kbEditor.setEditorStateJSON = () => false;
    prompt.kbEditor.setHtml = value => { loaded = value; };
    prompt.loadContentIntoEditor();
    assert.equal(loaded, '<p>New API value</p>');
});
