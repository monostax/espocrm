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
import StageRequirements from "global:helpers/opportunity-stage-requirements";

class OpportunityEditRecordView extends GlobalEditRecordView {
    mandatorySelectAttributeList = ["opportunityStageName", "opportunityStageStyle"];

    setup() {
        super.setup();
        StageRequirements.install(this);
    }
}

export default OpportunityEditRecordView;
