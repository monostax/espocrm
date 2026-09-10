/************************************************************************
 * This file is part of Monostax. PROPRIETARY AND CONFIDENTIAL.
 ************************************************************************/

// Reuse the existing per-link Internal Notes editor. Its modal edits a
// transient User model and persists description in <field>Columns, so the
// same view works for task-assignee relationships without duplicating the UI.
define('chatwoot:views/account-user-membership/fields/task-assignees', [
    'chatwoot:views/account-user-membership/fields/funnels-to-manage',
], function (Dep) {
    return Dep.extend({
        setup: function () {
            Dep.prototype.setup.call(this);
            this.panelDefs = Object.assign({}, this.panelDefs, {
                selectHandler: 'chatwoot:handlers/chatwoot-account-user-membership/select-task-assignee',
                create: false,
            });
        },
    });
});
