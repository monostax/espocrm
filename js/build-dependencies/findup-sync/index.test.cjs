'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const findup = require('./index');

test('Grunt/liftup ancestor lookup supports literals, ordered patterns and globs', t => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'crm-findup-'));
    t.after(() => fs.rmSync(root, {recursive: true, force: true}));
    const cwd = path.join(root, 'nested', 'child');
    fs.mkdirSync(cwd, {recursive: true});
    fs.writeFileSync(path.join(root, 'Gruntfile.js'), '');
    fs.writeFileSync(path.join(root, '.hidden.js'), '');
    fs.writeFileSync(path.join(root, 'nested', 'Gruntfile.cjs'), '');

    assert.equal(findup('Gruntfile.js', {cwd}), path.join(root, 'Gruntfile.js'));
    assert.equal(findup(['Gruntfile.js', 'Gruntfile.cjs'], {cwd}), path.join(root, 'nested', 'Gruntfile.cjs'));
    assert.equal(findup('Gruntfile.{js,cjs}', {cwd}), path.join(root, 'nested', 'Gruntfile.cjs'));
    assert.equal(findup('gruntfile.*', {cwd, nocase: true}), path.join(root, 'nested', 'Gruntfile.cjs'));
    assert.equal(findup('.*.js', {cwd, dot: true}), path.join(root, '.hidden.js'));
    assert.equal(findup('nonexistent-crm-build-file', {cwd}), null);
    assert.throws(() => findup(42), TypeError);
});
