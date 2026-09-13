import Ajax from 'ajax';
import Handlebars from 'handlebars';

const escapeHtml = value => Handlebars.Utils.escapeExpression(value);
const registries = new WeakMap();
const stores = new WeakMap();

function store(view) {
    const owner = view.getHelper();
    if (!stores.has(owner)) {
        stores.set(owner, {cache: new Map(), pending: new Map(), listeners: new Set()});
    }
    return stores.get(owner);
}

const RecordIcon = {
    attribute(view, scope) {
        return view.getMetadata().get(`clientDefs.${scope}.recordIconAttribute`) || null;
    },

    classes(metadata) {
        if (!registries.has(metadata)) {
            registries.set(metadata, [...new Set([
                ...metadata.get('app.clientIcons.classList', []),
                ...metadata.get('app.recordIcons.fontAwesomeClassList', []),
            ])].filter(value => /^(?:ti ti-|fa[rs] fa-)[a-z0-9-]+$/.test(value)));
        }
        return registries.get(metadata);
    },

    normalize(icon, metadata) {
        if (!icon || typeof icon.value !== 'string') {
            return null;
        }
        if (icon.type === 'icon' && this.classes(metadata).includes(icon.value)) {
            return {type: 'icon', value: icon.value};
        }
        // Rendering always escapes text; the API validates the full Unicode sequence against the catalog.
        if (icon.type === 'emoji' && icon.value.length <= 128 && /\p{Extended_Pictographic}|\p{Regional_Indicator}|\u20e3/u.test(icon.value)) {
            return {type: 'emoji', value: icon.value};
        }
        return null;
    },

    html(icon, metadata, fallback = '') {
        const value = this.normalize(icon, metadata);
        if (value?.type === 'emoji') {
            return `<span class="record-icon record-icon-emoji" aria-hidden="true">${escapeHtml(value.value)}</span>`;
        }
        const className = value?.value || fallback;
        if (!/^(?:ti ti-|fa[rs] fa-)[a-z0-9-]+$/.test(className)) {
            return '';
        }
        return `<span class="record-icon ${escapeHtml(className)}" aria-hidden="true"></span>`;
    },

    fallback(view, scope) {
        return view.getMetadata().get(`clientDefs.${scope}.iconClass`) || '';
    },

    slot(view, scope, id) {
        return `<span class="record-icon-slot" data-record-icon-id="${escapeHtml(id)}">` +
            this.html(null, view.getMetadata(), this.fallback(view, scope)) + '</span>';
    },

    remember(view, scope, record, notify = false) {
        const attribute = this.attribute(view, scope);
        if (!attribute || !record.id || !Object.hasOwn(record, attribute)) {
            return;
        }
        const state = store(view);
        const value = {id: record.id, name: record.name, icon: record[attribute] ?? null};
        state.cache.set(`${scope}:${record.id}`, {value, time: Date.now()});
        if (notify) {
            state.listeners.forEach(listener => { listener(scope, value); });
        }
    },

    listen(view, callback) {
        const listeners = store(view).listeners;
        listeners.add(callback);
        view.once('remove', () => listeners.delete(callback));
    },

    // Coalesce all visible linked fields into ACL-filtered collection requests, up to 100 IDs each.
    load(view, scope, id) {
        const attribute = this.attribute(view, scope);
        if (!attribute || !id || !view.getAcl().checkScope(scope, 'read')) {
            return Promise.resolve(null);
        }
        const state = store(view);
        const key = `${scope}:${id}`;
        const cached = state.cache.get(key);
        if (cached && Date.now() - cached.time < 10000) {
            return Promise.resolve(cached.value);
        }
        if (state.pending.has(key)) {
            return state.pending.get(key).promise;
        }
        let resolve;
        const promise = new Promise(done => { resolve = done; });
        state.pending.set(key, {key, scope, id, promise, resolve, previous: cached});
        if (!state.timer) {
            state.timer = setTimeout(() => {
                state.timer = null;
                const pending = [...state.pending.values()].filter(item => !item.started);
                const scopes = new Set(pending.map(item => item.scope));
                scopes.forEach(entityType => {
                    const items = pending.filter(item => item.scope === entityType);
                    for (let i = 0; i < items.length; i += 100) {
                        const batch = items.slice(i, i + 100);
                        batch.forEach(item => { item.started = true; });
                        const field = this.attribute(view, entityType);
                        Ajax.getRequest(entityType, {
                            select: `id,name,${field}`,
                            where: [{type: 'in', attribute: 'id', value: batch.map(item => item.id)}],
                            maxSize: batch.length,
                        }).then(response => {
                            const records = new Map((response.list || []).map(record => [record.id, record]));
                            batch.forEach(item => {
                                // A save while this request was in flight wins over an older response.
                                if (state.cache.get(item.key) === item.previous) {
                                    const record = records.get(item.id);
                                    if (record) {
                                        this.remember(view, entityType, record);
                                    } else {
                                        state.cache.set(item.key, {value: null, time: Date.now()});
                                    }
                                }
                                item.resolve(state.cache.get(item.key)?.value || null);
                            });
                        }).catch(() => {
                            batch.forEach(item => { item.resolve(null); });
                        }).finally(() => {
                            batch.forEach(item => { state.pending.delete(item.key); });
                        });
                    }
                });
            }, 0);
        }
        return promise;
    },

    bindLink(view, multiple = false) {
        const scope = view.foreignScope;
        if (!this.attribute(view, scope)) {
            return;
        }
        const render = () => {
            if (!view.element?.isConnected) {
                return;
            }
            if (!multiple && view.isEditMode()) {
                const input = view.element.querySelector('.input-group > input');
                const id = view.model.get(view.idName);
                view.element.querySelectorAll('.record-icon-input').forEach(element => { element.remove(); });
                input?.classList.toggle('record-icon-input-value', !!id);
                if (input && id) {
                    const span = document.createElement('span');
                    span.className = 'record-icon-slot record-icon-input icon-in-input';
                    span.dataset.recordIconId = id;
                    input.after(span);
                }
            }
            view.element.querySelectorAll('[data-record-icon-id]').forEach(element => {
                const id = element.dataset.recordIconId;
                this.load(view, scope, id).then(record => {
                    if (!element.isConnected || element.dataset.recordIconId !== id) {
                        return;
                    }
                    element.innerHTML = this.html(record?.icon, view.getMetadata(), this.fallback(view, scope));
                });
            });
        };
        view.on('after:render', render);
        view.on('change', () => setTimeout(render, 0));
        this.listen(view, entityType => { if (entityType === scope) { render(); } });
    },

    suggestion(view, scope, item) {
        this.remember(view, scope, item.attributes || {});
        return this.html(item.attributes?.[this.attribute(view, scope)], view.getMetadata(), this.fallback(view, scope)) +
            ' ' + escapeHtml(item.value);
    },
};

export default RecordIcon;
