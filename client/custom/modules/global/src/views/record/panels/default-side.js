/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import BaseDefaultSidePanelView from "views/record/panels/default-side";

/**
 * Custom default side panel that uses avatar-enabled views.
 */
class DefaultSidePanelView extends BaseDefaultSidePanelView {
    /**
     * Override createField to use custom views for specific fields.
     */
    createField(field, viewName, params, mode, readOnly, options) {
        // Use custom views with avatar support
        if (field === "complexCreated" || field === "complexModified") {
            viewName = "global:views/fields/complex-created";
        } else if (field === "followers") {
            viewName = "global:views/fields/followers";
        }

        super.createField(field, viewName, params, mode, readOnly, options);
    }
}

export default DefaultSidePanelView;


