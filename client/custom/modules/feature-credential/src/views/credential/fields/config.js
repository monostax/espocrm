define("feature-credential:views/credential/fields/config", ["views/fields/text"], function (
    Dep,
) {
    /**
     * Custom field view for Credential.config that dynamically renders
     * form inputs based on the linked CredentialType's uiConfig.
     *
     * Supported uiConfig field types:
     *   text, password, textarea, int, enum, checkbox, json, array
     */
    return Dep.extend({

        // Cache of fetched CredentialType data keyed by ID.
        _credentialTypeCache: null,

        // Parsed uiConfig fields array.
        uiFields: null,

        // Parsed schema object (for required field validation).
        schema: null,

        // Whether we're in fallback (raw JSON) mode.
        isFallback: false,

        // language=Handlebars
        editTemplateContent:
            '<div class="config-fields-container">' +
                '{{#if isFallback}}' +
                '<textarea class="form-control config-fallback" rows="8">{{{rawValue}}}</textarea>' +
                '{{else}}' +
                '{{#unless hasUiFields}}' +
                '<span class="text-muted">{{translate "None"}}</span>' +
                '{{else}}' +
                '<div class="config-dynamic-fields"></div>' +
                '{{/unless}}' +
                '{{/if}}' +
            '</div>',

        // language=Handlebars
        detailTemplateContent:
            '{{#if isFallback}}' +
                '{{#if isNotEmpty}}<pre class="config-raw-detail">{{{rawValue}}}</pre>{{else}}<span class="none-value">{{translate "None"}}</span>{{/if}}' +
            '{{else}}' +
                '{{#if hasFields}}' +
                '<div class="config-detail-fields">' +
                    '{{#each fieldValues}}' +
                    '<div class="config-detail-row" style="margin-bottom: 6px;">' +
                        '<span class="text-muted">{{this.label}}:</span> ' +
                        '<span>{{{this.displayValue}}}</span>' +
                    '</div>' +
                    '{{/each}}' +
                '</div>' +
                '{{else}}<span class="none-value">{{translate "None"}}</span>{{/if}}' +
            '{{/if}}',

        // language=Handlebars
        listTemplateContent:
            '{{#if isNotEmpty}}<span class="text-muted" title="{{rawValue}}">[Configured]</span>{{else}}<span class="none-value">{{translate "None"}}</span>{{/if}}',

        setup: function () {
            Dep.prototype.setup.call(this);

            this._credentialTypeCache = {};
            this.uiFields = null;
            this.schema = null;
            this.isFallback = false;

            this.listenTo(this.model, 'change:credentialTypeId', function () {
                this.loadUiConfig().then(function () {
                    if (this.isRendered()) {
                        this.reRender();
                    }
                }.bind(this));
            }.bind(this));

            this.validations = ['configRequired'];

            if (this.model.get('credentialTypeId')) {
                this.wait(this.loadUiConfig());
            }
        },

        /**
         * Fetch CredentialType record and parse uiConfig + schema.
         * @returns {Promise}
         */
        loadUiConfig: function () {
            var credentialTypeId = this.model.get('credentialTypeId');

            if (!credentialTypeId) {
                this.uiFields = null;
                this.schema = null;
                this.isFallback = false;
                return Promise.resolve();
            }

            // Check cache first.
            if (this._credentialTypeCache[credentialTypeId]) {
                var cached = this._credentialTypeCache[credentialTypeId];
                this.uiFields = cached.uiFields;
                this.schema = cached.schema;
                this.isFallback = !this.uiFields || this.uiFields.length === 0;
                return Promise.resolve();
            }

            return Espo.Ajax.getRequest('CredentialType/' + credentialTypeId)
                .then(function (response) {
                    var uiFields = null;
                    var schema = null;

                    try {
                        if (response.uiConfig) {
                            var uiConfig = typeof response.uiConfig === 'string'
                                ? JSON.parse(response.uiConfig)
                                : response.uiConfig;
                            uiFields = uiConfig.fields || null;
                        }
                    } catch (e) {
                        console.warn('Failed to parse uiConfig for CredentialType', credentialTypeId, e);
                    }

                    try {
                        if (response.schema) {
                            schema = typeof response.schema === 'string'
                                ? JSON.parse(response.schema)
                                : response.schema;
                        }
                    } catch (e) {
                        console.warn('Failed to parse schema for CredentialType', credentialTypeId, e);
                    }

                    this._credentialTypeCache[credentialTypeId] = {
                        uiFields: uiFields,
                        schema: schema,
                    };

                    this.uiFields = uiFields;
                    this.schema = schema;
                    this.isFallback = !this.uiFields || this.uiFields.length === 0;
                }.bind(this))
                .catch(function (err) {
                    console.error('Failed to load CredentialType', credentialTypeId, err);
                    this.uiFields = null;
                    this.schema = null;
                    this.isFallback = true;
                }.bind(this));
        },

        /**
         * Parse the current config value from the model.
         * @returns {Object}
         */
        getConfigValues: function () {
            var raw = this.model.get(this.name);

            if (!raw) {
                return {};
            }

            if (typeof raw === 'object') {
                return raw;
            }

            try {
                var parsed = JSON.parse(raw);
                return (typeof parsed === 'object' && parsed !== null) ? parsed : {};
            } catch (e) {
                return {};
            }
        },

        data: function () {
            var data = Dep.prototype.data.call(this);
            var configValues = this.getConfigValues();
            var raw = this.model.get(this.name);

            data.isFallback = this.isFallback;
            data.hasUiFields = this.uiFields && this.uiFields.length > 0;
            data.isNotEmpty = !!raw;

            // Raw value for fallback mode.
            if (typeof raw === 'object' && raw !== null) {
                data.rawValue = JSON.stringify(raw, null, 2);
            } else {
                data.rawValue = raw || '';
            }

            // Build field values for detail mode.
            if (!this.isFallback && this.uiFields && this.uiFields.length > 0) {
                data.hasFields = Object.keys(configValues).length > 0 || this.uiFields.length > 0;
                data.fieldValues = [];

                this.uiFields.forEach(function (field) {
                    var value = configValues[field.name];
                    var displayValue;

                    if (value === undefined || value === null || value === '') {
                        displayValue = '<span class="text-muted">&mdash;</span>';
                    } else if (field.type === 'password') {
                        displayValue = '<span class="text-muted">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>';
                    } else if (field.type === 'checkbox') {
                        displayValue = value ? 'Yes' : 'No';
                    } else if (field.type === 'json' || field.type === 'array') {
                        displayValue = '<pre style="margin:0;white-space:pre-wrap;">' +
                            this.getHelper().escapeString(
                                typeof value === 'string' ? value : JSON.stringify(value, null, 2)
                            ) + '</pre>';
                    } else if (field.type === 'enum' && field.options) {
                        displayValue = this.getHelper().escapeString(field.options[value] || value);
                    } else {
                        displayValue = this.getHelper().escapeString(String(value));
                    }

                    data.fieldValues.push({
                        label: field.label || field.name,
                        displayValue: displayValue,
                    });
                }.bind(this));
            } else {
                data.hasFields = false;
                data.fieldValues = [];
            }

            return data;
        },

        afterRender: function () {
            // Don't call Dep (text) afterRender — we manage our own rendering.
            if (this.isEditMode()) {
                this.afterRenderEdit();
            }
        },

        afterRenderEdit: function () {
            if (this.isFallback) {
                // Fallback raw JSON textarea — bind change event.
                this.$el.find('.config-fallback').on('change input', function () {
                    this.trigger('change', {ui: true});
                }.bind(this));
                return;
            }

            if (!this.uiFields || this.uiFields.length === 0) {
                return;
            }

            var configValues = this.getConfigValues();
            var $container = this.$el.find('.config-dynamic-fields');

            $container.empty();

            this.uiFields.forEach(function (field) {
                var value = configValues[field.name];
                var defaultValue = field.default !== undefined ? field.default : '';
                var currentValue = (value !== undefined && value !== null) ? value : defaultValue;
                var fieldName = field.name;
                var label = field.label || field.name;
                var isRequired = this.schema && this.schema.required &&
                    this.schema.required.indexOf(fieldName) !== -1;

                var $group = $('<div>').addClass('form-group').attr('data-config-field', fieldName);
                var $label = $('<label>')
                    .addClass('control-label')
                    .text(label);

                if (isRequired) {
                    $label.append(' <span class="text-danger">*</span>');
                }

                $group.append($label);

                var $input;

                switch (field.type) {
                    case 'text':
                        $input = $('<input>')
                            .attr('type', 'text')
                            .addClass('form-control')
                            .attr('data-config-name', fieldName)
                            .val(currentValue || '');
                        break;

                    case 'password':
                        var $inputGroup = $('<div>').addClass('input-group');
                        $input = $('<input>')
                            .attr('type', 'password')
                            .addClass('form-control')
                            .attr('data-config-name', fieldName)
                            .val(currentValue || '');

                        var $toggleBtn = $('<span>')
                            .addClass('input-group-btn')
                            .html(
                                '<button class="btn btn-default btn-config-toggle-password" type="button" tabindex="-1">' +
                                '<span class="fas fa-eye"></span>' +
                                '</button>'
                            );

                        $toggleBtn.find('button').on('click', function () {
                            var $inp = $inputGroup.find('input');
                            var $icon = $toggleBtn.find('span.fas');

                            if ($inp.attr('type') === 'password') {
                                $inp.attr('type', 'text');
                                $icon.removeClass('fa-eye').addClass('fa-eye-slash');
                            } else {
                                $inp.attr('type', 'password');
                                $icon.removeClass('fa-eye-slash').addClass('fa-eye');
                            }
                        });

                        $inputGroup.append($input).append($toggleBtn);
                        $group.append($inputGroup);
                        $input.on('change input', function () {
                            this.trigger('change', {ui: true});
                        }.bind(this));
                        $container.append($group);
                        return; // Skip the default append below.

                    case 'textarea':
                        $input = $('<textarea>')
                            .addClass('form-control')
                            .attr('rows', 4)
                            .attr('data-config-name', fieldName)
                            .val(currentValue || '');
                        break;

                    case 'int':
                        $input = $('<input>')
                            .attr('type', 'number')
                            .addClass('form-control')
                            .attr('data-config-name', fieldName)
                            .val(currentValue !== '' ? currentValue : '');
                        break;

                    case 'enum':
                        $input = $('<select>')
                            .addClass('form-control')
                            .attr('data-config-name', fieldName);

                        $input.append($('<option>').val('').text(''));

                        if (field.options) {
                            Object.keys(field.options).forEach(function (optKey) {
                                var $opt = $('<option>')
                                    .val(optKey)
                                    .text(field.options[optKey]);

                                if (currentValue === optKey) {
                                    $opt.attr('selected', true);
                                }

                                $input.append($opt);
                            });
                        }
                        break;

                    case 'checkbox':
                        $input = $('<input>')
                            .attr('type', 'checkbox')
                            .attr('data-config-name', fieldName);

                        if (currentValue === true || currentValue === 'true' || currentValue === 1) {
                            $input.prop('checked', true);
                        }

                        // Wrap checkbox differently.
                        var $checkWrap = $('<div>').addClass('checkbox');
                        var $checkLabel = $('<label>').append($input).append(' ' + label);
                        $checkWrap.append($checkLabel);
                        // Replace the label with just the checkbox wrapper.
                        $group.empty().append($checkWrap);
                        $input.on('change', function () {
                            this.trigger('change', {ui: true});
                        }.bind(this));
                        $container.append($group);
                        return;

                    case 'json':
                    case 'array':
                        $input = $('<textarea>')
                            .addClass('form-control')
                            .attr('rows', 6)
                            .attr('data-config-name', fieldName);

                        if (typeof currentValue === 'object' && currentValue !== null) {
                            $input.val(JSON.stringify(currentValue, null, 2));
                        } else if (currentValue) {
                            $input.val(currentValue);
                        }
                        break;

                    default:
                        // Fall back to text input for unknown types.
                        $input = $('<input>')
                            .attr('type', 'text')
                            .addClass('form-control')
                            .attr('data-config-name', fieldName)
                            .val(currentValue || '');
                        break;
                }

                $group.append($input);

                $input.on('change input', function () {
                    this.trigger('change', {ui: true});
                }.bind(this));

                $container.append($group);
            }.bind(this));
        },

        /**
         * Collect values from the dynamic form and serialize to JSON.
         * @returns {Object}
         */
        fetch: function () {
            if (this.isFallback || !this.uiFields || this.uiFields.length === 0) {
                // Fallback mode: read raw textarea.
                var raw = this.$el.find('.config-fallback').val();

                if (!raw || !raw.trim()) {
                    return {config: null};
                }

                // Try to parse as JSON; if it fails, store as-is.
                try {
                    JSON.parse(raw);
                    return {config: raw.trim()};
                } catch (e) {
                    return {config: raw.trim()};
                }
            }

            var config = {};

            this.uiFields.forEach(function (field) {
                var fieldName = field.name;
                var $el;

                if (field.type === 'checkbox') {
                    $el = this.$el.find('[data-config-name="' + fieldName + '"]');
                    config[fieldName] = $el.is(':checked');
                    return;
                }

                $el = this.$el.find('[data-config-name="' + fieldName + '"]');
                var val = $el.val();

                if (field.type === 'int') {
                    config[fieldName] = val !== '' ? parseInt(val, 10) : null;
                } else if (field.type === 'json' || field.type === 'array') {
                    if (val && val.trim()) {
                        try {
                            config[fieldName] = JSON.parse(val);
                        } catch (e) {
                            config[fieldName] = val;
                        }
                    } else {
                        config[fieldName] = null;
                    }
                } else {
                    config[fieldName] = val || null;
                }
            }.bind(this));

            return {config: JSON.stringify(config)};
        },

        /**
         * Validate required fields based on the schema's required array.
         * @returns {boolean} true if validation fails.
         */
        validateConfigRequired: function () {
            if (this.isFallback || !this.schema || !this.schema.required || !this.uiFields) {
                return false;
            }

            var configValues = {};
            var fetchedData = this.fetch();
            try {
                configValues = JSON.parse(fetchedData.config || '{}');
            } catch (e) {
                return false;
            }

            var hasError = false;

            // Also check tokenFieldMapping — fields sourced from OAuth should
            // not be required in the form.
            var oauthSourcedFields = [];
            if (this.schema && this.schema.properties) {
                Object.keys(this.schema.properties).forEach(function (key) {
                    if (this.schema.properties[key].source === 'oauth') {
                        oauthSourcedFields.push(key);
                    }
                }.bind(this));
            }

            this.schema.required.forEach(function (fieldName) {
                // Skip fields sourced from OAuth.
                if (oauthSourcedFields.indexOf(fieldName) !== -1) {
                    return;
                }

                var value = configValues[fieldName];

                if (value === undefined || value === null || value === '') {
                    hasError = true;
                    var $field = this.$el.find('[data-config-field="' + fieldName + '"]');

                    if ($field.length) {
                        var uiField = this.uiFields.find(function (f) { return f.name === fieldName; });
                        var label = uiField ? (uiField.label || fieldName) : fieldName;
                        var msg = label + ' ' + this.translate('isRequired', 'messages');

                        $field.addClass('has-error');

                        var $input = $field.find('.form-control').first();
                        if ($input.length) {
                            this.showValidationMessage(msg, $input);
                        }
                    }
                }
            }.bind(this));

            return hasError;
        },

        /**
         * Override to clear our custom validation markers.
         */
        showValidationMessage: function (message, $target) {
            // Use the parent's implementation.
            Dep.prototype.showValidationMessage.call(this, message, $target);
        },
    });
});
