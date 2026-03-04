/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('feature-meta-whatsapp-business:views/whatsapp-business-account/panels/subscribed-apps',
    ['views/record/panels/bottom', 'collection'],
    function (Dep, Collection) {

    return Dep.extend({

        template: 'record/panels/relationship',

        name: 'subscribedApps',

        label: 'Subscribed Apps',

        scope: 'WhatsAppBusinessAccountWebhook',

        rowActionsView: false,

        buttonList: [
            {
                action: 'refreshSubscribedApps',
                title: 'Refresh',
                html: '<span class="fas fa-sync"></span>'
            }
        ],

        listLayout: [
            { name: 'name', width: 30 },
            { name: 'appId', width: 20 },
            { name: 'overrideCallbackUri', width: 50 }
        ],

        setup: function () {
            Dep.prototype.setup.call(this);

            this.wait(true);

            this.collection = this.createCollection();

            this.listenTo(this.model, 'sync', () => {
                this.loadSubscribedApps();
            });

            this.loadSubscribedApps();
        },

        createCollection: function () {
            const collection = new Collection();
            collection.entityType = 'WhatsAppBusinessAccountWebhook';
            collection.name = 'WhatsAppBusinessAccountWebhook';
            collection.maxSize = 50;
            return collection;
        },

        loadSubscribedApps: function () {
            const parentId = this.model.id;

            if (!parentId) {
                this.wait(false);
                return;
            }

            Espo.Ajax.getRequest(`WhatsAppBusinessAccount/${parentId}/subscribedApps`)
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

        actionRefreshSubscribedApps: function () {
            this.loadSubscribedApps();
        }
    });
});
