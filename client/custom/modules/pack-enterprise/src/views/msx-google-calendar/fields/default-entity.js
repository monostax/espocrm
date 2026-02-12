/**
 * Dynamic enum field for selecting the default entity type.
 * Options are built from the calendarEntityTypes field value,
 * so only entities the user has selected for sync are available.
 */
define('pack-enterprise:views/msx-google-calendar/fields/default-entity', ['views/fields/enum'], function (Dep) {

    return Dep.extend({

        setup: function () {
            this.buildOptions();

            Dep.prototype.setup.call(this);

            this.listenTo(this.model, 'change:calendarEntityTypes', function () {
                this.buildOptions();
                this.reRender();
            }, this);
        },

        buildOptions: function () {
            var calendarEntityTypes = this.model.get('calendarEntityTypes') || [];

            // If no entity types selected yet, fall back to activity/calendar scopes.
            if (calendarEntityTypes.length === 0) {
                var scopes = this.getMetadata().get('scopes') || {};

                calendarEntityTypes = Object.keys(scopes).filter(function (scope) {
                    if (scope === 'Email') return false;
                    if (scopes[scope].disabled) return false;
                    if (!scopes[scope].object) return false;
                    if (!scopes[scope].entity) return false;
                    if (!scopes[scope].activity || !scopes[scope].calendar) return false;

                    return true;
                });
            }

            this.params.options = calendarEntityTypes;

            // Build translation map from Global.scopeNames.
            var translatedOptions = {};

            calendarEntityTypes.forEach(function (scope) {
                translatedOptions[scope] = this.translate(scope, 'scopeNames');
            }, this);

            this.translatedOptions = translatedOptions;

            // If current value is not in options, clear it.
            var currentValue = this.model.get(this.name);

            if (currentValue && calendarEntityTypes.indexOf(currentValue) === -1) {
                this.model.set(this.name, calendarEntityTypes.length > 0 ? calendarEntityTypes[0] : null);
            }

            // Set default if empty.
            if (!this.model.get(this.name) && calendarEntityTypes.length > 0) {
                if (calendarEntityTypes.indexOf('Meeting') !== -1) {
                    this.model.set(this.name, 'Meeting');
                } else {
                    this.model.set(this.name, calendarEntityTypes[0]);
                }
            }
        },
    });
});
