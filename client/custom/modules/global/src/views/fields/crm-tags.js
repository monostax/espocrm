import LinkMultiple from "views/fields/link-multiple";
import {loadTags, tagClasses, tagStyle} from "global:crm-tags";

export default class CrmTagsField extends LinkMultiple {
    setup() {
        super.setup();
        if (this.getAcl().check("CrmTag", "read")) {
            this.wait(loadTags(this.getUser()).then(tags => { this.tags = tags; }).catch(() => { this.tags = {}; }));
        }
    }

    getSelectFilters() {
        const tenantId = this.model.get("tenantId");
        return tenantId ? {tenant: {type: "equals", attribute: "tenantId", value: tenantId}} : null;
    }

    getCreateAttributes() {
        return {
            ...super.getCreateAttributes(),
            tenantId: this.model.get("tenantId"),
            tenantName: this.model.get("tenantName"),
            teamsIds: this.model.get("teamsIds"),
            teamsNames: this.model.get("teamsNames"),
        };
    }

    getDetailLinkHtml(id, name) {
        const tag = this.tags?.[id];
        return $("<a>").attr("href", this.getUrl(id)).attr("data-id", id)
            .addClass(`inline-flex rounded px-2 py-0.5 text-xs ${tagClasses(tag?.color)}`)
            .css(tagStyle(tag?.color))
            .text(tag?.name || name || this.nameHash?.[id] || id)[0].outerHTML;
    }
}
