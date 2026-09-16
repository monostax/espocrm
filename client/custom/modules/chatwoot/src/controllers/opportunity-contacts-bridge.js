/************************************************************************
 * This file is part of Monostax.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 ************************************************************************/

import Controller from "controller";

class OpportunityContactsBridgeController extends Controller {
    actionIndex() {
        this.main("chatwoot:views/opportunity/contacts-bridge", {});
    }
}

export default OpportunityContactsBridgeController;
