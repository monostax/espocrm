/**
 * Bottom panel that displays Job records belonging to the CNN pipeline
 * for the current FeatureIntegrationClinicaNasNuvensSettings profile.
 *
 * Jobs are associated via the `group` field pattern: "cnn-pipeline-{profileId}".
 * Since there is no direct ORM relationship between Settings and Job, this panel
 * fetches Job records via the standard Job API with a group filter.
 *
 * Usage in clientDefs JSON:
 * {
 *     "bottomPanels": {
 *         "detail": [
 *             {
 *                 "name": "pipelineJobs",
 *                 "label": "Pipeline Jobs",
 *                 "view": "feature-integration-clinica-nas-nuvens:views/settings/panels/jobs",
 *                 "order": 1
 *             }
 *         ]
 *     }
 * }
 */
define('feature-integration-clinica-nas-nuvens:views/settings/panels/jobs',
    ['views/record/panels/bottom'],
    function (Dep) {

    return Dep.extend({

        template: 'record/panels/relationship',

        name: 'pipelineJobs',

        scope: 'Job',

        rowActionsView: false,

        recordsPerPage: 10,

        buttonList: [
            {
                action: 'refreshJobs',
                title: 'Refresh',
                html: '<span class="fas fa-sync"></span>',
            },
        ],

        setup: function () {
            Dep.prototype.setup.call(this);

            this.titleHtml = '<span class="fas fa-tasks"></span> ' +
                this.translate('Pipeline Jobs', 'labels', 'FeatureIntegrationClinicaNasNuvensSettings');

            this.wait(true);

            this.getCollectionFactory().create('Job', function (collection) {
                collection.maxSize = this.recordsPerPage;
                this.collection = collection;

                this.loadJobs();
            }.bind(this));
        },

        loadJobs: function () {
            var profileId = this.model.id;

            if (!profileId) {
                this.wait(false);
                return;
            }

            var group = 'cnn-pipeline-' + profileId;

            Espo.Ajax.getRequest('Job', {
                where: [
                    {
                        type: 'equals',
                        attribute: 'group',
                        value: group,
                    },
                ],
                orderBy: 'createdAt',
                order: 'desc',
                maxSize: this.recordsPerPage,
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

        listLayout: [
            {name: 'name'},
            {name: 'status', width: 20},
            {name: 'className', width: 30},
            {name: 'executeTime', width: 20},
        ],

        afterRender: function () {
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
                listLayout: this.listLayout,
                selectable: false,
                checkboxes: false,
                rowActionsView: this.rowActionsView,
                buttonsDisabled: true,
                displayTotalCount: true,
                el: this.getSelector() + ' .list-container',
            }, function (view) {
                view.render();
            }.bind(this));

            this.$el.find('.list-row').css('cursor', 'pointer');

            this.$el.on('click', '.list-row', function (e) {
                if ($(e.target).closest('a').length) {
                    return;
                }

                e.preventDefault();
                e.stopPropagation();

                var id = $(e.currentTarget).attr('data-id');

                if (id) {
                    this.openJobDetail(id);
                }
            }.bind(this));
        },

        openJobDetail: function (id) {
            this.createView('jobDetail', 'views/admin/job/modals/detail', {
                id: id,
                scope: 'Job',
            }, function (view) {
                view.render();

                this.listenToOnce(view, 'after:delete', function () {
                    this.loadJobs();
                }.bind(this));
            }.bind(this));
        },

        actionRefreshJobs: function () {
            this.loadJobs();
        },
    });
});
