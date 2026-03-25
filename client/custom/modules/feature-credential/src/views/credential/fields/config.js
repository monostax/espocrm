define("feature-credential:views/credential/fields/config", [
    "views/fields/text",
    "feature-credential:views/credential/fields/schema-ui-adapter",
], function (
    Dep,
    SchemaUiAdapter,
) {
    /**
     * Custom field view for Credential.config that dynamically renders
     * form inputs based on the linked CredentialType's schema.
     */
    return Dep.extend({

        // Cache of fetched CredentialType data keyed by ID.
        _credentialTypeCache: null,

        // Parsed schema-derived UI fields array.
        uiFields: null,

        // Parsed schema object (canonical required-field source).
        schema: null,

        // Schema-derived required fields.
        requiredFields: null,

        // Fields sourced from OAuth (manual input skipped).
        oauthSourcedFields: null,

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
            this.requiredFields = [];
            this.oauthSourcedFields = [];
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
         * Fetch CredentialType record and parse schema.
         * @returns {Promise}
         */
        loadUiConfig: function () {
            var credentialTypeId = this.model.get('credentialTypeId');

            if (!credentialTypeId) {
                this.uiFields = null;
                this.schema = null;
                this.requiredFields = [];
                this.oauthSourcedFields = [];
                this.isFallback = false;
                return Promise.resolve();
            }

            // Check cache first.
            if (this._credentialTypeCache[credentialTypeId]) {
                var cached = this._credentialTypeCache[credentialTypeId];
                this.uiFields = cached.uiFields;
                this.schema = cached.schema;
                this.requiredFields = cached.requiredFields || [];
                this.oauthSourcedFields = cached.oauthSourcedFields || [];
                this.isFallback = !this.hasUsableUiFields();
                return Promise.resolve();
            }

            return Espo.Ajax.getRequest('CredentialType/' + credentialTypeId)
                .then(function (response) {
                    var schema = SchemaUiAdapter.parseSchema(response.schema);
                    var requiredFields = SchemaUiAdapter.extractRequiredFields(schema);
                    var oauthSourcedFields = SchemaUiAdapter.extractOauthSourcedFields(schema);

                    var schemaUi = SchemaUiAdapter.buildFieldsFromSchema(
                        schema,
                        response.encryptionFields
                    );

                    var uiFields = schemaUi && schemaUi.fields && schemaUi.fields.length > 0
                        ? schemaUi.fields
                        : null;

                    this._credentialTypeCache[credentialTypeId] = {
                        uiFields: uiFields,
                        schema: schema,
                        requiredFields: requiredFields,
                        oauthSourcedFields: oauthSourcedFields,
                    };

                    this.uiFields = uiFields;
                    this.schema = schema;
                    this.requiredFields = requiredFields;
                    this.oauthSourcedFields = oauthSourcedFields;
                    this.isFallback = !this.hasUsableUiFields();
                }.bind(this))
                .catch(function (err) {
                    console.error('Failed to load CredentialType', credentialTypeId, err);
                    this.uiFields = null;
                    this.schema = null;
                    this.requiredFields = [];
                    this.oauthSourcedFields = [];
                    this.isFallback = true;
                }.bind(this));
        },

        hasUsableUiFields: function () {
            return !!(this.uiFields && this.uiFields.length > 0);
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
            data.hasUiFields = this.hasUsableUiFields();
            data.isNotEmpty = !!raw;

            // Raw value for fallback mode.
            if (typeof raw === 'object' && raw !== null) {
                data.rawValue = JSON.stringify(raw, null, 2);
            } else {
                data.rawValue = raw || '';
            }

            // Build field values for detail mode.
            if (!this.isFallback && this.hasUsableUiFields()) {
                data.hasFields = Object.keys(configValues).length > 0 || this.uiFields.length > 0;
                data.fieldValues = [];

                this.uiFields.forEach(function (field) {
                    if (field.skip === true && !configValues.hasOwnProperty(field.name)) {
                        return;
                    }

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

            if (!this.hasUsableUiFields()) {
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
                var isRequired = this.requiredFields.indexOf(fieldName) !== -1 &&
                    this.oauthSourcedFields.indexOf(fieldName) === -1;
                var isOAuthManaged = field.source === 'oauth';
                var isReadOnly = !!field.readOnly;

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
                            .prop('readonly', isReadOnly)
                            .prop('disabled', field.skip === true)
                            .attr('placeholder', isOAuthManaged ? this.translate('oAuthManagedValueHint', 'messages', 'Credential') : '')
                            .val(currentValue || '');
                        break;

                    case 'password':
                        var $inputGroup = $('<div>').addClass('input-group');
                        $input = $('<input>')
                            .attr('type', 'password')
                            .addClass('form-control')
                            .attr('data-config-name', fieldName)
                            .prop('readonly', isReadOnly)
                            .prop('disabled', field.skip === true)
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
                            .prop('readonly', isReadOnly)
                            .prop('disabled', field.skip === true)
                            .val(currentValue || '');
                        break;

                    case 'int':
                        $input = $('<input>')
                            .attr('type', 'number')
                            .addClass('form-control')
                            .attr('data-config-name', fieldName)
                            .prop('readonly', isReadOnly)
                            .prop('disabled', field.skip === true)
                            .val(currentValue !== '' ? currentValue : '');
                        break;

                    case 'enum':
                        $input = $('<select>')
                            .addClass('form-control')
                            .attr('data-config-name', fieldName)
                            .prop('disabled', field.skip === true || isReadOnly);

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
                            .attr('data-config-name', fieldName)
                            .prop('disabled', field.skip === true || isReadOnly);

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
                            .attr('data-config-name', fieldName)
                            .prop('readonly', isReadOnly)
                            .prop('disabled', field.skip === true);

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
                            .prop('readonly', isReadOnly)
                            .prop('disabled', field.skip === true)
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

                if (field.skip === true || field.readOnly === true) {
                    return;
                }

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
            if (this.isFallback || !this.requiredFields || this.requiredFields.length === 0 || !this.uiFields) {
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

            this.requiredFields.forEach(function (fieldName) {
                // Skip fields sourced from OAuth.
                if (this.oauthSourcedFields.indexOf(fieldName) !== -1) {
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
