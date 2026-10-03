const colors = {
    slate: "bg-slate-100 text-slate-800",
    amber: "bg-amber-100 text-amber-800",
    teal: "bg-teal-100 text-teal-800",
    ruby: "bg-red-100 text-red-800",
    blue: "bg-blue-100 text-blue-800",
    iris: "bg-purple-100 text-purple-800",
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

export const tagClasses = color => colors[color] || colors.slate;
