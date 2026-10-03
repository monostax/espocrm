const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const transpileCustomModule = require('../../js/custom-module-transpiler');

function fixture(t, source) {
    const directory = fs.mkdtempSync('/tmp/opencode/custom-module-transpiler-');
    t.after(() => fs.rmSync(directory, {recursive: true, force: true}));
    fs.mkdirSync(path.join(directory, 'src/handlers'), {recursive: true});
    const file = path.join(directory, 'src/handlers/record-detail.js');
    fs.writeFileSync(file, source);
    const result = transpileCustomModule({path: directory, destDir: path.join(directory, 'lib/transpiled')});
    const output = fs.readFileSync(path.join(directory, 'lib/transpiled/src/handlers/record-detail.js'), 'utf8');
    return {file, result, output};
}

test('first-line default exports become executable AMD handlers', t => {
    const {file, result, output} = fixture(t, 'export default class { process() { return "ready"; } }\n');
    let Handler;
    vm.runInNewContext(output, {define(id, dependencies, factory) {
        assert.equal(id, 'handlers/record-detail');
        const exports = {};
        factory(exports);
        Handler = exports.default;
    }});
    assert.equal(new Handler().process(), 'ready');
    assert.deepEqual(result.transpiled, [file]);
    assert.deepEqual(result.copied, []);
});

test('import-only modules execute their dependency through AMD', t => {
    const {file, result, output} = fixture(t, "import {install} from 'helper';\ninstall();\n");
    let installed = false;
    vm.runInNewContext(output, {define(id, dependencies, factory) {
        assert.equal(id, 'handlers/record-detail');
        assert.deepEqual(Array.from(dependencies), ['helper']);
        factory({install() { installed = true; }});
    }});
    assert.equal(installed, true);
    assert.deepEqual(result.transpiled, [file]);
});

test('handwritten AMD is preserved even when a comment contains export syntax', t => {
    const source = "/*\nexport default example\n*/\ndefine('custom:handler', [], () => 'ready');\n";
    const {file, result, output} = fixture(t, source);
    assert.equal(output, source);
    assert.deepEqual(result.copied, [file]);
    assert.deepEqual(result.transpiled, []);
});
