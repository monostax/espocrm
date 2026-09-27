const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const ts = require('typescript');

const filename = path.join(__dirname, '../../client/src/helpers/list/misc/sticky-bar.js');
const {outputText} = ts.transpileModule(readFileSync(filename, 'utf8'), {
    compilerOptions: {target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS},
});

function createHelper({
    hasNavbar = true,
    navbarVisible = true,
    buttonsTop = 72,
    force = false,
    isSmallWindow = false,
    isModal = false,
} = {}) {
    const exports = {};
    vm.runInNewContext(outputText, {
        exports,
        require(name) {
            if (name === 'jquery') {
                return {__esModule: true, default: () => ({hasClass: () => false})};
            }
            assert.equal(name, 'bullbone');
            return {Events: {}};
        },
        document: {body: {classList: {contains: () => hasNavbar}}},
    }, {filename});

    const helper = Object.create(exports.default.prototype);
    const element = offsetTop => ({offsetTop, classList: {contains: () => false}});
    let hidden = true;
    let scrollTop = 0;
    Object.assign(helper, {
        view: {toShowStickyBar: () => true},
        force,
        isSmallWindow,
        isModal,
        $el: {find: () => ({get: () => element(buttonsTop)})},
        $middle: {get: () => element(96), outerHeight: () => 400},
        $scrollable: {scrollTop: () => scrollTop},
        $bar: {
            addClass: () => { hidden = true; },
            removeClass: () => { hidden = false; },
        },
        $navbarRight: {
            is: () => navbarVisible,
            outerHeight: () => 48,
            addClass() {},
            removeClass() {},
        },
    });

    return {
        helper,
        isHiddenAt(top) {
            scrollTop = top;
            helper._controlSticking();
            return hidden;
        },
    };
}

test('a headerless embed keeps its floating controls hidden until scrolling past the toolbar', () => {
    for (const navbar of [
        {hasNavbar: true, navbarVisible: false},
        {hasNavbar: false, navbarVisible: true},
    ]) {
        const {isHiddenAt} = createHelper({...navbar, buttonsTop: 8});
        assert.equal(isHiddenAt(0), true);
        assert.equal(isHiddenAt(3), true);
        assert.equal(isHiddenAt(4), false);
        assert.equal(isHiddenAt(491), true);
    }
});

test('the visible 48px navbar determines the sticking threshold', () => {
    const {isHiddenAt} = createHelper();
    assert.equal(isHiddenAt(19), true);
    assert.equal(isHiddenAt(20), false);
    assert.equal(isHiddenAt(443), true);
});

test('a toolbar at the top of an embed does not stick before the user scrolls', () => {
    const {isHiddenAt} = createHelper({hasNavbar: false, buttonsTop: 0});
    assert.equal(isHiddenAt(0), true);
    assert.equal(isHiddenAt(1), false);
});

test('explicitly forced sticky controls remain visible without scrolling', () => {
    const {isHiddenAt} = createHelper({force: true});
    assert.equal(isHiddenAt(0), false);
});

test('mobile and modal lists do not subtract the desktop navbar height', () => {
    assert.equal(createHelper({isSmallWindow: true}).helper._getButtonsTop(), 67);
    assert.equal(createHelper({isModal: true}).helper._getButtonsTop(), 72);
});
