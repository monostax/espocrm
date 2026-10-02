// NODE_PATH=/path/to/playwright/node_modules node --test tests/editor/notion-editor.cjs
const {test, before, after} = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require('playwright');
let browser;
before(async () => { browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH}); });
after(async () => { await browser?.close(); });

async function fixture(t, options = {}) {
    const page = await browser.newPage(options);
    page.setDefaultTimeout(8000);
    t.after(() => page.close());
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    t.after(() => assert.deepEqual(errors, []));
    await page.setContent('<div class="kb-lexical-editor-wrap"><div id="editor" class="kb-lexical-editor" contenteditable="true"></div></div>');
    const base = path.resolve(__dirname, '../../client/custom/modules/feature-knowledge-base-editor');
    await page.addStyleTag({path: `${base}/css/kb-lexical.css`});
    await page.addScriptTag({path: `${base}/lib/lexical-kb-bundle.js`});
    await page.evaluate(() => {
        window.kb = EspoLexical.createKbEditor({element: document.querySelector('#editor'), notion: true, locale: 'pt-BR',
            references: {
                search: async query => [{kind: 'record', entityType: 'Account', recordId: 'acme', label: 'Acme'}],
                resolve: async refs => refs.map(ref => ({...ref, available: ref.recordId !== 'deleted', label: 'Renamed Acme'})),
            }});
        kb.setHtml('<p>Hello world</p>');
    });
    await page.waitForSelector('.kb-notion-plugins', {state: 'attached'});
    return page;
}

