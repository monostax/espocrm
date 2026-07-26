define('feature-journey:views/journey-record/record/kanban', ['views/record/kanban'], function (Dep) {
    return Dep.extend({
        statusField: 'currentStageId',

        currentJourneyId: null,
        currentJourneyName: null,
        _isFetching: false,

        setup: function () {
            this.currentJourneyId =
                this.options.journeyId ||
                this.getStorage().get('state', 'journeyRecordKanbanJourneyId') ||
                null;

            this.currentJourneyName =
                this.getStorage().get('state', 'journeyRecordKanbanJourneyName') ||
                null;

            Dep.prototype.setup.call(this);

            this.statusField = 'currentStageId';
            this.orderDisabled = true;

            if (!this.currentJourneyId) {
                this.loadDefaultJourney();
            } else {
                this.applyJourneyFilter(false);
            }

            this.addActionHandler('selectJourney', () => this.actionSelectJourney());
        },

        loadDefaultJourney: function () {
            Espo.Ajax.getRequest('Journey', {
                select: 'id,name,status',
                where: [
                    {
                        type: 'in',
                        attribute: 'status',
                        value: ['Active', 'Paused', 'Draft'],
                    },
                ],
                orderBy: 'name',
                order: 'asc',
                maxSize: 1,
            })
                .then((response) => {
                    if (response.list && response.list.length > 0) {
                        const journey = response.list[0];
                        this.setJourney(journey.id, journey.name);
                    }
                })
                .catch(() => {});
        },

        setJourney: function (journeyId, journeyName) {
            if (this.currentJourneyId === journeyId) {
                return;
            }

            this.currentJourneyId = journeyId;
            this.currentJourneyName = journeyName;

            this.getStorage().set('state', 'journeyRecordKanbanJourneyId', journeyId);
            this.getStorage().set('state', 'journeyRecordKanbanJourneyName', journeyName);

            this.updateJourneySelectorDisplay();
            this.applyJourneyFilter(true);
        },

        applyJourneyFilter: function (fetch) {
            if (!this.collection) {
                return;
            }

            const where = this.collection.where || [];
            const filteredWhere = where.filter(
                (item) => item.attribute !== 'journeyId' && item.attribute !== 'journey'
            );

            if (this.currentJourneyId) {
                filteredWhere.push({
                    type: 'equals',
                    attribute: 'journeyId',
                    value: this.currentJourneyId,
                });
            }

            this.collection.where = filteredWhere;

            if (fetch === false || this._isFetching) {
                return;
            }

            this._isFetching = true;

            this.collection
                .fetch()
                .then(() => {
                    this._isFetching = false;
                })
                .catch(() => {
                    this._isFetching = false;
                });
        },

        actionSelectJourney: function () {
            const viewName =
                this.getMetadata().get(['clientDefs', 'Journey', 'modalViews', 'select']) ||
                'views/modals/select-records';

            this.createView('dialog', viewName, {
                scope: 'Journey',
                multiple: false,
                createButton: false,
                forceSelectAllAttributes: true,
            }).then((view) => {
                view.render();

                this.listenToOnce(view, 'select', (model) => {
                    this.setJourney(model.id, model.get('name'));
                    view.close();
                });
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);
            this.ensureJourneySelector();
            this.updateJourneySelectorDisplay();
        },

        ensureJourneySelector: function () {
            if (!this.$el || !this.$el.length) {
                return;
            }

            if (this.$el.find('.journey-selector').length) {
                return;
            }

            const label =
                this.translate('Select Journey', 'labels', 'Global') || 'Select Journey';

            const html =
                '<div class="journey-selector" style="margin:0 0 0.75rem 0;">' +
                '<button type="button" class="btn btn-default btn-sm action" data-action="selectJourney">' +
                '<span class="fas fa-route"></span> ' +
                '<span class="journey-selector-name">' +
                Espo.Utils.escapeString(this.currentJourneyName || label) +
                '</span></button>' +
                '<span class="text-muted small" style="margin-left:0.5rem;">' +
                '(read-only board — stage moves via transitions)</span></div>';

            this.$el.prepend(html);
        },

        updateJourneySelectorDisplay: function () {
            if (!this.$el || !this.$el.length) {
                return;
            }

            const $selector = this.$el.find('.journey-selector-name');
            const label =
                this.translate('Select Journey', 'labels', 'Global') || 'Select Journey';

            if ($selector.length) {
                $selector.text(this.currentJourneyName || label);
            }
        },

        initSortable: function () {
            // Read-only: stage changes must go through TransitionExecutor.
        },

        handleAttributesOnGroupChange: function () {},
    });
});
