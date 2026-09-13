/* Build local picker data and the matching server-side allowlist. */
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const read = file => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'));
const write = (file, value) => {
    const target = path.join(root, file);
    fs.mkdirSync(path.dirname(target), {recursive: true});
    fs.writeFileSync(target, JSON.stringify(value) + '\n');
};

// Reuse the Font Awesome catalog maintained by Espo's admin picker.
const source = fs.readFileSync(path.join(root,
    'client/src/views/admin/entity-manager/modals/select-icon.js'), 'utf8');
const fontAwesomeClassList = [...new Set(source.match(/fa[rs] fa-[a-z0-9-]+/g))];
if (fontAwesomeClassList.length < 1000) {
    throw new Error('Could not read the Espo Font Awesome catalog.');
}
write('custom/Espo/Modules/Global/Resources/metadata/app/recordIcons.json', {fontAwesomeClassList});

const emojis = new Set();
for (const locale of ['en', 'pt']) {
    const data = read(`node_modules/emojibase-data/${locale}/data.json`);
    const messages = read(`node_modules/emojibase-data/${locale}/messages.json`);
    const items = data.filter(item => Number.isInteger(item.group) && item.group !== 2)
        .sort((a, b) => a.order - b.order)
        .map(item => {
            const skins = (item.skins || []).map(skin => {
                emojis.add(skin.emoji);
                return {value: skin.emoji, label: skin.label, tone: skin.tone};
            });
            emojis.add(item.emoji);
            return {
                value: item.emoji,
                label: item.label,
                tags: item.tags || [],
                group: item.group,
                skins,
            };
        });
    write(`client/custom/modules/global/res/record-icons/${locale}.json`, {
        groups: messages.groups.filter(group => group.order !== 2), items,
    });
}
write('custom/Espo/Modules/Global/Resources/record-icon-emojis.json', [...emojis]);
console.log(`Record icons: ${fontAwesomeClassList.length} Font Awesome icons, ${emojis.size} emoji sequences.`);
