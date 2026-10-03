/************************************************************************
 * This file is part of Monostax.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 ************************************************************************/

import Controller from "controller";

export default class ActivityDocumentBridgeController extends Controller {
    actionIndex() {
        this.main("chatwoot:views/activities/document-bridge", {});
    }
}
