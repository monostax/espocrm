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
 * Bottom panel on the (virtual) WhatsAppBusinessAccount detail view that
 * shows the Meta CAPI dataset binding for this WABA, if any.
 *
 * WhatsAppBusinessAccount has no DB table, so there is no ORM relation to the
 * concrete MetaCapiDatasetSource. Instead this panel queries MetaCapiDatasetSource
 * via the standard list API filtered by wabaId (unique → at most one row),
 * mirroring the phone-numbers / message-templates panels in this module.
 */
define('feature-meta-whatsapp-business:views/whatsapp-business-account/panels/capi-dataset',
    ['views/record/panels/bottom', 'collection'],
    function (Dep, Collection) {

    return Dep.extend({

        template: 'record/panels/relationship',

        name: 'capiDataset',

        label: 'CAPI Dataset',

        scope: 'MetaCapiDatasetSource',

        rowActionsView: false,

        listLayout: [
            { name: 'metaCapiDataset', width: 50 },
            { name: 'sourceName', width: 30 },
            { name: 'sourceId', width: 20 }
        ],

        setup: function () {
            Dep.prototype.setup.call(this);

            this.wait(true);

            this.collection = this.createCollection();

            this.listenTo(this.model, 'sync', () => {
                this.loadDataset();
            });

            this.loadDataset();
        },

        createCollection: function () {
            const collection = new Collection();
            collection.entityType = 'MetaCapiDatasetSource';
            collection.name = 'MetaCapiDatasetSource';
            collection.maxSize = 10;
            return collection;
        },

        loadDataset: function () {
            const wabaId = this.model.get('wabaId');

            if (!wabaId) {
                this.wait(false);
                return;
            }

            Espo.Ajax.getRequest('MetaCapiDatasetSource', {
                where: [
                    { type: 'equals', attribute: 'channel', value: 'whatsapp' },
                    { type: 'equals', attribute: 'sourceId', value: wabaId }
                ],
                maxSize: 10,
            })
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
    });
});
