/**
 * Field view for entity types with identification labels.
 * Adapted from google:views/google/fields/labeled-array.
 */
define('pack-enterprise:views/msx-google-calendar/fields/labeled-array', ['views/fields/array'], function (Dep) {

    return Dep.extend({

        data: function () {
            var itemHtmlList = [];

            (this.selected || []).forEach(function (value) {
                itemHtmlList.push(this.getItemHtml(value));
            }, this);

            return _.extend(
                {
                    selected: this.selected,
                    translatedOptions: this.translatedOptions,
                    hasOptions: this.params.options ? true : false,
                    itemHtmlList: itemHtmlList,
                },
                Dep.prototype.data.call(this)
            );
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.buildOptions();

            this.listenTo(
                this.model,
                'change:' + this.name,
                function () {
                    this.selected = Espo.Utils.clone(this.model.get(this.name));
                },
                this
            );

            this.selected = Espo.Utils.clone(this.model.get(this.name) || []);

            if (
                Object.prototype.toString.call(this.selected) !== '[object Array]'
            ) {
                this.selected = [];
            }
        },

        /**
         * Dynamically build options from scopes metadata,
         * filtering for activity/calendar entity types.
         */
        buildOptions: function () {
            var scopes = this.getMetadata().get('scopes') || {};

            var eventOptions = Object.keys(scopes)
                .filter(function (scope) {
                    if (scope === 'Email') return false;
                    if (scopes[scope].disabled) return false;
                    if (!scopes[scope].object) return false;
                    if (!scopes[scope].entity) return false;
                    if (!scopes[scope].activity || !scopes[scope].calendar) return false;

                    return true;
                })
                .sort(function (v1, v2) {
                    return this.translate(v1, 'scopeNames').localeCompare(
                        this.translate(v2, 'scopeNames')
                    );
                }.bind(this));

            this.params.options = eventOptions;

            var translatedOptions = {};

            eventOptions.forEach(function (scope) {
                translatedOptions[scope] = this.translate(scope, 'scopeNamesPlural', 'Global');
            }, this);

            this.translatedOptions = translatedOptions;
        },

        getItemHtml: function (value) {
            if (this.translatedOptions != null) {
                for (var item in this.translatedOptions) {
                    if (this.translatedOptions[item] == value) {
                        value = item;
                        break;
                    }
                }
            }

            var label = value;

            if (this.translatedOptions) {
                label =
                    value in this.translatedOptions
                        ? this.translatedOptions[value]
                        : value;
            }

            var identLabel = this.model.get(value + 'IdentificationLabel');
            var identificationLabel = value.substring(0, 1);

            if (identLabel != null) {
                identificationLabel = identLabel;
            }

            let escapedValue = this.getHelper().escapeString(value);
            let escapedLabel = this.getHelper().escapeString(label);
            let escapedIdentificationLabel =
                this.getHelper().escapeString(identificationLabel);

            var html =
                '' +
                '<div class="list-group-item link-with-role form-inline" data-value="' +
                escapedValue +
                '">' +
                '<div class="pull-left" style="width: 92%; display: inline-block;">' +
                '<input data-name="translatedValue" data-value="' +
                escapedValue +
                '" class="role form-control input-sm pull-right" value="' +
                escapedIdentificationLabel +
                '">' +
                '<div>' +
                escapedLabel +
                '</div>' +
                '</div>' +
                '<div style="width: 8%; display: inline-block; vertical-align: top;">' +
                '<a role="button" class="pull-right" data-value="' +
                escapedValue +
                '" data-action="removeValue"><span class="fas fa-times"></a>' +
                '</div><br style="clear: both;" />' +
                '</div>';

            return html;
        },

        fetch: function () {
            var data = {};

            data[this.name] = Espo.Utils.clone(this.selected || []);

            for (var key in data[this.name]) {
                var scope = data[this.name][key];

                data[scope + 'IdentificationLabel'] = this.$el
                    .find(
                        '.list-group .list-group-item input[data-name="translatedValue"][data-value="' +
                            scope +
                            '"]'
                    )
                    .val();
            }

            return data;
        },

        validateRequired: function () {
            if (
                this.params.required ||
                this.model.isRequired(this.name)
            ) {
                var value = this.model.get(this.name);

                if (!value || value.length == 0) {
                    var msg = this.translate('fieldIsRequired', 'messages').replace(
                        '{field}',
                        this.translate(this.name, 'fields', this.model.name)
                    );

                    this.showValidationMessage(msg, '.link-container');

                    return true;
                }

                var hasEmptyIdentLabel = false;

                for (var key in value) {
                    var label = this.model.get(value[key] + 'IdentificationLabel');

                    if (label == null || label == '') {
                        if (hasEmptyIdentLabel) {
                            var msg = this.translate(
                                'fieldLabelIsRequired',
                                'messages',
                                'MsxGoogleCalendar'
                            ).replace(
                                '{field}',
                                this.translate(this.name, 'fields', this.model.name)
                            );

                            this.showValidationMessage(
                                msg,
                                '[data-name="translatedValue"]:last'
                            );

                            return true;
                        }

                        hasEmptyIdentLabel = true;
                    }
                }
            }

            return false;
        },
    });
});
