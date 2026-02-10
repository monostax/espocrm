/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Custom list view for WhatsAppBusinessAccount.
 *
 * Uses EspoCRM's native field filter UI for OAuthAccount filtering.
 * The controller parses whereGroup clauses to extract oAuthAccountId,
 * so no special interception is needed here.
 */
define('feature-meta-whatsapp-business:views/whatsapp-business-account/list',
    ['views/list'],
    function (ListView) {

    return ListView.extend({});
});
