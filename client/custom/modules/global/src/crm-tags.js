const colors = {
    slate: "bg-slate-100 text-slate-800",
    amber: "bg-amber-100 text-amber-800",
    teal: "bg-teal-100 text-teal-800",
    ruby: "bg-red-100 text-red-800",
    blue: "bg-blue-100 text-blue-800",
    iris: "bg-purple-100 text-purple-800",
};

const legacyHex = {
    slate: "#64748b", amber: "#f59e0b", teal: "#14b8a6",
    ruby: "#ef4444", blue: "#3b82f6", iris: "#a855f7",
};

export const tagHex = color => legacyHex[color] || (/^#[0-9a-f]{6}$/i.test(color || "") ? color : null);

export const tagStyle = color => {
    if (colors[color]) return {};
    const hex = tagHex(color);
    if (!hex) return {};
    const channels = hex.slice(1).match(/../g).map(value => {
        const channel = parseInt(value, 16) / 255;
        return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    });
    const luminance = channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
    return {backgroundColor: hex, color: luminance > 0.179 ? "#000000" : "#ffffff"};
};

let request;
let expires = 0;
let userId;

export const invalidateTags = () => { request = null; expires = 0; };

// Native table cells share one ACL-filtered catalog request, not one per row.
export async function loadTags(user) {
    if (!request || expires < Date.now() || userId !== user.id) {
        userId = user.id;
        expires = Infinity;
        request = (async () => {
            const tags = {};
            let offset = 0;
            while (true) {
                const data = await Espo.Ajax.getRequest("CrmTag", {
                    select: "name,color,tenantId", maxSize: 200, offset, orderBy: "id", order: "asc",
                });
                const page = data.list.slice(0, 200);
                page.forEach(tag => { tags[tag.id] = tag; });
                offset += page.length;
                if (page.length < 200 || (data.total >= 0 && offset >= data.total)) break;
            }
            return tags;
        })().finally(() => { expires = Date.now() + 5000; });
    }
    return request;
}

export const tagClasses = color => colors[color] || (tagHex(color) ? "" : colors.slate);
