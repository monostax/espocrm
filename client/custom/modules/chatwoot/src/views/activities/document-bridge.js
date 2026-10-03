/************************************************************************
 * This file is part of Monostax.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 ************************************************************************/

import RecordDocumentBridgeView from "chatwoot:views/record/document-bridge";

export default class ActivityDocumentBridgeView extends RecordDocumentBridgeView {
    getRecordIdentity(context) {
        const activity = context?.activity;
        if (["Task", "Call", "Meeting"].includes(activity?.entityType)) return activity;
    }
}
