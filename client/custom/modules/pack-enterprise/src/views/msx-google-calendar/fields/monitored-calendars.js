/**
 * Field view for selecting monitored Google Calendars.
 * Adapted from google:views/google/fields/monitored-calendars.
 */
define('pack-enterprise:views/msx-google-calendar/fields/monitored-calendars', ['views/fields/link-multiple'], function (Dep) {

    return Dep.extend({

        nameHashName: null,
        idsName: null,
        nameHash: null,

        events: {
            'click [data-action="selectLink"]': function () {
                var self = this;

                this.notify('Please wait...');

                this.createView(
                    'modal',
                    'pack-enterprise:views/msx-google-calendar/modals/select-calendar',
                    {
                        calendars: this.model.calendarList,
                    },
                    function (view) {
                        self.notify(false);
                        view.render();

                        self.listenToOnce(view, 'select', function (calendar) {
                            view.close();
                            self.addCalendar(calendar);
                        });
                    }
                );
            },
            'click [data-action="clearLink"]': function (e) {
                this.clearLink(e);
            },
        },

        addCalendar: function (calendarId) {
            this.addLink(calendarId, this.model.calendarList[calendarId]);
        },

        clearLink: function (e) {
            var id = $(e.currentTarget).data('id').toString();
            this.deleteLink(id);
        },

        setup: function () {
            this.nameHashName = this.name + 'Names';
            this.idsName = this.name + 'Ids';

            this.ids = Espo.Utils.clone(this.model.get(this.idsName) || []);
            this.nameHash = Espo.Utils.clone(
                this.model.get(this.nameHashName) || {}
            );

            this.listenTo(
                this.model,
                'change:' + this.idsName,
                function () {
                    this.ids = Espo.Utils.clone(this.model.get(this.idsName) || []);
                    this.nameHash = Espo.Utils.clone(
                        this.model.get(this.nameHashName) || {}
                    );
                }.bind(this)
            );
        },

        afterRender: function () {
            this.renderLinks();
        },

        deleteLinkHtml: function (id) {
            var explodedId = id.split('@');
            var newId = explodedId[0].replace('.', '\\.');
            this.$el.find('.link-' + newId).remove();
        },

        addLinkHtml: function (id, name) {
            var container = this.$el.find('.link-container');
            var explodedId = id.split('@');
            var $el = $('<div />')
                .addClass('link-' + explodedId[0])
                .addClass('list-group-item');

            $el.html(name + '&nbsp');

            let escapedId = this.getHelper().escapeString(id);

            $el.append(
                '<a role="button" class="pull-right" data-id="' +
                    escapedId +
                    '" data-action="clearLink">' +
                    '<span class="fas fa-times"></a>'
            );

            container.append($el);

            return $el;
        },

        fetch: function () {
            var data = {};

            if (this.$el.is(':visible')) {
                data[this.idsName] = this.ids;
                data[this.nameHashName] = this.nameHash;
            } else {
                data[this.idsName] = null;
                data[this.nameHashName] = null;
            }

            if (Array.isArray(data[this.nameHashName])) {
                data[this.nameHashName] = null;
            }

            return data;
        },

        validateRequired: function () {
            if (this.$el.is(':visible') && this.model.isRequired(this.name)) {
                if (this.model.get(this.idsName).length === 0) {
                    var msg = this.translate('fieldIsRequired', 'messages').replace(
                        '{field}',
                        this.translate(this.name, 'fields', this.model.name)
                    );

                    this.showValidationMessage(msg);

                    return true;
                }
            }
        },
    });
});
