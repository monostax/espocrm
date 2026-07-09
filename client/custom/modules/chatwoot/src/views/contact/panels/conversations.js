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
 * Clicking a row opens the Chatwoot conversation drawer (iframe).
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
    ['views/record/panels/bottom'],
    function (Dep) {

    return Dep.extend({

        template: 'record/panels/relationship',

        name: 'contactConversations',

        scope: 'ChatwootConversation',

        rowActionsView: false,

        layoutName: 'listForContactPanel',

        recordsPerPage: 5,

        buttonList: [
            {
                action: 'refreshConversations',
                title: 'Refresh',
                html: '<span class="fas fa-sync"></span>'
            }
        ],

        actionList: [
            {
                label: 'Select',
                labelTranslation: 'Global.labels.selectRelated',
                action: 'selectConversation',
                acl: 'edit'
            }
        ],

        setup: function () {
            Dep.prototype.setup.call(this);

            var iconHtml = this.getHelper().getScopeColorIconHtml('ChatwootConversation');

            this.titleHtml = iconHtml +
                this.translate('Conversations', 'labels', this.model.entityType);

            this.contactIdAttribute = (this.options.defs || {}).contactIdAttribute || 'contactId';
            this.contactTypeAttribute = (this.options.defs || {}).contactTypeAttribute;
            this.requiredContactType = (this.options.defs || {}).requiredContactType;

            this.hasAccess = this.getAcl().check('Contact', 'read')
                && this.getAcl().check('ChatwootConversation', 'read');

            // The contact id attribute may not be set yet when the panel is
            // created (e.g. when the detail view is opened from a list/kanban
            // view the model carries only list-layout attributes and the full
            // record arrives later via fetch). React to its arrival/changes.
            this.listenTo(
                this.model,
                'change:' + this.contactIdAttribute,
                this.controlContactChange,
                this
            );

            if (this.contactTypeAttribute) {
                this.listenTo(
                    this.model,
                    'change:' + this.contactTypeAttribute,
                    this.controlContactChange,
                    this
                );
            }

            this.hasData = this.checkHasData();

            if (!this.hasData) {
                return;
            }

            this.wait(true);

            this.createCollection(function () {
                this.loadConversations();
            }.bind(this));
        },

        /**
         * Whether the panel has a linked contact to fetch conversations for.
         *
         * @return {boolean}
         */
        checkHasData: function () {
            var contactId = this.model.get(this.contactIdAttribute);
            var contactTypeMatches = !this.requiredContactType ||
                this.model.get(this.contactTypeAttribute) === this.requiredContactType;

            return !!contactId && contactTypeMatches && this.hasAccess;
        },

        /**
         * @param {function} callback
         */
        createCollection: function (callback) {
            this.getCollectionFactory().create('ChatwootConversation', function (collection) {
                collection.maxSize = this.recordsPerPage;
                this.collection = collection;

                callback();
            }.bind(this));
        },

        /**
         * Re-evaluate the linked contact after the model attributes changed
         * (initial fetch completed or the contact link was changed/removed).
         */
        controlContactChange: function () {
            var hasData = this.checkHasData();

            if (!hasData && !this.hasData) {
                return;
            }

            this.hasData = hasData;

            if (!hasData) {
                if (this.isRendered()) {
                    this.reRender();
                }

                return;
            }

            if (this.collection) {
                this.loadConversations();

                return;
            }

            this.createCollection(function () {
                this.loadConversations();
            }.bind(this));
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
                selectable: true,
                checkboxes: false,
                rowActionsView: this.rowActionsView,
                buttonsDisabled: true,
                displayTotalCount: false,
                el: this.getSelector() + ' .list-container',
            }, function (view) {
                this.listenTo(view, 'select', function (model) {
                    this.openConversationDrawer(model);
                }.bind(this));

                view.render();

                // Make entire row clickable (not just <a> links).
                // Re-bind on each render to avoid stacking handlers.
                this.$el.off('click', '.list-row');
                this.$el.on('click', '.list-row', function (e) {
                    if ($(e.target).closest('a.link').length) {
                        // Already handled by the selectable handler.
                        return;
                    }

                    e.preventDefault();
                    e.stopPropagation();

                    var id = $(e.currentTarget).attr('data-id');

                    if (id && this.collection) {
                        var model = this.collection.get(id);

                        if (model) {
                            this.openConversationDrawer(model);
                        }
                    }
                }.bind(this));
            }.bind(this));
        },

        /**
         * Open the Chatwoot conversation drawer for the selected conversation.
         *
         * @param {Object} model - The ChatwootConversation Backbone model.
         */
        openConversationDrawer: function (model) {
            this.createView(
                'conversationDrawer',
                'chatwoot:views/chatwoot-conversation/modals/conversation-drawer',
                {
                    chatwootConversationId: model.get('chatwootConversationId'),
                    chatwootAccountIdExternal: model.get('chatwootAccountIdExternal'),
                    contactName: model.get('contactDisplayName') || model.get('name'),
                    recordId: model.id,
                },
                function (view) {
                    view.render();

                    this.listenToOnce(view, 'close', function () {
                        this.loadConversations();
                    }.bind(this));
                }.bind(this)
            );
        },

        actionRefreshConversations: function () {
            this.loadConversations();
        },

        /**
         * Open a select-records modal to link an existing ChatwootConversation
         * to the Contact behind this panel.
         */
        actionSelectConversation: function () {
            var contactId = this.model.get(this.contactIdAttribute);

            if (!contactId) {
                Espo.Ui.warning(this.translate('No Contact linked', 'labels', this.model.entityType));

                return;
            }

            Espo.Ui.notifyWait();

            this.createView('dialogSelectRelated', 'views/modals/select-records', {
                scope: 'ChatwootConversation',
                multiple: true,
                createButton: false,
            }, function (view) {
                view.render();

                Espo.Ui.notify(false);

                this.listenToOnce(view, 'select', function (selectObj) {
                    var ids = [];

                    if (Object.prototype.toString.call(selectObj) === '[object Array]') {
                        selectObj.forEach(function (model) {
                            ids.push(model.id);
                        });
                    } else if (selectObj && selectObj.id) {
                        ids.push(selectObj.id);
                    }

                    if (!ids.length) {
                        return;
                    }

                    var url = 'Contact/' + contactId + '/chatwootConversations';

                    Espo.Ajax.postRequest(url, {ids: ids})
                        .then(function () {
                            Espo.Ui.success(this.translate('Linked'));

                            this.loadConversations();
                        }.bind(this));
                }.bind(this));
            }.bind(this));
        },
    });
});
