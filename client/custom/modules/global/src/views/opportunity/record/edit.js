/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax - Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import GlobalEditRecordView from "global:views/record/edit";

class OpportunityEditRecordView extends GlobalEditRecordView {
    mandatorySelectAttributeList = ["opportunityStageName", "opportunityStageStyle"];
}

export default OpportunityEditRecordView;
