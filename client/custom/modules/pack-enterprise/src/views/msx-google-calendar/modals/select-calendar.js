/**
 * Modal for selecting a Google Calendar from the user's calendar list.
 * Adapted from google:views/google/modals/select-calendar.
 */
define('pack-enterprise:views/msx-google-calendar/modals/select-calendar', ['views/modal'], function (Dep) {

    return Dep.extend({

        cssName: 'select-folder-modal',

        template: 'pack-enterprise:msx-google-calendar/modals/select-calendar',

        data: function () {
            return {
                calendars: this.options.calendars,
            };
        },

        events: {
            'click button[data-action="select"]': function (e) {
                var value = $(e.currentTarget).data('value');
                this.trigger('select', value);
            },
        },

        setup: function () {
            this.buttonList = [
                {
                    name: 'cancel',
                    label: 'Cancel',
                },
            ];
        },
    });
});
