/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('feature-meta-whatsapp-business:views/whatsapp-business-account/panels/phone-numbers',
    ['views/record/panels/bottom', 'collection'],
    function (Dep, Collection) {

    return Dep.extend({

        template: 'record/panels/relationship',

        name: 'phoneNumbers',

        label: 'Phone Numbers',

        scope: 'WhatsAppBusinessAccountPhoneNumber',

        rowActionsView: false,

        buttonList: [
            {
                action: 'refreshPhoneNumbers',
                title: 'Refresh',
                html: '<span class="fas fa-sync"></span>'
            }
        ],

        listLayout: [
            { name: 'name', width: 30 },
            { name: 'displayPhoneNumber', width: 25 },
            { name: 'qualityRating', width: 20 },
            { name: 'phoneNumberId', width: 25 }
        ],

        setup: function () {
            Dep.prototype.setup.call(this);

            this.wait(true);

            this.collection = this.createCollection();

            this.listenTo(this.model, 'sync', () => {
                this.loadPhoneNumbers();
            });

            this.loadPhoneNumbers();
        },

        createCollection: function () {
            const collection = new Collection();
            collection.entityType = 'WhatsAppBusinessAccountPhoneNumber';
            collection.name = 'WhatsAppBusinessAccountPhoneNumber';
            collection.maxSize = 50;
            return collection;
        },

        loadPhoneNumbers: function () {
            const parentId = this.model.id;

            if (!parentId) {
                this.wait(false);
                return;
            }

            Espo.Ajax.getRequest(`WhatsAppBusinessAccount/${parentId}/phoneNumbers`)
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

        actionRefreshPhoneNumbers: function () {
            this.loadPhoneNumbers();
        }
    });
});
