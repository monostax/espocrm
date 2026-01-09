/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('waha:views/waha-session/panels/apps', ['views/record/panels/bottom', 'collection'], function (Dep, Collection) {

    return Dep.extend({

        template: 'record/panels/relationship',

        name: 'apps',

        label: 'Apps',

        scope: 'WahaSessionApp',

        rowActionsView: 'views/record/row-actions/relationship-no-unlink',

        buttonList: [
            {
                action: 'createApp',
                title: 'Create',
                acl: 'create',
                aclScope: 'WahaSessionApp',
                html: '<span class="fas fa-plus"></span>'
            },
            {
                action: 'refreshApps',
                title: 'Refresh',
                html: '<span class="fas fa-sync"></span>'
            }
        ],

        listLayout: [
            { name: 'wahaAppId', width: 25 },
            { name: 'appType', width: 20 },
            { name: 'enabled', width: 15 }
        ],

        setup: function () {
            Dep.prototype.setup.call(this);
            
            this.wait(true);

            this.collection = this.createCollection();
            
            this.listenTo(this.model, 'sync', () => {
                this.loadApps();
            });

            this.loadApps();
        },

        createCollection: function () {
            const collection = new Collection();
            collection.entityType = 'WahaSessionApp';
            collection.name = 'WahaSessionApp';
            collection.maxSize = 20;
            return collection;
        },

        loadApps: function () {
            const sessionId = this.model.id;

            if (!sessionId) {
                this.wait(false);
                return;
            }

            Espo.Ajax.getRequest(`WahaSession/${sessionId}/apps`)
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
                el: this.getSelector() + ' .list-container',
                skipBuildRows: true
            }, view => {
                view.render();
            });
        },

        actionCreateApp: function () {
            const sessionId = this.model.id;
            const parts = sessionId.split('_');
            const platformId = parts[0];
            const sessionName = parts.slice(1).join('_');

            this.createView('quickCreate', 'waha:views/waha-session-app/modals/edit', {
                scope: 'WahaSessionApp',
                attributes: {
                    platformId: platformId,
                    sessionName: sessionName
                }
            }, view => {
                view.render();

                this.listenToOnce(view, 'after:save', () => {
                    this.loadApps();
                });
            });
        },

        actionRefreshApps: function () {
            this.loadApps();
        }
    });
});

