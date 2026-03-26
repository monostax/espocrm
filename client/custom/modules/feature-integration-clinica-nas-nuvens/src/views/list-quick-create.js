/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('feature-integration-clinica-nas-nuvens:views/list-quick-create', [
    'views/list',
    'helpers/record-modal',
], function (Dep, RecordModal) {

    return Dep.extend({
        quickCreate: true,

        actionQuickCreate: function (data) {
            data = data || {};

            var attributes = this.getCreateAttributes() || {};

            var returnDispatchParams = {
                controller: this.scope,
                action: null,
                options: {isReturn: true},
            };

            this.prepareCreateReturnDispatchParams(returnDispatchParams);

            var helper = new RecordModal();

            return helper.showCreate(this, {
                entityType: this.scope,
                attributes: attributes,
                focusForCreate: data.focusForCreate,
                fullFormDisabled: true,
                returnUrl: this.getRouter().getCurrentUrl(),
                returnDispatchParams: returnDispatchParams,
                afterSave: function () {
                    this.collection.fetch();
                }.bind(this),
            });
        },
    });
});
