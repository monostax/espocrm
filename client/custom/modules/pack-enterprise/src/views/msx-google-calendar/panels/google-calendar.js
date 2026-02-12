/**
 * MsxGoogleCalendar configuration panel.
 * Adapted from google:views/google/panels/google-calendar.
 * Used within the MsxGoogleCalendarUser record view.
 */
define('pack-enterprise:views/msx-google-calendar/panels/google-calendar', ['view'], function (Dep) {

    return Dep.extend({

        template: 'pack-enterprise:msx-google-calendar/panel',

        productName: 'msxGoogleCalendar',

        fieldList: [],

        calendarList: [],

        isBlocked: true,

        fields: null,

        setupFields: function () {
            var scopes = this.getMetadata().get('scopes');

            var eventOptions = Object.keys(scopes)
                .filter(function (scope) {
                    if (scope === 'Email') return false;
                    if (scopes[scope].disabled) return false;
                    if (!scopes[scope].object) return false;
                    if (!scopes[scope].entity) return false;
                    if (!scopes[scope].activity || !scopes[scope].calendar) return false;

                    return true;
                })
                .sort(
                    function (v1, v2) {
                        return this.translate(v1, 'scopeNames').localeCompare(this.translate(v2, 'scopeNames'));
                    }.bind(this)
                );

            this.fields = {
                calendarDirection: {
                    type: 'enum',
                    options: ['EspoToGC', 'GCToEspo', 'Both'],
                    default: 'Both',
                },
                calendarStartDate: {
                    required: true,
                    type: 'date',
                },
                calendarEntityTypes: {
                    type: 'base',
                    view: 'pack-enterprise:views/msx-google-calendar/fields/labeled-array',
                    default: eventOptions,
                    options: eventOptions,
                    tooltip: true,
                    required: true,
                },
                calendarDefaultEntity: {
                    type: 'enum',
                    options: eventOptions,
                    default: 'Meeting',
                    tooltip: true,
                    translation: 'Global.scopeNames',
                },
                removeGCEventIfRemovedInEspo: {
                    type: 'bool',
                },
                dontSyncEventAttendees: {
                    type: 'bool',
                    default: true,
                },
                calendarMainCalendar: {
                    type: 'base',
                    view: 'pack-enterprise:views/msx-google-calendar/fields/main-calendar',
                    required: true,
                },
                calendarMonitoredCalendars: {
                    type: 'base',
                    view: 'pack-enterprise:views/msx-google-calendar/fields/monitored-calendars',
                },
                calendarAssignDefaultTeam: {
                    type: 'bool',
                    default: false,
                },
            };
        },

        data: function () {
            return {
                integration: this.integration,
                helpText: this.helpText,
                isActive: this.model.get(this.productName + 'Enabled') || false,
                isBlocked: this.isBlocked,
                fields: this.fieldList,
                hasFields: this.fieldList.length > 0,
                name: this.productName,
            };
        },

        setup: function () {
            this.model = this.options.model;
            this.id = this.options.id;
            this.setupFields();
            this.model.defs.fields = $.extend(this.model.defs.fields, this.fields);
            this.model.populateDefaults();

            this.fieldList = [];

            for (let i in this.fields) {
                this.createFieldView(this.fields[i].type, this.fields[i].view || null, i, false);
            }
        },

        createFieldView: function (type, view, name, readOnly, params) {
            var fieldView = view || this.getFieldManager().getViewName(type);

            this.createView(name, fieldView, {
                model: this.model,
                selector: '.field-' + name,
                defs: {
                    name: name,
                    params: params,
                },
                mode: readOnly ? 'detail' : 'edit',
                readOnly: readOnly,
            });

            this.fieldList.push(name);
        },

        loadCalendars: function () {
            Espo.Ajax.getRequest('MsxGoogleCalendar/action/usersCalendars')
                .then(function (calendars) {
                    this.model.calendarList = calendars;
                    this.checkCalendars();

                    if (this.isBlocked) {
                        this.isBlocked = false;
                        this.reRender();
                    }
                }.bind(this))
                .catch(function (xhr) {
                    xhr.errorIsHandled = true;

                    if (!this.isBlocked) {
                        this.isBlocked = true;
                        this.reRender();
                    }
                }.bind(this));
        },

        checkCalendars: function () {
            var mainCalendar = this.model.get('calendarMainCalendarId');

            if (!(mainCalendar in this.model.calendarList)) {
                this.model.set('calendarMainCalendarId', '');
                this.model.set('calendarMainCalendarName', '');

                this.getView('calendarMainCalendar').render();
            }

            var monitoredCalendars = this.model.get('calendarMonitoredCalendarsIds') || [];
            var monitoredCalendarsNames = this.model.get('calendarMonitoredCalendarsNames') || [];
            var render = false;

            for (let key in monitoredCalendars) {
                if (!(monitoredCalendars[key] in this.model.calendarList)) {
                    delete monitoredCalendarsNames[monitoredCalendars[key]];
                    monitoredCalendars.splice(key, 1);
                    render = true;
                }
            }

            if (monitoredCalendars.length === 0) {
                render = true;
            }

            if (render) {
                this.model.set('calendarMonitoredCalendarsIds', monitoredCalendars);
                this.model.set('calendarMonitoredCalendarsNames', monitoredCalendarsNames);

                this.getView('calendarMonitoredCalendars').render();
            }
        },

        afterRender: function () {
            this.showCalendarFields();

            this.listenTo(this.model, 'change:calendarDirection', function () {
                this.showCalendarFields();
            }, this);

            this.enablingDefaultEntity();

            this.listenTo(this.model, 'change:calendarEntityTypes', function () {
                this.enablingDefaultEntity();
            }, this);

            // Auto-load calendars if we have an OAuth account.
            if (this.model.get('oAuthAccountId')) {
                this.loadCalendars();
            }
        },

        showCalendarFields: function () {
            var calendarDirection = this.model.get('calendarDirection');

            switch (calendarDirection) {
                case 'EspoToGC':
                    this.hideField('calendarMonitoredCalendars');
                    this.hideField('calendarDefaultEntity');
                    break;

                case 'GCToEspo':
                    this.showField('calendarMonitoredCalendars');
                    this.showField('calendarDefaultEntity');
                    break;

                case 'Both':
                    this.showField('calendarMonitoredCalendars');
                    this.showField('calendarDefaultEntity');
                    break;

                default:
                    this.hideField('calendarMonitoredCalendars');
                    this.hideField('calendarDefaultEntity');
            }
        },

        enablingDefaultEntity: function () {
            var calendarEntityTypes = this.model.get('calendarEntityTypes');
            var defaultEntityView = this.getView('calendarDefaultEntity');

            if (defaultEntityView && defaultEntityView.$el) {
                defaultEntityView.$el.find('option').each(function (i, o) {
                    var $o = $(o);

                    if (calendarEntityTypes.indexOf($o.val()) === -1) {
                        $o.attr('disabled', 'disabled');
                        $o.removeAttr('selected');
                    } else {
                        $o.removeAttr('disabled');
                    }
                });
            }
        },

        setConnected: function () {
            this.loadCalendars();
        },

        setNotConnected: function () {},

        validate: function () {
            this.fieldList.forEach(function (field) {
                var view = this.getView(field);

                if (!view.readOnly && view.$el.is(':visible')) {
                    view.fetchToModel();
                }
            }, this);

            var notValid = false;

            this.fieldList.forEach(function (field) {
                notValid = this.getView(field).validate() || notValid;
            }, this);

            var defaultEntity = this.model.get('calendarDefaultEntity');
            var entities = this.model.get('calendarEntityTypes');
            var entitiesView = this.getView('calendarEntityTypes');
            var defaultEntityView = this.getView('calendarDefaultEntity');

            if (defaultEntityView.$el.is(':visible')) {
                var defaultIsInList = false;
                var labelDuplicates = false;
                var labels = [];

                for (let key in entities) {
                    var label = this.model.get(entities[key] + 'IdentificationLabel');

                    if ((label == null || label === '') && defaultEntity !== entities[key]) {
                        const msg = this.translate(
                            'emptyNotDefaultEnitityLabel',
                            'messages',
                            'MsxGoogleCalendar'
                        );

                        entitiesView.showValidationMessage(
                            msg,
                            '[data-name="translatedValue"]:last'
                        );

                        notValid |= true;
                    } else {
                        if (labels.indexOf(label) >= 0) {
                            labelDuplicates = true;
                        }

                        labels.push(label);
                    }

                    if (entities[key] === defaultEntity) {
                        defaultIsInList = true;
                    }
                }

                if (!defaultIsInList) {
                    const msg = this.translate(
                        'defaultEntityIsRequiredInList',
                        'messages',
                        'MsxGoogleCalendar'
                    );

                    defaultEntityView.showValidationMessage(msg);
                    notValid |= true;
                }

                if (labelDuplicates) {
                    const msg = this.translate(
                        'notUniqueIdentificationLabel',
                        'messages',
                        'MsxGoogleCalendar'
                    );

                    entitiesView.showValidationMessage(
                        msg,
                        '[data-name="translatedValue"]:last'
                    );

                    notValid |= true;
                }
            }

            return notValid;
        },

        hideField: function (field) {
            this.$el.find('.cell-' + field).addClass('hidden');
        },

        showField: function (field) {
            this.$el.find('.cell-' + field).removeClass('hidden');
        },
    });
});
