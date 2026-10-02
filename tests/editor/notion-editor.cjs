// NODE_PATH=/path/to/playwright/node_modules node --test tests/editor/notion-editor.cjs
const {test, before, after} = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require('playwright');
let browser;
before(async () => { browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH}); });
after(async () => { await browser?.close(); });

async function fixture(t) {
    const page = await browser.newPage();
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
        window.kb = EspoLexical.createKbEditor({element: document.querySelector('#editor'), notion: true,
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
