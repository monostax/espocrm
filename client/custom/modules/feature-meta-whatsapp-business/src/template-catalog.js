define('feature-meta-whatsapp-business:template-catalog', [], function () {
    return {
        load: function (context, progress = () => {}) {
            let request;
            let cancelled = false;
            const deadline = Date.now() + 120000;
            const promise = (async () => {
                const rows = new Map();
                const cursors = new Set();
                let cursor = null;
                for (let page = 1; page <= 100; page++) {
                    if (cancelled) throw {statusText: 'abort'};
                    const remaining = deadline - Date.now();
                    if (remaining <= 0) throw new Error('catalogDeadline');
                    request = Espo.Ajax.getRequest('WhatsAppBusinessAccountMessageTemplate/action/listPage',
                        {...context, ...(cursor ? {cursor} : {})}, {timeout: remaining});
                    const result = await request;
                    if (cancelled) throw {statusText: 'abort'};
                    if (!Array.isArray(result.list) || typeof result.hasMore !== 'boolean') {
                        throw new Error('catalogIncomplete');
                    }
                    result.list.forEach(row => {
                        if (!row.id) throw new Error('catalogIncomplete');
                        rows.set(row.id, row);
                    });
                    progress(page, rows.size);
                    if (!result.hasMore) return {list: [...rows.values()], total: rows.size};
                    cursor = result.nextCursor;
                    if (!cursor || cursors.has(cursor)) throw new Error('catalogIncomplete');
                    cursors.add(cursor);
                }
                throw new Error('catalogIncomplete');
            })();
            promise.abort = () => {
                cancelled = true;
                if (request && request.abort) request.abort();
            };
            return promise;
        },
    };
});
