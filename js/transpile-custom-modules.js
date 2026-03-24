/************************************************************************
 * Transpile custom EspoCRM modules (ES6 -> AMD with correct namespace).
 *
 * EspoCRM custom modules need AMD define() IDs in the format "{mod}:path"
 * (e.g. "chatwoot:views/site/navbar/conversation-badges"). The upstream
 * espo-frontend-build-tools Transpiler only produces bare IDs (no prefix)
 * when run without the `mod` option. This script:
 *
 *   1. Discovers modules with jsTranspiled/bundled: true in module.json
 *   2. Runs the Transpiler (ES6 files -> AMD, hand-written AMD -> copied)
 *   3. Adds the "{mod}:" namespace prefix to transpiled files
 *   4. Validates every define() has the correct prefix (fails the build if not)
 *
 * This is the single authoritative script for custom module transpilation.
 * It replaces the previous transpile-custom-modules.js, fix-custom-module-ids.js,
 * and the custom-module handling that was duplicated in transpile.js.
 ************************************************************************/

const {Transpiler, Bundler} = require('espo-frontend-build-tools');
const fs = require('fs');
const path = require('path');

const customModulesPath = 'custom/Espo/Modules';
const clientModulesPath = 'client/custom/modules';

/**
 * Convert PascalCase module directory name to kebab-case.
 * e.g. "FeatureFinanceiroBase" -> "feature-financeiro-base"
 */
function toKebabCase(name) {
    return name.replace(/([A-Z])/g, (match, p1, offset) => {
        return offset > 0 ? '-' + p1.toLowerCase() : p1.toLowerCase();
    });
}

/**
 * Discover all custom modules that need transpilation.
 */
function discoverModules() {
    if (!fs.existsSync(customModulesPath)) {
        return [];
    }

    const modules = [];

    for (const dir of fs.readdirSync(customModulesPath)) {
        const configPath = path.join(customModulesPath, dir, 'Resources/module.json');

        if (!fs.existsSync(configPath)) continue;

        try {
            const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));

            if (!config.jsTranspiled && !config.bundled) continue;

            const moduleName = toKebabCase(dir);
            const clientPath = path.join(clientModulesPath, moduleName);

            if (!fs.existsSync(clientPath)) continue;

            modules.push({
                name: dir,
                moduleName,
                clientPath,
                bundled: !!config.bundled,
            });
        } catch (e) {
            console.error(`  Error reading module.json for ${dir}: ${e.message}`);
        }
    }

    return modules;
}

/**
 * Add the "{moduleName}:" namespace prefix to define() IDs in a transpiled file.
 *
 * The Transpiler (without `mod`) produces bare IDs like:
 *   define("views/site/navbar/conversation-badges", ...)
 *
 * This rewrites them to:
 *   define("chatwoot:views/site/navbar/conversation-badges", ...)
 *
 * Files that already contain a ":" in their define() ID are left untouched
 * (hand-written AMD files that were copied, not transpiled).
 */