test('HTML, Markdown and canonical state preserve atomic record/context references', async t => {
    const page = await fixture(t);
    const results = await page.evaluate(() => {
        kb.setHtml('<p>Ask <a href="#crm-reference/v1/record/Account/acme">Old name</a> about <a href="#crm-reference/v1/context/currentOpportunity">Current Opportunity</a>.</p>');
        const state = kb.getEditorStateJSON();
        const html = kb.getHtml();
        const markdown = kb.getMarkdown();
        kb.setMarkdown(markdown);
        const fromMarkdown = kb.getEditorStateJSON();
        kb.setHtml(html);
        const fromHtml = kb.getEditorStateJSON();
        return {state, html, markdown, fromMarkdown, fromHtml};
    });
    for (const key of ['state', 'fromMarkdown', 'fromHtml']) {
        const children = JSON.parse(results[key]).root.children[0].children;
        assert.equal(children.filter(node => node.type === 'crm-mention').length, 2, key);
        assert.equal(children.find(node => node.type === 'crm-mention').reference.recordId, 'acme');
    }
    assert.match(results.markdown, /#crm-reference\/v1\/record\/Account\/acme/);
    await page.waitForFunction(() => document.querySelector('.kb-mention')?.textContent === 'Renamed Acme');
    await page.evaluate(() => kb.setHtml('<p><a href="#crm-reference/v1/record/Account/deleted">Secret old name</a></p>'));
    assert.equal(await page.locator('.kb-mention').textContent(), 'Unavailable reference');
});

test('partial-word floating formatting retains selection and supports keyboard access, undo, source-mode hiding', async t => {
    const page = await fixture(t);
    await page.locator('#editor').focus();
    await page.evaluate(() => {
        const text = document.querySelector('#editor p span').firstChild;
        const selection = window.getSelection();
        selection.setBaseAndExtent(text, 1, text, 4);
        document.dispatchEvent(new Event('selectionchange'));
    });
    await page.getByRole('toolbar').waitFor();
    await page.getByRole('button', {name: 'bold', exact: true}).click();
    await page.waitForFunction(() => kb.getHtml().includes('<b>') || kb.getHtml().includes('<strong>'));
    const html = await page.evaluate(() => kb.getHtml());
    assert.match(html, /ell/);
    await page.getByRole('button', {name: 'italic', exact: true}).click();
    assert.match(await page.evaluate(() => kb.getHtml()), /font-style: italic|<i>|<em>/);
    await page.keyboard.press('Alt+F10');
    assert.equal(await page.evaluate(() => document.activeElement.getAttribute('aria-label')), 'bold');
    await page.keyboard.press('Escape');
    await page.evaluate(() => kb.setEditable(false));
    await page.getByRole('toolbar').waitFor({state: 'detached'});
    await page.evaluate(() => { kb.setEditable(true); kb.undo(); });
});

test('slash menu inserts headings and tables; mentions use the grouped picker', async t => {
    const page = await fixture(t);
    await page.evaluate(() => kb.setHtml(''));
    await page.locator('#editor').click();
    await page.keyboard.type('/heading 2');
    await page.getByRole('option').first().waitFor();
    await page.keyboard.press('Enter');
    await page.keyboard.type('Title');
    assert.equal(await page.locator('#editor h2').textContent(), 'Title');
    await page.keyboard.press('Enter');
    await page.keyboard.type('@Acme');
    await page.getByRole('option').filter({hasText: 'Acme'}).waitFor();
    await page.keyboard.press('Enter');
    await page.waitForSelector('.kb-mention');
    assert.match(await page.evaluate(() => kb.getEditorStateJSON()), /"recordId":"acme"/);
    await page.keyboard.press('Enter');
    await page.keyboard.type('/table');
    await page.getByRole('option').waitFor();
    await page.keyboard.press('Enter');
    await page.waitForSelector('#editor table');
    assert.equal(await page.locator('#editor table tr').count(), 3);
    const markdown = await page.evaluate(() => kb.getMarkdown());
    assert.match(markdown, /<table/);
    await page.evaluate(md => kb.setMarkdown(md), markdown);
    assert.equal(await page.locator('#editor table tr').count(), 3);
});

test('keyboard block reordering, backward multiline selection and undo preserve content', async t => {
    const page = await fixture(t);
    await page.evaluate(() => kb.setHtml('<p>First block</p><p>Second block</p>'));
    await page.locator('#editor').focus();
    await page.keyboard.press('Control+End');
    await page.keyboard.press('Alt+Shift+ArrowUp');
    assert.equal(await page.locator('#editor p').first().textContent(), 'Second block');
    await page.evaluate(() => {
        const nodes = document.querySelectorAll('#editor p span');
        window.getSelection().setBaseAndExtent(nodes[1].firstChild, 5, nodes[0].firstChild, 3);
        document.dispatchEvent(new Event('selectionchange'));
    });
    await page.getByRole('button', {name: 'bold', exact: true}).click();
    assert.match(await page.evaluate(() => kb.getHtml()), /<b>|<strong>/);
    await page.evaluate(() => kb.undo());
    assert.doesNotMatch(await page.evaluate(() => kb.getHtml()), /<b>|<strong>/);
    await page.evaluate(() => kb.redo());
    assert.match(await page.evaluate(() => kb.getHtml()), /<b>|<strong>/);
});

test('multiple editors isolate history and remove React portals on destroy', async t => {
    const page = await fixture(t);
    const result = await page.evaluate(() => {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = '<div contenteditable="true"></div>';
        document.body.appendChild(wrapper);
        const other = EspoLexical.createKbEditor({element: wrapper.firstChild, notion: true});
        other.setMarkdown('## Second editor');
        const first = kb.getHtml();
        const second = other.getHtml();
        other.destroy(); kb.destroy();
        return {first, second, hosts: document.querySelectorAll('.kb-notion-plugins').length};
    });
    assert.match(result.first, /Hello world/);
    assert.match(result.second, /Second editor/);
    assert.equal(result.hosts, 0);
    assert.equal(await page.locator('.kb-notion-popover').count(), 0);
});

test('late mention searches cannot replace the current query and ARIA targets are instance scoped', async t => {
    const page = await fixture(t);
    await page.evaluate(() => {
        kb.destroy();
        window.searches = [];
        kb = EspoLexical.createKbEditor({element: document.querySelector('#editor'), notion: true, references: {
            search: query => new Promise(resolve => searches.push({query, resolve})),
            resolve: async refs => refs.map(ref => ({...ref, available: true})),
        }});
        kb.setHtml('');
    });
    await page.locator('#editor').click();
    await page.keyboard.type('@Alpha');
    await page.waitForFunction(() => searches.some(item => item.query === 'Alpha'));
    await page.keyboard.press('Control+a');
    await page.keyboard.type('@Beta');
    await page.waitForFunction(() => searches.some(item => item.query === 'Beta'));
    await page.evaluate(() => searches.find(item => item.query === 'Beta').resolve([
        {kind: 'record', entityType: 'Contact', recordId: 'beta', label: 'Beta contact'},
    ]));
    await page.getByRole('option').filter({hasText: 'Beta contact'}).waitFor();
    await page.evaluate(() => searches.find(item => item.query === 'Alpha').resolve([
        {kind: 'record', entityType: 'Contact', recordId: 'alpha', label: 'Alpha contact'},
    ]));
    assert.equal(await page.getByRole('option').filter({hasText: 'Alpha contact'}).count(), 0);
    await page.keyboard.press('ArrowDown');
    assert.equal(await page.evaluate(() => {
        const id = document.querySelector('#editor').getAttribute('aria-activedescendant');
        return document.getElementById(id)?.getAttribute('role');
    }), 'option');
    await page.keyboard.press('Escape');
    await page.locator('.kb-command-menu').waitFor({state: 'detached'});
});

test('modal toolbar stays within viewport and sanitizer preserves portable identity', async t => {
    const page = await fixture(t);
    await page.setViewportSize({width: 375, height: 600});
    await page.addScriptTag({path: path.resolve(__dirname, '../../node_modules/dompurify/dist/purify.js')});
    await page.evaluate(() => {
        document.querySelector('.kb-lexical-editor-wrap').classList.add('modal');
        const clean = DOMPurify.sanitize('<p>Ask <a href="#crm-reference/v1/record/Account/acme">A [group] \\ name</a></p>');
        kb.setHtml(clean);
        const markdown = kb.getMarkdown();
        kb.setMarkdown(markdown);
        window.markdownRoundTrip = kb.getEditorStateJSON();
        kb.setHtml('<p>Some multiline text that will wrap in the narrow modal viewport near the edges.</p>');
    });
    assert.match(await page.evaluate(() => markdownRoundTrip), /"recordId":"acme"/);
    await page.locator('#editor').focus();
    await page.keyboard.press('Control+a');
    await page.getByRole('toolbar').waitFor();
    assert.equal(await page.locator('.modal .kb-selection-toolbar').count(), 1);
    const box = await page.getByRole('toolbar').boundingBox();
    assert.ok(box.x >= 0 && box.x + box.width <= 375);
    assert.ok(box.y >= 0 && box.y + box.height <= 600);
});

test('slash menu inserts a full-width localized date separator between editable blocks', async t => {
    const page = await fixture(t);
    await page.addStyleTag({path: path.resolve(__dirname, '../../client/css/tailwind.css')});
    await page.evaluate(() => kb.setHtml('<p>Before</p><p><br></p><p>After</p>'));
    await page.locator('#editor > p').nth(1).click();
    await page.keyboard.type('/date');
    await page.getByRole('option').filter({hasText: 'Date separator'}).waitFor();
    await page.keyboard.press('Enter');
    const picker = page.getByRole('dialog', {name: 'Date separator'});
    await picker.waitFor();
    await picker.getByLabel('Date', {exact: true}).fill('2026-09-19');
    await picker.getByRole('button', {name: 'Insert', exact: true}).click();
    await picker.waitFor({state: 'detached'});
    assert.equal(await page.locator('#editor > .kb-date-separator time').textContent(), '19 de setembro de 2026');
    await page.keyboard.type('Between');
    assert.deepEqual(await page.locator('#editor > p').allTextContents(), ['Before', 'Between', 'After']);
    const layout = await page.locator('#editor > .kb-date-separator').evaluate(element => {
        const editor = element.parentElement;
        const style = getComputedStyle(editor);
        return {width: element.getBoundingClientRect().width,
            contentWidth: editor.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight),
            line: getComputedStyle(element, '::before').borderTopWidth,
            editable: element.contentEditable};
    });
    assert.ok(Math.abs(layout.width - layout.contentWidth) < 1);
    assert.equal(layout.line, '1px');
    assert.equal(layout.editable, 'false');
});

