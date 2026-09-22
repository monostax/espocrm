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
 * Side panel that displays ChatwootConversation records linked to the current
 * entity or to its Contact.
 *
 * Set `link: "chatwootConversations"` in the panel defs for entities with a
 * direct relationship (e.g. Opportunity). Both listing and selection then use:
 *   {entityType}/{id}/chatwootConversations
 *
 * Otherwise, uses contactId (or contactIdAttribute) on the model and fetches:
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
            this.link = (this.options.defs || {}).link;

            this.hasAccess = this.getAcl().check(this.link ? this.model.entityType : 'Contact', 'read')
                && this.getAcl().check('ChatwootConversation', 'read');

            // The contact id attribute may not be set yet when the panel is
            // created (e.g. when the detail view is opened from a list/kanban
            // view the model carries only list-layout attributes and the full
            // record arrives later via fetch). React to its arrival/changes.
            this.listenTo(
                this.model,
                this.link
                    ? 'change:id update-related:' + this.link + ' update-all'
                    : 'change:' + this.contactIdAttribute,
                this.controlContactChange,
                this
            );

            if (!this.link && this.contactTypeAttribute) {
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
         * Whether the panel has an accessible relationship to fetch.
         *
         * @return {boolean}
         */
        checkHasData: function () {
            return this.hasAccess && !!this.getConversationsUrl();
        },

        /**
         * Resolve the same relationship for listing and linking conversations.
         *
         * @return {string|null}
         */
        getConversationsUrl: function () {
            if (this.link) {
                return this.model.id
                    ? this.model.entityType + '/' + this.model.id + '/' + this.link
                    : null;
            }

            var contactId = this.model.get(this.contactIdAttribute);
            var contactTypeMatches = !this.requiredContactType ||
                this.model.get(this.contactTypeAttribute) === this.requiredContactType;

            return contactId && contactTypeMatches
                ? 'Contact/' + contactId + '/chatwootConversations'
                : null;
        },

        /**
         * @param {function} callback
         */
        createCollection: function (callback) {
            this.getCollectionFactory().create('ChatwootConversation', function (collection) {
                collection.maxSize = this.recordsPerPage;
                collection.setOrder('lastActivityAt', 'desc', true);
                this.collection = collection;

                callback();
            }.bind(this));
        },

        /**
         * Re-evaluate the source after model attributes or direct links change.
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
            var url = this.getConversationsUrl();

            if (!url || !this.collection || !this.hasAccess) {
                this.wait(false);
                return;
            }

            // Sorting and Show More use collection.fetch, so they must stay on
            // the same relationship as the initial request.
            this.collection.url = this.collection.urlRoot = url;

            return Espo.Ajax.getRequest(url, {
                maxSize: this.recordsPerPage,
                orderBy: this.collection.orderBy,
                order: this.collection.order,
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
                    (this.link
                        ? this.translate('No Data')
                        : this.translate('No Contact linked', 'labels', this.model.entityType)) +
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
            return this.loadConversations();
        },

        actionRefresh: function () {
            return this.loadConversations();
        },

        /**
         * Open a select-records modal to link an existing ChatwootConversation
         * to the same entity whose conversations are displayed by this panel.
         */
        actionSelectConversation: function () {
            var url = this.getConversationsUrl();

            if (!url) {
                Espo.Ui.warning(this.link
                    ? this.translate('No Data')
                    : this.translate('No Contact linked', 'labels', this.model.entityType));

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

                    Espo.Ajax.postRequest(url, {ids: ids})
                        .then(function () {
                            Espo.Ui.success(this.translate('Linked'));

                            if (this.link) {
                                this.model.trigger('update-related:' + this.link);
                                this.model.trigger('after:relate');
                                this.model.trigger('after:relate:' + this.link);
                            } else {
                                this.loadConversations();
                            }
                        }.bind(this));
                }.bind(this));
            }.bind(this));
        },
    });
});
