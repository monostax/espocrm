/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('feature-meta-whatsapp-business:views/whatsapp-business-account/panels/message-templates',
    ['views/record/panels/bottom', 'collection'],
    function (Dep, Collection) {

    return Dep.extend({

        template: 'record/panels/relationship',

        name: 'messageTemplates',

        label: 'Message Templates',

        scope: 'WhatsAppBusinessAccountMessageTemplate',

        rowActionsView: false,

        buttonList: [
            {
                action: 'refreshMessageTemplates',
                title: 'Refresh',
                html: '<span class="fas fa-sync"></span>'
            }
        ],

        listLayout: [
            { name: 'name', width: 25 },
            { name: 'language', width: 10 },
            { name: 'status', width: 15 },
            { name: 'category', width: 15 },
            { name: 'templateId', width: 20 }
        ],

        setup: function () {
            Dep.prototype.setup.call(this);

            this.wait(true);

            this.collection = this.createCollection();

            this.listenTo(this.model, 'sync', () => {
                this.loadMessageTemplates();
            });

            this.loadMessageTemplates();
        },

        createCollection: function () {
            const collection = new Collection();
            collection.entityType = 'WhatsAppBusinessAccountMessageTemplate';
            collection.name = 'WhatsAppBusinessAccountMessageTemplate';
            collection.maxSize = 100;
            return collection;
        },

        loadMessageTemplates: function () {
            const parentId = this.model.id;

            if (!parentId) {
                this.wait(false);
                return;
            }

            Espo.Ajax.getRequest(`WhatsAppBusinessAccount/${parentId}/messageTemplates`)
                .then(response => {
                    this.collection.reset(response.list || []);
                    this.collection.total = response.total || 0;
                    this.wait(false);

                    if (this.isRendered()) {
                        this.reRender();
                    }
                })
                .catch(() => {
                    this.wait(false);
                    if (this.isRendered()) {
                        this.reRender();
                    }
                });
        },

        afterRender: function () {
            this.createView('list', 'views/record/list', {
                collection: this.collection,
                layoutName: 'listSmall',
                listLayout: this.listLayout,
                checkboxes: false,
                rowActionsView: this.rowActionsView,
                buttonsDisabled: true,
                el: this.getSelector() + ' .list-container'
            }, view => {
                view.render();
            });
        },

        actionRefreshMessageTemplates: function () {
            this.loadMessageTemplates();
        }
    });
});
