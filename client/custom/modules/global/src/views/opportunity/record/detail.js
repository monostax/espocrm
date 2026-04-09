/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("global:views/opportunity/record/detail", [
    "views/record/detail",
], function (Dep) {
    return Dep.extend({
        mandatorySelectAttributeList: ["opportunityStageName", "opportunityStageStyle"],

        setup: function () {
            Dep.prototype.setup.call(this);

            // Re-render opportunityStage field after model syncs (for detailSmall modal)
            this.listenTo(this.model, 'sync', () => {
                if (this.model.get('opportunityStageId') && this.model.get('opportunityStageName')) {
                    const fieldsView = this.getView('fields');
                    if (fieldsView) {
                        const fieldView = fieldsView.getView('opportunityStage');
                        if (fieldView && fieldView.isRendered()) {
                            fieldView.reRender();
                        }
                    }
                }
            });
        },
    });
});
