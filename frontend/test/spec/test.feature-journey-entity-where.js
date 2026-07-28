describe('FeatureJourney entity where field', () => {
    let EntityWhere;
    let field;

    beforeEach(done => {
        require('feature-journey:views/fields/entity-where', Module => {
            EntityWhere = Module;
            field = Object.create(EntityWhere.prototype);
            field.name = 'conditionsGroup';
            field.model = {entityType: 'JourneyStageAction'};
            field.cfHelper = {
                operatorNeedsValue: operator => [
                    'isTrue',
                    'isFalse',
                    'isNull',
                    'isNotNull',
                ].indexOf(operator) === -1,
                coerceValue: (value, operator) => {
                    if (operator === 'in') {
                        return String(value || '')
                            .split(',')
                            .map(item => item.trim())
                            .filter(Boolean);
                    }

                    return value;
                },
            };
            done();
        });
    });

    it('normalizes a legacy list to an AND group', () => {
        const tree = field.normalizeTree([
            {type: 'equals', attribute: 'status', value: 'New'},
        ]);

        expect(tree).toEqual({
            type: 'and',
            value: [{type: 'equals', attribute: 'status', value: 'New'}],
        });
    });

    it('round-trips nested AND and OR groups', () => {
        const tree = {
            type: 'and',
            value: [
                {type: 'isNotNull', attribute: 'emailAddress'},
                {
                    type: 'or',
                    value: [
                        {type: 'equals', attribute: 'status', value: 'New'},
                        {
                            type: 'equals',
                            attribute: 'customFields.billing.plan',
                            value: 'pro',
                        },
                    ],
                },
            ],
        };

        expect(field.cleanTree(field.normalizeTree(tree))).toEqual(tree);
    });

    it('omits values for no-value operators', () => {
        expect(field.cleanTree({
            type: 'and',
            value: [{type: 'isNull', attribute: 'emailAddress', value: 'ignored'}],
        })).toEqual({
            type: 'and',
            value: [{type: 'isNull', attribute: 'emailAddress'}],
        });
    });

    it('keeps list values as arrays', () => {
        expect(field.cleanTree({
            type: 'and',
            value: [{type: 'in', attribute: 'status', value: ['New', 'Assigned']}],
        }).value[0].value).toEqual(['New', 'Assigned']);
    });

    it('preserves a dynamic value formula alongside the fixed fallback', () => {
        expect(field.cleanTree({
            type: 'and',
            value: [{
                type: 'equals',
                attribute: 'assignedUserId',
                value: 'fallback',
                valueFormula: "entity\\attribute('createdById')",
            }],
        }).value[0]).toEqual({
            type: 'equals',
            attribute: 'assignedUserId',
            value: 'fallback',
            valueFormula: "entity\\attribute('createdById')",
        });
    });

    it('saves an empty root as null', () => {
        expect(field.cleanTree({type: 'and', value: []})).toBeNull();
    });
});
