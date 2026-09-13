// Run after npm run build-frontend, with chromium on PATH (or CHROMIUM_BIN).
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const {spawn} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const types = {'.js': 'text/javascript', '.json': 'application/json', '.css': 'text/css', '.html': 'text/html'};
const server = http.createServer((request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    if (pathname === '/favicon.ico') { response.writeHead(204).end(); return; }
    const file = pathname === '/' ? path.join(__dirname, 'record-icons.html') : path.resolve(root, '.' + pathname);
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
        console.error('Missing browser resource:', request.url);
        response.writeHead(404).end();
        return;
    }
    response.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
    if (file === path.join(__dirname, 'record-icons.html')) {
        const libsConfig = JSON.parse(fs.readFileSync(path.join(root,
            'application/Espo/Resources/metadata/app/jsLibs.json'), 'utf8'));
        const loaderParams = {
            basePath: '', internalModuleList: ['crm'], transpiledModuleList: ['global'], libsConfig,
            aliasMap: Object.fromEntries(Object.keys(libsConfig).map(name => [name, 'lib!' + name])),
        };
        response.end(fs.readFileSync(file, 'utf8').replace('data-name="loader-params">{}',
            'data-name="loader-params">' + JSON.stringify(loaderParams)));
        return;
    }
    fs.createReadStream(file).pipe(response);
});
server.listen(0, '127.0.0.1', () => {
    const browser = spawn(process.env.CHROMIUM_BIN || 'chromium', [
        '--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--no-first-run', '--disable-background-networking', '--enable-logging=stderr', '--dump-dom', '--virtual-time-budget=12000',
        `http://127.0.0.1:${server.address().port}/`,
    ]);
    let output = '';
    let errors = '';
    const timeout = setTimeout(() => browser.kill('SIGKILL'), 30000);
    browser.stdout.on('data', chunk => { output += chunk; });
    browser.stderr.on('data', chunk => { errors += chunk; });
    browser.on('error', error => { console.error(error.message); server.close(); process.exitCode = 1; });
    browser.on('close', () => {
        clearTimeout(timeout);
        server.close();
        const result = output.match(/<pre id="result">([\s\S]*?)<\/pre>/)?.[1];
        console.log(result || errors || 'Browser did not return a result.');
        if (!result?.startsWith('PASS:')) { console.error(errors); process.exitCode = 1; }
    });
});