test('date separators can change to date-time, retain time across display switches, and undo edits', async t => {
    const page = await fixture(t);
    await page.evaluate(() => kb.setHtml(''));
    await page.locator('#editor').click();
    await page.evaluate(() => kb.insertDateSeparator('2026-09-19'));
    await page.getByRole('button', {name: 'Edit date separator: 19 de setembro de 2026', exact: true}).click();
    const picker = page.getByRole('dialog', {name: 'Date separator'});
    await picker.getByLabel('Display').selectOption('datetime-local');
    await picker.getByLabel('Date and time', {exact: true}).fill('2026-09-19T14:30');
    await picker.getByLabel('Display').selectOption('date');
    assert.equal(await picker.getByLabel('Date', {exact: true}).inputValue(), '2026-09-19');
    await picker.getByLabel('Display').selectOption('datetime-local');
    assert.equal(await picker.getByLabel('Date and time', {exact: true}).inputValue(), '2026-09-19T14:30');
    await picker.getByRole('button', {name: 'Save', exact: true}).click();
    const time = page.locator('#editor .kb-date-separator time');
    assert.equal(await time.getAttribute('datetime'), '2026-09-19T14:30');
    assert.match(await time.textContent(), /19 de setembro de 2026.*14:30/);
    assert.equal(await page.locator('#editor > .kb-date-separator').count(), 1);
    await page.evaluate(() => kb.undo());
    await page.waitForFunction(() => document.querySelector('#editor .kb-date-separator time').dateTime === '2026-09-19');
    await page.evaluate(() => kb.redo());
    await page.waitForFunction(() => document.querySelector('#editor .kb-date-separator time').dateTime === '2026-09-19T14:30');
    await page.locator('#editor .kb-date-separator button').click();
    await picker.waitFor();
    await page.evaluate(() => kb.setEditable(false));
    await picker.waitFor({state: 'detached'});
    assert.equal(await page.locator('#editor .kb-date-separator button').isDisabled(), true);
    await page.evaluate(() => kb.setEditable(true));
    assert.equal(await page.locator('#editor .kb-date-separator button').isEnabled(), true);
});

