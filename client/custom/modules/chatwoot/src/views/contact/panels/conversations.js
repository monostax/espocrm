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
 * Side panel that displays ChatwootConversation records belonging to the
 * Contact linked to the current entity.
 *
 * Designed for entities that do NOT have a direct link to ChatwootConversation
 * but DO have a contactId on the model (via a direct belongsTo link or a
 * ReadHook like PopulateContactFromPaciente).
 *
 * Fetches conversations via the Contact's relationship endpoint:
 *   GET Contact/{contactId}/chatwootConversations
 *
 * Usage in clientDefs JSON:
 * {
 *     "sidePanels": {
 *         "detail": [
 *             {
 *                 "name": "contactConversations",
 *                 "label": "Conversations",
 *                 "view": "chatwoot:views/contact/panels/conversations",
 *                 "order": 3
 *             }
 *         ]
 *     }
 * }
 */
define('chatwoot:views/contact/panels/conversations',
    ['views/record/panels/bottom', 'collection'],
    function (Dep, Collection) {

    return Dep.extend({

        template: 'record/panels/relationship',

        name: 'contactConversations',

        scope: 'ChatwootConversation',

        rowActionsView: false,

        layoutName: 'listSmall',

        recordsPerPage: 5,

        buttonList: [
            {
                action: 'refreshConversations',
                title: 'Refresh',
                html: '<span class="fas fa-sync"></span>'
            }
        ],

        setup: function () {
            Dep.prototype.setup.call(this);

            this.contactIdAttribute = (this.options.defs || {}).contactIdAttribute || 'contactId';

            var contactId = this.model.get(this.contactIdAttribute);
            var hasAccess = this.getAcl().check('Contact', 'read')
                && this.getAcl().check('ChatwootConversation', 'read');

            this.hasData = !!contactId && hasAccess;

            if (!this.hasData) {
                return;
            }

            this.wait(true);

            this.collection = new Collection();
            this.collection.entityType = 'ChatwootConversation';
            this.collection.name = 'ChatwootConversation';
            this.collection.maxSize = this.recordsPerPage;

            this.loadConversations();
        },

        loadConversations: function () {
            var contactId = this.model.get(this.contactIdAttribute);

            if (!contactId) {
                this.wait(false);
                return;
            }

            var url = 'Contact/' + contactId + '/chatwootConversations';

            Espo.Ajax.getRequest(url, {
                maxSize: this.recordsPerPage,
                orderBy: 'lastActivityAt',
                order: 'desc',
            })
                .then(function (response) {
                    this.collection.reset(response.list || []);
                    this.collection.total = response.total || 0;
                    this.wait(false);

                    if (this.isRendered()) {
                        this.reRender();
                    }
                }.bind(this))
                .catch(function () {
                    this.collection.reset([]);
                    this.collection.total = 0;
                    this.wait(false);

                    if (this.isRendered()) {
                        this.reRender();
                    }
                }.bind(this));
        },

        afterRender: function () {
            if (!this.hasData) {
                this.$el.find('.list-container').html(
                    '<span class="text-muted small">' +
                    this.translate('No Contact linked', 'labels', this.model.entityType) +
                    '</span>'
                );
                return;
            }

            if (this.collection.length === 0 && this.collection.total === 0) {
                this.$el.find('.list-container').html(
                    '<span class="text-muted small">' +
                    this.translate('No Data') +
                    '</span>'
                );
                return;
            }

            this.createView('list', 'views/record/list', {
                collection: this.collection,
                layoutName: this.layoutName,
                listLayout: null,
                checkboxes: false,
                rowActionsView: this.rowActionsView,
                buttonsDisabled: true,
                headerDisabled: true,
                el: this.getSelector() + ' .list-container',
            }, function (view) {
                view.render();
            });
        },

        actionRefreshConversations: function () {
            this.loadConversations();
        },
    });
});
