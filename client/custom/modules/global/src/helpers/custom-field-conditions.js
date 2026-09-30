define('global:helpers/custom-field-conditions', [], () => {
    const evaluate = (condition, context, partial = false) => {
        if (condition == null) return true;
        for (const group of ['all', 'any']) {
            if (condition[group]) {
                const results = condition[group].map(child => evaluate(child, context, partial));
                const decisive = group === 'any';
                if (results.includes(decisive)) return decisive;
                return results.includes(null) ? null : !decisive;
            }
        }
        if (!Object.prototype.hasOwnProperty.call(context, condition.attribute) && partial) return null;
        const value = context[condition.attribute] ?? null;
        const empty = value === null || (typeof value === 'string' && value.trim() === '') ||
            (Array.isArray(value) && value.length === 0);
        if (condition.operator === 'equals') return value === condition.value;
        if (condition.operator === 'in') return condition.value.includes(value);
        if (condition.operator === 'containsAny') return Array.isArray(value) && condition.value.some(item => value.includes(item));
        if (condition.operator === 'containsAll') return Array.isArray(value) && condition.value.every(item => value.includes(item));
        if (condition.operator === 'isEmpty') return empty;
        if (condition.operator === 'isFilled') return !empty;
        if (condition.operator === 'isTrue') return value === true;
        if (condition.operator === 'isFalse') return value === false;
        return false;
    };
    const required = (field, context) => evaluate(field.appliesWhen, context) === true &&
        (!!field.isRequired || (field.requiredWhen != null && evaluate(field.requiredWhen, context) === true));
    const groups = (meta, context) => (meta?.groups || []).map(group => ({
        ...group,
        fields: (group.fields || []).filter(field => evaluate(field.appliesWhen, context) === true)
            .map(field => ({ ...field, isRequired: required(field, context) })),
    })).filter(group => group.fields.length);
    const leaves = condition => {
        if (!condition) return [];
        if (condition.attribute) return [condition];
        return (condition.all || condition.any || []).flatMap(leaves);
    };
    const dependencies = meta => [...new Set((meta?.groups || []).flatMap(group => (group.fields || [])
        .flatMap(field => [...leaves(field.appliesWhen), ...leaves(field.requiredWhen)]).map(leaf => leaf.attribute)))];
    return { evaluate, required, groups, leaves, dependencies };
});