test('date separators retain exact calendar values through canonical JSON, sanitized HTML, and Markdown', async t => {
    const page = await fixture(t);
    await page.addScriptTag({path: path.resolve(__dirname, '../../node_modules/dompurify/dist/purify.js')});
    const results = await page.evaluate(() => {
        kb.setHtml('<p>Before</p><div data-date-separator="" lang="pt-BR"><time datetime="2026-09-19">Old date label</time></div>' +
            '<div data-date-separator="" lang="pt-BR"><time datetime="2026-09-20T08:45">Old time label</time></div><p>After</p>');
        const state = kb.getEditorStateJSON();
        const html = kb.getHtml();
        const markdown = kb.getMarkdown();
        kb.setHtml(DOMPurify.sanitize(html));
        const fromHtml = kb.getEditorStateJSON();
        kb.setMarkdown(markdown);
        const fromMarkdown = kb.getEditorStateJSON();
        kb.setEditorStateJSON(state);
        return {state, html, markdown, fromHtml, fromMarkdown, fromState: kb.getEditorStateJSON()};
    });
    for (const key of ['state', 'fromHtml', 'fromMarkdown', 'fromState']) {
        const children = JSON.parse(results[key]).root.children;
        assert.deepEqual(children.map(node => node.type), ['paragraph', 'date-separator', 'date-separator', 'paragraph'], key);
        assert.deepEqual(children.filter(node => node.type === 'date-separator').map(node => node.value), ['2026-09-19', '2026-09-20T08:45'], key);
        assert.deepEqual(children.filter(node => node.type === 'date-separator').map(node => node.locale), ['pt-BR', 'pt-BR'], key);
    }
    assert.match(results.html, /<time[^>]+datetime="2026-09-19"/);
    assert.doesNotMatch(results.html, /<button|contenteditable|Old date label|Old time label/);
    assert.match(results.markdown, /data-date-separator/);
    assert.match(results.markdown, /datetime="2026-09-20T08:45"/);
    assert.equal(await page.locator('#editor .kb-date-separator time').first().textContent(), '19 de setembro de 2026');
});

