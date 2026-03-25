define("feature-credential:views/credential/fields/schema-ui-adapter", [], function () {
    var DEFAULT_FIELD_TYPE = 'text';

    var parseJsonValue = function (value) {
        if (value === null || value === undefined) {
            return null;
        }

        if (typeof value === 'object') {
            return value;
        }

        if (typeof value !== 'string') {
            return null;
        }

        var trimmed = value.trim();

        if (!trimmed) {
            return null;
        }

        try {
            return JSON.parse(trimmed);
        } catch (e) {
            return null;
        }
    };

    var normalizeSchema = function (schemaRaw) {
        var schema = parseJsonValue(schemaRaw);

        if (!schema || typeof schema !== 'object' || Array.isArray(schema)) {
            return null;
        }

        if (!schema.properties || typeof schema.properties !== 'object' || Array.isArray(schema.properties)) {
            return null;
        }

        return schema;
    };

    var normalizeEncryptionFields = function (encryptionFieldsRaw) {
        if (!encryptionFieldsRaw) {
            return [];
        }

        if (Array.isArray(encryptionFieldsRaw)) {
            return encryptionFieldsRaw
                .filter(function (item) {
                    return typeof item === 'string' && item.trim() !== '';
                })
                .map(function (item) {
                    return item.trim();
                });
        }

        if (typeof encryptionFieldsRaw === 'string') {
            var parsed = parseJsonValue(encryptionFieldsRaw);

            if (parsed !== null) {
                return normalizeEncryptionFields(parsed);
            }

            return encryptionFieldsRaw
                .split(',')
                .map(function (item) {
                    return item.trim();
                })
                .filter(function (item) {
                    return item !== '';
                });
        }

        if (typeof encryptionFieldsRaw === 'object') {
            if (Array.isArray(encryptionFieldsRaw.fields)) {
                return normalizeEncryptionFields(encryptionFieldsRaw.fields);
            }

            return Object.keys(encryptionFieldsRaw).filter(function (fieldName) {
                return !!encryptionFieldsRaw[fieldName];
            });
        }

        return [];
    };

    var normalizeEnumOptions = function (propertySchema) {
        if (!propertySchema || !Array.isArray(propertySchema.enum) || propertySchema.enum.length === 0) {
            return null;
        }

        var options = {};

        propertySchema.enum.forEach(function (value) {
            var key = String(value);
            options[key] = key;
        });

        return options;
    };

    var inferFieldType = function (fieldName, propertySchema, encryptedFieldSet) {
        var schemaType = propertySchema.type;

        if (propertySchema.enum && propertySchema.enum.length > 0) {
            return 'enum';
        }

        if (encryptedFieldSet[fieldName]) {
            return 'password';
        }

        if (propertySchema.format === 'password') {
            return 'password';
        }

        if (schemaType === 'boolean') {
            return 'checkbox';
        }

        if (schemaType === 'integer' || schemaType === 'number') {
            return 'int';
        }

        if (schemaType === 'array') {
            return 'array';
        }

        if (schemaType === 'object') {
            return 'json';
        }

        if (schemaType === 'string') {
            if (propertySchema.format === 'textarea' || propertySchema.format === 'multiline') {
                return 'textarea';
            }

            if (propertySchema.contentMediaType === 'application/json' || propertySchema.format === 'json') {
                return 'json';
            }
        }

        return DEFAULT_FIELD_TYPE;
    };

    var buildFieldsFromSchema = function (schemaRaw, encryptionFieldsRaw) {
        var schema = normalizeSchema(schemaRaw);

        if (!schema) {
            return {
                schema: null,
                fields: [],
            };
        }

        var encryptionFields = normalizeEncryptionFields(encryptionFieldsRaw);
        var encryptedFieldSet = {};

        encryptionFields.forEach(function (fieldPath) {
            encryptedFieldSet[fieldPath] = true;
        });

        var fields = Object.keys(schema.properties).map(function (fieldName) {
            var propertySchema = schema.properties[fieldName] || {};
            var field = {
                name: fieldName,
                label: propertySchema.title || propertySchema.label || fieldName,
                type: inferFieldType(fieldName, propertySchema, encryptedFieldSet),
            };

            if (propertySchema.default !== undefined) {
                field.default = propertySchema.default;
            }

            var options = normalizeEnumOptions(propertySchema);
            if (options) {
                field.options = options;
            }

            if (propertySchema.source === 'oauth') {
                field.source = 'oauth';
                field.readOnly = true;
                field.skip = true;
            }

            return field;
        });

        return {
            schema: schema,
            fields: fields,
        };
    };

    var extractRequiredFields = function (schema) {
        if (!schema || !Array.isArray(schema.required)) {
            return [];
        }

        return schema.required.filter(function (fieldName) {
            return typeof fieldName === 'string' && fieldName.trim() !== '';
        });
    };

    var extractOauthSourcedFields = function (schema) {
        if (!schema || !schema.properties || typeof schema.properties !== 'object') {
            return [];
        }

        return Object.keys(schema.properties).filter(function (fieldName) {
            var propertySchema = schema.properties[fieldName];

            return propertySchema && typeof propertySchema === 'object' && propertySchema.source === 'oauth';
        });
    };

    return {
        parseSchema: normalizeSchema,
        buildFieldsFromSchema: buildFieldsFromSchema,
        extractRequiredFields: extractRequiredFields,
        extractOauthSourcedFields: extractOauthSourcedFields,
        parseEncryptionFields: normalizeEncryptionFields,
    };
});
