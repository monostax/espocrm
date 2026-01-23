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
 * Custom Calendar Controller with Resource Calendar Support
 *
 * @module custom/modules/global/controllers/calendar
 */

import CalendarControllerBase from 'crm:controllers/calendar';

class CalendarController extends CalendarControllerBase {

    actionIndex(options) {
        this.handleCheckAccess('');

        // Use custom calendar page view
        const viewName = this.getMetadata().get(['clientDefs', 'Calendar', 'calendarPageView']) ||
            'global:views/calendar/calendar-page';

        this.main(viewName, {
            date: options.date,
            mode: options.mode,
            userId: options.userId,
            userName: options.userName,
        });
    }
}

export default CalendarController;
