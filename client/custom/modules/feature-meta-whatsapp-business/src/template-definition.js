define('feature-meta-whatsapp-business:template-definition', [], function () {
    const languages = ('af sq ar az bn bg ca zh_CN zh_HK zh_TW hr cs da nl en en_GB en_US et fil fi fr fr_CA ' +
        'ka de el gu ha he hi hu id ga it ja kn kk rw_RW ko ky_KG lo lv lt mk ms ml mr nb ps_AF fa pl pt_BR ' +
        'pt_PT pa ro ru sr si_LK sk sl es es_AR es_ES es_MX sw sv ta te th tr uk ur uz vi zu').split(' ');
    const fail = key => { throw new Error(key); };
    const text = (value, limit) => {
        if (typeof value !== 'string' || !value.trim() || [...value].length > limit) fail('invalidText');
    };
    const variables = value => {
        const rest = value.replace(/\{\{([1-9][0-9]*)\}\}/g, '');
        if (rest.includes('{{') || rest.includes('}}')) fail('invalidVariables');
        const list = [...new Set([...value.matchAll(/\{\{([1-9][0-9]*)\}\}/g)].map(m => Number(m[1])))].sort((a, b) => a - b);
        if (list.some((v, i) => v !== i + 1)) fail('invalidVariables');
        return list;
    };
    return {
        languages, variables,
        validate: function (definition, sample) {
            if (!/^[a-z0-9_]{1,512}$/.test(definition.name)) fail('invalidName');
            if (!languages.includes(definition.language)) fail('invalidLanguage');
            if (!['MARKETING', 'UTILITY'].includes(definition.category)) fail('unsupported');
            for (const component of definition.components) {
                if (component.type === 'BUTTONS') {
                    const buttons = component.buttons;
                    if (!buttons.length || buttons.length > 10 ||
                        buttons.filter(b => b.type === 'URL').length > 2 ||
                        buttons.filter(b => b.type === 'PHONE_NUMBER').length > 1) fail('invalidButtons');
                    const groups = [];
                    buttons.forEach(button => {
                        text(button.text, 25);
                        if (variables(button.text).length) fail('invalidButtons');
                        const group = button.type === 'QUICK_REPLY' ? 'quick' : 'cta';
                        if (groups[groups.length - 1] !== group) groups.push(group);
                        if (button.type === 'URL') {
                            text(button.url, 2000);
                            let url;
                            try { url = new URL(button.url); } catch (e) { fail('invalidButtons'); }
                            if (!['http:', 'https:'].includes(url.protocol) || variables(button.url).length) fail('invalidButtons');
                        } else if (button.type === 'PHONE_NUMBER' && !/^\+[1-9][0-9]{6,14}$/.test(button.phone_number)) {
                            fail('invalidButtons');
                        }
                    });
                    if (groups.length > 2) fail('invalidButtons');
                    continue;
                }
                if (component.type === 'HEADER' && component.format !== 'TEXT') {
                    const mime = {IMAGE: ['image/jpeg', 'image/png'], VIDEO: ['video/mp4'], DOCUMENT: ['application/pdf']}[component.format];
                    if (!sample || !sample.size || sample.size > (component.format === 'IMAGE' ? 5000000 : 16000000) ||
                        !mime?.includes(sample.type)) fail('invalidSample');
                    continue;
                }
                const limit = component.type === 'BODY' ? 1024 : 60;
                text(component.text, limit);
                const count = variables(component.text).length;
                if ((component.type === 'HEADER' && count > 1) || (component.type === 'FOOTER' && count)) fail('invalidVariables');
                if (count) {
                    const examples = component.type === 'BODY' ? component.example?.body_text?.[0] : component.example?.header_text;
                    if (!examples || examples.length !== count) fail('invalidExamples');
                    examples.forEach(value => text(value, limit));
                }
            }
        },
    };
});
