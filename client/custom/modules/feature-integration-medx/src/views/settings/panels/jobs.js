/**
 * Bottom panel that displays Job records belonging to the MEDX pipeline
 * for the current FeatureIntegrationMedxSettings profile.
 *
 * Jobs are associated via the `group` field pattern: "medx-pipeline-{profileId}".
 */
define('feature-integration-medx:views/settings/panels/jobs',
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
                this.translate('Pipeline Jobs', 'labels', 'FeatureIntegrationMedxSettings');

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

            var group = 'medx-pipeline-' + profileId;

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
