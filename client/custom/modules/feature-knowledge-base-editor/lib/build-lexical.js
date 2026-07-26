/**
 * Build Lexical KB UMD bundle for EspoCRM loader.
 * Run: node client/custom/modules/feature-knowledge-base-editor/lib/build-lexical.js
 */
const path = require('path');
const fs = require('fs');
const {rollup} = require('rollup');
const resolve = require('@rollup/plugin-node-resolve');
const commonjs = require('@rollup/plugin-commonjs');

const baseDir = __dirname;
const entry = path.join(baseDir, 'lexical-entry.js');
const outPath = path.join(baseDir, 'lexical-kb-bundle.js');
const outOriginal = path.join(baseDir, 'original', 'lexical-kb-bundle.js');

async function build() {
    console.log('Building Lexical KB bundle...');

    const bundle = await rollup({
        input: entry,
        plugins: [
            resolve({
                browser: true,
                preferBuiltins: false,
            }),
            commonjs(),
        ],
        onwarn(warning, warn) {
            if (warning.code === 'CIRCULAR_DEPENDENCY') {
                return;
            }
            warn(warning);
        },
    });

    const {output} = await bundle.generate({
        format: 'iife',
        name: 'EspoLexical',
        exports: 'default',
        sourcemap: false,
        extend: false,
    });

    const code = output[0].code;

    fs.mkdirSync(path.join(baseDir, 'original'), {recursive: true});
    fs.writeFileSync(outPath, code);
    fs.writeFileSync(outOriginal, code);

    console.log('Wrote', outPath);
    console.log('Wrote', outOriginal);
    console.log('Done.');
}

build().catch(err => {
    console.error(err);
    process.exit(1);
});
