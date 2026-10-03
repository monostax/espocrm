export function identity(model) {
    return {recordType: model.entityType, recordId: model.id};
}

export function endpoint(action, model) {
    return `RecordKnowledge/${action}?${new URLSearchParams(identity(model))}`;
}

// Textareas normalize line endings internally. Keep original bytes on an unchanged
// save and preserve CRLF convention when editing a CRLF-authored document.
export function sourceValue(textarea, original = '') {
    const value = textarea.value;
    if (value === original.replace(/\r\n?/g, '\n')) return original;
    return original.includes('\r\n') ? value.replace(/\n/g, '\r\n') : value;
}

export async function resolveLinks(element) {
    const links = [...element.querySelectorAll('a[href^="#crm-reference/"]')];
    const entries = [];
    for (const link of links) {
        const match = /^#crm-reference\/v1\/record\/([A-Z][a-zA-Z0-9]{0,63})\/([a-zA-Z0-9_-]{1,64})$/.exec(link.getAttribute('href'));
        link.removeAttribute('href');
        link.textContent = 'Unavailable reference';
        if (match) entries.push({link, ref: {kind: 'record', entityType: match[1], recordId: match[2]}});
    }
    const references = [...new Map(entries.map(({ref}) => [`${ref.entityType}:${ref.recordId}`, ref])).values()];
    if (!references.length) return;
    const data = await Espo.Ajax.postRequest('EditorReference/resolve', {references});
    const resolved = new Map(data.list.map(ref => [`${ref.entityType}:${ref.recordId}`, ref]));
    for (const {link, ref} of entries) {
        const result = resolved.get(`${ref.entityType}:${ref.recordId}`);
        if (!result?.available || !element.contains(link)) continue;
        link.textContent = result.label;
        link.href = `#${ref.entityType}/view/${ref.recordId}`;
    }
}

export function preview(view, element, html) {
    element.innerHTML = view.getHelper().sanitizeHtml(html || '');
    resolveLinks(element).catch(() => {});
}

export function download(filename, content) {
    const url = URL.createObjectURL(new Blob([content], {type: 'text/markdown;charset=utf-8'}));
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
