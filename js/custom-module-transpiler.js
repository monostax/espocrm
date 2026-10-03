const {Transpiler} = require('espo-frontend-build-tools');
const babel = require('@babel/core');
const fs = require('fs');
const path = require('path');

module.exports = function transpileCustomModule(config) {
    const result = new Transpiler(config).process();

    // The upstream detector requires a newline before `export`. It copies
    // first-line exports and import-only modules unchanged, which the AMD
    // loader then evaluates as classic scripts.
    for (const file of [...result.copied]) {
        const source = fs.readFileSync(file, 'utf8');
        if (!/^\s*(export|import)\b/mu.test(source)) continue;

        const relative = path.relative(path.join(config.path, 'src'), file).split(path.sep).join('/');
        const options = {
            filename: file,
            sourceType: 'unambiguous',
            presets: [['@babel/preset-env', {targets: {chrome: '90', safari: '16'}}]],
            plugins: [
                ...(file.endsWith('.ts') ? ['@babel/plugin-transform-typescript'] : []),
                '@babel/plugin-transform-modules-amd',
                ['@babel/plugin-proposal-decorators', {version: '2023-11'}],
            ],
            moduleId: relative.replace(/\.(js|ts)$/u, ''),
        };
        const ast = babel.parseSync(source, options);
        if (!ast.program.body.some(node => node.type === 'ImportDeclaration' || node.type.startsWith('Export'))) continue;

        const output = babel.transformFromAstSync(ast, source, {...options, sourceMaps: true});
        const target = path.join(config.destDir, 'src', relative.replace(/\.ts$/u, '.js'));
        fs.mkdirSync(path.dirname(target), {recursive: true});
        fs.writeFileSync(target, output.code + `\n//# sourceMappingURL=${path.basename(target)}.map\n`, 'utf8');
        fs.writeFileSync(target + '.map', JSON.stringify(output.map), 'utf8');
        result.copied.splice(result.copied.indexOf(file), 1);
        result.transpiled.push(file);
    }

    return result;
};
