// Runs before the core bundles. Keep this independent of Espo and its loader.
(() => {
    if (window.self === window.top || window.location.hash !== '#OpportunityTableBridge') return;

    let auth;
    let anotherUser;
    try {
        auth = localStorage.getItem('espo-user-auth');
        anotherUser = localStorage.getItem('espo-user-anotherUser');
    } catch {
        return;
    }
    if (!auth) return;

    const params = JSON.parse(document.querySelector('script[data-name="loader-params"]').textContent);
    const xhr = new XMLHttpRequest();
    // Resolve failures too: initialization may not attach a consumer until later.
    const promise = new Promise(resolve => {
        xhr.open('GET', `${params.apiUrl}/App/user?bootstrap=opportunity-table`);
        xhr.timeout = params.ajaxTimeout;
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('Authorization', `Basic ${auth}`);
        xhr.setRequestHeader('Espo-Authorization', auth);
        xhr.setRequestHeader('Espo-Authorization-By-Token', 'true');
        if (anotherUser) xhr.setRequestHeader('X-Another-User', anotherUser);
        xhr.onload = () => {
            if (xhr.status === 200) {
                try {
                    resolve({data: JSON.parse(xhr.responseText)});
                    return;
                } catch { /* Hand off malformed responses to the application's error UI. */ }
            }
            resolve({error: xhr});
        };
        xhr.onerror = xhr.ontimeout = xhr.onabort = () => resolve({error: xhr});
        xhr.send();
    });
    window.espoEarlyBootstrap = {auth, anotherUser, promise};
})();
