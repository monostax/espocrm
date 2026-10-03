/************************************************************************
 * This file is part of Monostax.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 ************************************************************************/

import RecordDocumentBridgeView from "chatwoot:views/record/document-bridge";

export default class OpportunityDocumentBridgeView extends RecordDocumentBridgeView {
    getRecordIdentity(context) {
        return {entityType: "Opportunity", id: context?.opportunity?.id};
    }
}
