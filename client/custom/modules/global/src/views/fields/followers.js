/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import BaseFollowersFieldView from "views/fields/followers";

/**
 * Custom followers field view that shows user avatars instead of scope icons.
 */
class FollowersFieldView extends BaseFollowersFieldView {
    /**
     * Override getIconHtml to return avatar instead of scope icon.
     * @param {string} id - User ID
     * @return {string}
     */
    getIconHtml(id) {
        return (
            this.getHelper().getAvatarHtml(id, "small", 16, "avatar-link") || ""
        );
    }
}

// noinspection JSUnusedGlobalSymbols
export default FollowersFieldView;


