/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import BaseComplexCreatedFieldView from "views/fields/complex-created";

/**
 * Custom complex-created field view that shows user avatar.
 * Uses user-with-avatar for the createdBy sub-view.
 */
class ComplexCreatedFieldView extends BaseComplexCreatedFieldView {
    /**
     * Override data to NOT include byUserAvatar - the sub-view handles it.
     */
    data() {
        const hasBy = this.model.has(this.fieldBy + "Id");
        const hasAt = this.model.has(this.fieldAt);

        return {
            baseName: this.baseName,
            hasBy: hasBy,
            hasAt: hasAt,
            hasBoth: hasAt && hasBy,
            byUserAvatar: null, // Don't render avatar here - user-with-avatar sub-view does it
        };
    }

    /**
     * Override createField to use user-with-avatar view for the "by" field.
     */
    createField(part) {
        const field = this.baseName + Espo.Utils.upperCaseFirst(part);
        const type = this.model.getFieldType(field) || "base";

        let viewName;
        if (part === "by") {
            viewName = "views/fields/user-with-avatar";
        } else {
            viewName =
                this.model.getFieldParam(field, "view") ||
                this.getFieldManager().getViewName(type);
        }

        this.createView(part + "Field", viewName, {
            name: field,
            model: this.model,
            mode: this.MODE_DETAIL,
            readOnly: true,
            readOnlyLocked: true,
            selector: '[data-name="' + field + '"]',
        });
    }
}

// noinspection JSUnusedGlobalSymbols
export default ComplexCreatedFieldView;