test('date picker supports cancellation, keyboard insertion, invalid dates, and destroy cleanup', async t => {
    const page = await fixture(t);
    await page.setViewportSize({width: 320, height: 600});
    await page.evaluate(() => document.querySelector('.kb-lexical-editor-wrap').classList.add('modal'));
    await page.locator('#editor').focus();
    await page.evaluate(() => kb.openDateSeparatorPicker());
    const picker = page.getByRole('dialog', {name: 'Date separator'});
    await picker.waitFor();
    assert.equal(await page.locator('.modal .kb-date-separator-picker').count(), 1);
    const box = await picker.boundingBox();
    assert.ok(box.x >= 0 && box.x + box.width <= 320);
    assert.ok(box.y >= 0 && box.y + box.height <= 600);
    await picker.getByRole('button', {name: 'Cancel', exact: true}).click();
    await picker.waitFor({state: 'detached'});
    assert.equal(await page.locator('#editor .kb-date-separator').count(), 0);
    assert.match(await page.evaluate(() => kb.getHtml()), /Hello world/);
    await page.evaluate(() => kb.openDateSeparatorPicker());
    await picker.waitFor();
    await picker.getByLabel('Date', {exact: true}).fill('2026-09-19');
    await page.keyboard.press('Escape');
    await picker.waitFor({state: 'detached'});
    assert.equal(await page.locator('#editor .kb-date-separator').count(), 0);
    await page.evaluate(() => kb.openDateSeparatorPicker());
    await picker.waitFor();
    await picker.getByLabel('Date', {exact: true}).fill('2026-09-19');
    await page.keyboard.press('Enter');
    await picker.waitFor({state: 'detached'});
    assert.equal(await page.locator('#editor .kb-date-separator').count(), 1);
    assert.deepEqual(await page.evaluate(() => ['2026-02-30', '2026-09-19T25:00', '', '2026-09-19<script>']
        .map(value => kb.insertDateSeparator(value))), [false, false, false, false]);
    await page.locator('#editor .kb-date-separator button').click();
    await picker.waitFor();
    await page.evaluate(() => kb.destroy());
    await picker.waitFor({state: 'detached'});
    assert.equal(await page.locator('.kb-notion-plugins').count(), 0);
});

test('date separator labels preserve calendar dates and wall-clock times across timezones and DST', async t => {
    for (const timezoneId of ['America/New_York', 'Asia/Tokyo']) {
        const page = await fixture(t, {timezoneId});
        await page.evaluate(() => kb.setHtml('<div data-date-separator lang="pt-BR"><time datetime="2026-09-19"></time></div>' +
            '<div data-date-separator lang="pt-BR"><time datetime="2026-03-08T02:30"></time></div>'));
        const labels = await page.locator('#editor .kb-date-separator time').allTextContents();
        assert.equal(labels[0], '19 de setembro de 2026', timezoneId);
        assert.match(labels[1], /8 de março de 2026.*02:30/, timezoneId);
    }
});

test('date separators support native keyboard selection, block reordering, deletion, and undo', async t => {
    const page = await fixture(t);
    await page.evaluate(() => kb.setHtml('<p>Before</p><div data-date-separator lang="pt-BR"><time datetime="2026-09-19"></time></div><p>After</p>'));
    await page.locator('#editor').focus();
    await page.evaluate(() => {
        const text = document.querySelector('#editor > p:last-child span').firstChild;
        window.getSelection().setBaseAndExtent(text, 0, text, 0);
        document.dispatchEvent(new Event('selectionchange'));
    });
    await page.keyboard.press('ArrowLeft');
    await page.locator('#editor > .kb-date-separator-selected').waitFor();
    await page.keyboard.press('Alt+Shift+ArrowUp');
    assert.equal(await page.locator('#editor > :first-child').getAttribute('data-date-separator'), '');
    await page.keyboard.press('Backspace');
    await page.locator('#editor .kb-date-separator').waitFor({state: 'detached'});
    assert.deepEqual(await page.locator('#editor > p').allTextContents(), ['Before', 'After']);
    await page.evaluate(() => kb.undo());
    await page.locator('#editor .kb-date-separator').waitFor();
    assert.equal(await page.locator('#editor .kb-date-separator time').getAttribute('datetime'), '2026-09-19');
});
