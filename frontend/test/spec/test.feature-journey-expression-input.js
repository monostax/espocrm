describe('FeatureJourney expression input', () => {
    let ExpressionInput;
    let $;
    let input;
    let host;

    const view = {
        translate: key => key,
        getHelper: () => ({
            escapeString: value => value,
        }),
    };

    beforeEach(done => {
        require(['jquery', 'underscore'], jquery => {
            $ = jquery;

            require('feature-journey:helpers/expression-input', Module => {
                ExpressionInput = Module;
                host = $('<div>').appendTo(document.body);
                input = new ExpressionInput(view, {mode: 'expression'}).mount(host);
                done();
            });
        });
    });

    afterEach(() => {
        if (input) {
            input.destroy();
        }

        if (host) {
            host.remove();
        }
    });

    it('keeps an unsupported formula in raw mode', () => {
        const formula = "comparison\\greaterThan(entity\\attribute('score'), 10)";

        input._loadExpressionIntoUi(formula);

        expect(input._rawFormula).toBeTrue();
        expect(input.$raw.val()).toBe(formula);
        expect(input.getExpressionValue()).toBe(formula);

        input.$expand.trigger('click');

        expect(input._rawFormula).toBeTrue();
        expect(input.getExpressionValue()).toBe(formula);
    });

    it('inserts functions as executable formula code', () => {
        input._applyCatalogItem({
            kind: 'function',
            insert: 'datetime\\now()',
        });

        expect(input._rawFormula).toBeTrue();
        expect(input.getExpressionValue()).toBe('datetime\\now()');
    });

    it('composes a function with existing visual content', () => {
        input.$editor.text('Hello ');
        input._syncExpressionFromEditor();
        input._applyCatalogItem({
            kind: 'function',
            insert: 'datetime\\now()',
        });

        expect(input.getExpressionValue()).toBe(
            "string\\concatenate('Hello ', datetime\\now())"
        );
    });

    it('keeps empty and whitespace-only string formulas in raw mode', () => {
        ["''", "'   '", 'string\\concatenate()'].forEach(formula => {
            input._loadExpressionIntoUi(formula);

            expect(input._rawFormula).toBeTrue();
            expect(input.getExpressionValue()).toBe(formula);
        });
    });

    it('preserves fixed value whitespace', () => {
        input.setMode('fixed');
        input.$fixed.val('  padded  ');

        expect(input.getState().fixed).toBe('  padded  ');
    });

    it('preserves visual editor line breaks', () => {
        input.$editor.html('first<div>second</div>');
        input._syncExpressionFromEditor();

        expect(input.getExpressionValue()).toBe("'first\nsecond'");
    });
});
