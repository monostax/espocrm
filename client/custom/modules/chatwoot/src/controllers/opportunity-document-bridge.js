/************************************************************************
 * This file is part of Monostax.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 ************************************************************************/

import Controller from "controller";

export default class OpportunityDocumentBridgeController extends Controller {
    actionIndex() {
        this.main("chatwoot:views/opportunity/document-bridge", {});
    }
}