function addNamespacePrefix(filePath, moduleName) {
    let content = fs.readFileSync(filePath, 'utf-8');
    const original = content;

    // Match: define("bare/path", ...) or define('bare/path', ...)
    // where "bare/path" does NOT contain a colon (i.e. not already namespaced).
    content = content.replace(
        /define\(\s*(["'])([^:"']+)\1\s*,/g,
        (match, quote, id) => `define(${quote}${moduleName}:${id}${quote},`
    );

    if (content !== original) {
        fs.writeFileSync(filePath, content, 'utf-8');
        return true;
    }

    return false;
}

/**
 * Validate that every define() with a string ID in the transpiled output
 * has the correct "{moduleName}:" prefix. Fails the build if not.
 */
function validate(files, moduleName) {
    let errors = 0;

    for (const filePath of files) {
        const content = fs.readFileSync(filePath, 'utf-8');
        const match = content.match(/(^|\n)\s*define\(\s*["']([^"']+)["']/);

        // Anonymous defines (no string ID) are fine - the loader resolves them
        // via document.currentScript.src + _urlIdMap.
        if (!match) continue;

        const moduleId = match[2];

        if (!moduleId.startsWith(`${moduleName}:`)) {
            console.error(
                `    ✗ ${path.relative('.', filePath)}: ` +
                `define("${moduleId}") missing "${moduleName}:" prefix`
            );
            errors++;
        }
    }

    return errors;
}

// --- Main ---

const modules = discoverModules();

if (modules.length === 0) {
    console.log('\n  No custom modules require transpilation.\n');
    process.exit(0);
}

console.log(`\n  Transpiling ${modules.length} custom module(s):\n`);

let totalTranspiled = 0;
let totalCopied = 0;
let totalPrefixed = 0;
let validationErrors = 0;

for (const mod of modules) {
    console.log(`    ${mod.name} (${mod.moduleName})`);

    try {
        // Step 1: Transpile (ES6 -> AMD) and copy (hand-written AMD as-is).
        const transpiler = new Transpiler({
            path: mod.clientPath,
            destDir: path.join(mod.clientPath, 'lib/transpiled'),
        });

        const result = transpiler.process();

        totalTranspiled += result.transpiled.length;
        totalCopied += result.copied.length;

        // Step 2: Add namespace prefix to transpiled files.
        // Only transpiled files need prefixing - copied files (hand-written AMD)
        // already have their "{mod}:path" IDs or are anonymous defines.
        let prefixed = 0;

        for (const filePath of result.transpiled) {
            // The Transpiler writes output to destDir mirroring the src/ structure.
            // Compute the output path from the source path.
            const relPath = filePath.slice(mod.clientPath.length + 1); // e.g. "src/views/foo.js"
            const outPath = path.join(mod.clientPath, 'lib/transpiled', relPath);

            if (fs.existsSync(outPath) && addNamespacePrefix(outPath, mod.moduleName)) {
                prefixed++;
            }
        }

        totalPrefixed += prefixed;

        // Step 3: Validate ALL output files (transpiled + copied).
        const allOutputFiles = [...result.transpiled, ...result.copied].map(filePath => {
            const relPath = filePath.slice(mod.clientPath.length + 1);
            return path.join(mod.clientPath, 'lib/transpiled', relPath);
        }).filter(f => fs.existsSync(f));

        validationErrors += validate(allOutputFiles, mod.moduleName);

        // Step 4: Bundle if needed.
        let bundleMsg = '';

        if (mod.bundled) {
            const bundleConfigPath = path.join(mod.clientPath, 'bundle-config.json');

            if (fs.existsSync(bundleConfigPath)) {
                try {
                    const bundleConfig = JSON.parse(fs.readFileSync(bundleConfigPath, 'utf8'));
                    const bundler = new Bundler(bundleConfig);
                    const bundleResult = bundler.bundle();

                    const libPath = path.join(mod.clientPath, 'lib');
                    fs.mkdirSync(libPath, {recursive: true});
                    fs.writeFileSync(path.join(libPath, 'init.js'), bundleResult.main || '', 'utf8');

                    bundleMsg = ', bundled';
                } catch (e) {
                    console.error(`      ⚠ Bundling error: ${e.message}`);
                }
            }
        }

        console.log(
            `      transpiled: ${result.transpiled.length}, ` +
            `copied: ${result.copied.length}, ` +
            `prefixed: ${prefixed}${bundleMsg}`
        );
    } catch (e) {
        console.error(`      ✗ Error: ${e.message}`);
        validationErrors++;
    }
}

console.log(
    `\n  Total: transpiled: ${totalTranspiled}, ` +
    `copied: ${totalCopied}, prefixed: ${totalPrefixed}`
);

if (validationErrors > 0) {
    console.error(
        `\n  ✗ Validation failed: ${validationErrors} file(s) have incorrect module IDs.\n` +
        `    This will cause silent loading failures in production.\n`
    );
    process.exit(1);
}

console.log(`  ✓ All module IDs validated.\n`);
