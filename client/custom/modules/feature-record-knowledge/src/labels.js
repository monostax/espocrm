export function predicateLabel(view, key, fallback, inverse = false) {
    if (!key?.startsWith('builtin:')) return fallback || key;
    const code = key.slice(8);
    const category = inverse ? 'inversePredicates' : 'predicates';
    return view.getLanguage().has(code, category, 'RecordRelation')
        ? view.translate(code, category, 'RecordRelation')
        : (fallback || key);
}
