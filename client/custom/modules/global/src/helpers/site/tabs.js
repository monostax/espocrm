/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import CoreTabsHelper from 'helpers/site/tabs';

export default class TabsHelper extends CoreTabsHelper {
    isTabVirtualFolder(item) {
        return typeof item === 'object' && item.type === 'virtualFolder';
    }

    checkTabAccess(item) {
        if (this.isTabVirtualFolder(item)) {
            return this.acl.checkScope(item.entityType, 'read');
        }

        return super.checkTabAccess(item);
    }
}