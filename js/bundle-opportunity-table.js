const fs = require('fs');
const Handlebars = require('handlebars');

// Internal dependencies precede their consumers so AMD factories resolve in
// the same script evaluation. Core/CRM dependencies are loaded by the loader.
const modules = [
    'chatwoot:helpers/opportunity-group-icon',
    'global:crm-tags',
    'global:helpers/opportunity-stage-requirements',
    'global:views/opportunity/record/list',
    'global:views/opportunity/fields/opportunity-stage',
    'chatwoot:views/opportunity/table-groups',
    'chatwoot:views/opportunity/table-bridge',
    'chatwoot:controllers/opportunity-table-bridge',
];

module.exports = function bundleOpportunityTable() {
    const contents = modules.map(id => {
        const [module, name] = id.split(':');
        return fs.readFileSync(`client/custom/modules/${module}/lib/transpiled/src/${name}.js`, 'utf8');
    });

    const templates = ['detail', 'list', 'list-link'].map(name => {
        const templatePath = `opportunity/fields/opportunity-stage/${name}`;
        const template = fs.readFileSync(`client/custom/modules/global/res/templates/${templatePath}.tpl`, 'utf8');
        return `${JSON.stringify(`global:${templatePath}`)}: Handlebars.template(${Handlebars.precompile(template)})`;
    });
    contents.push(`Espo.loader.require('handlebars', function (Handlebars) {
    Object.assign(Espo.preCompiledTemplates, {${templates.join(',\n')}});
});`);

    const mapping = Object.fromEntries(modules.map(id => [
        'modules/' + id.replace(':', '/'), 'opportunity-table',
    ]));
    const dependencies = [
        'controllers/record', 'views/list', 'view', 'helpers/record-icon',
        'crm:views/opportunity/record/list', 'views/fields/link', 'handlebars',
    ];

    return {
        bundle: contents.join('\n;\n'),
        init: `Espo.preCompiledTemplates = Espo.preCompiledTemplates || {};
Espo.loader.mapBundleFile('opportunity-table', 'client/lib/espo-opportunity-table.js');
Espo.loader.mapBundleDependencies('opportunity-table', ${JSON.stringify(dependencies)});
Espo.loader.addBundleMapping(${JSON.stringify(mapping)});
if (window.location.hash === '#OpportunityTableBridge') {
    Espo.loader.require('chatwoot:controllers/opportunity-table-bridge', function () {});
}
`,
    };
};
