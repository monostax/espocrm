/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Modal editor for the per-calendar `description` additionalColumn on
 * ChatwootAccountUserMembership.calendarsToManage. Hosts a `views/fields/wysiwyg`
 * bound to a transient User model so the edit experience matches the stock
 * `aiPrompt` field on the same entity.
 *
 * On save the modal triggers `save` with the current HTML value; the parent
 * field view (`calendars-to-manage.js`) writes it back into the
 * `calendarsToManageColumns[<id>].description` attribute on the host model.
 *
 * The HTML is later stripped to plain text by `stripWysiwygHtml` in
 * `drizzle.crm.app/helpers.ts` before reaching the AI agent.
 */
define('chatwoot:views/account-user-membership/modals/edit-calendar-description', [
    'views/modal',
    'model',
], function (Dep, Model) {

    return Dep.extend({

        template: 'chatwoot:account-user-membership/modals/edit-calendar-description',

        cssName: 'edit-calendar-description-modal',

        backdrop: true,

        fitHeight: true,

        data: function () {
            return {};
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.calendarId = this.options.calendarId;
            this.calendarName = this.options.calendarName || '';
            this.value = this.options.value || '';

            this.headerText = this.translate('Edit Internal Notes', 'labels', 'ChatwootAccountUserMembership');

            if (this.calendarName) {
                this.headerText += ' — ' + this.calendarName;
            }

            // Transient User-scoped model so wysiwyg lookups (e.g. tooltip,
            // language strings) resolve against `User.calendarDescription`.
            this.formModel = new Model();
            this.formModel.name = 'User';
            this.formModel.entityType = 'User';
            this.formModel.set('calendarDescription', this.value);

            this.buttonList = [
                {
                    name: 'save',
                    label: 'Save',
                    style: 'primary',
                    onClick: () => this.actionSave(),
                },
                {
                    name: 'cancel',
                    label: 'Cancel',
                    onClick: () => this.close(),
                },
            ];

            this.createView('description', 'views/fields/wysiwyg', {
                selector: '.field[data-name="description"]',
                model: this.formModel,
                mode: 'edit',
                defs: {
                    name: 'calendarDescription',
                    params: {
                        height: 240,
                    },
                },
            });
        },

        actionSave: function () {
            const fieldView = this.getView('description');

            if (fieldView) {
                fieldView.fetchToModel();
            }

            const newValue = this.formModel.get('calendarDescription') || '';

            this.trigger('save', {
                calendarId: this.calendarId,
                value: newValue,
            });

            this.close();
        },
    });
});
