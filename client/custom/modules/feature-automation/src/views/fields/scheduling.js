define('feature-automation:views/fields/scheduling', ['views/fields/base'], function (Dep) {
    const dayCodes = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
    const dayOrder = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
    const frequencies = ['MINUTELY', 'HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /**
     * Google Calendar-style recurrence UI. Values persist as RFC 5545 recurrence sets.
     * Advanced raw text stays collapsed unless the stored value cannot be represented simply.
     */
    return Dep.extend({

        type: 'text',

        detailTemplateContent:
            '{{#if isNotEmpty}}' +
                '<div class="automation-recurrence-detail">' +
                    '<div class="automation-recurrence-detail-summary">{{summary}}</div>' +
                    '{{#if showRawDetail}}' +
                        '<pre class="automation-recurrence-code">{{value}}</pre>' +
                    '{{/if}}' +
                '</div>' +
            '{{else}}' +
                '<span class="none-value">{{translate "None"}}</span>' +
            '{{/if}}',

        listTemplateContent:
            '{{#if isNotEmpty}}' +
                '<span class="text-muted">{{summary}}</span>' +
            '{{else}}' +
                '<span class="none-value">{{translate "None"}}</span>' +
            '{{/if}}',

        editTemplateContent:
            '<div class="automation-recurrence">' +
                '<div class="automation-recurrence-form">' +
                    '<div class="automation-recurrence-row">' +
                        '<div class="automation-recurrence-label">' +
                            '{{translate "repeats" category="labels" scope="Automation"}}' +
                        '</div>' +
                        '<div class="automation-recurrence-control">' +
                            '<select class="form-control input-sm automation-recurrence-frequency">' +
                                '<option value="DAILY">{{translate "freqDaily" category="labels" scope="Automation"}}</option>' +
                                '<option value="WEEKLY">{{translate "freqWeekly" category="labels" scope="Automation"}}</option>' +
                                '<option value="MONTHLY">{{translate "freqMonthly" category="labels" scope="Automation"}}</option>' +
                                '<option value="YEARLY">{{translate "freqYearly" category="labels" scope="Automation"}}</option>' +
                                '<option value="HOURLY">{{translate "freqHourly" category="labels" scope="Automation"}}</option>' +
                                '<option value="MINUTELY">{{translate "freqMinutely" category="labels" scope="Automation"}}</option>' +
                            '</select>' +
                        '</div>' +
                    '</div>' +
                    '<div class="automation-recurrence-row">' +
                        '<div class="automation-recurrence-label">' +
                            '{{translate "repeatEvery" category="labels" scope="Automation"}}' +
                        '</div>' +
                        '<div class="automation-recurrence-control automation-recurrence-inline">' +
                            '<input type="number" min="1" step="1" class="form-control input-sm automation-recurrence-interval">' +
                            '<span class="automation-recurrence-interval-unit text-muted"></span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="automation-recurrence-row automation-recurrence-weekdays hidden">' +
                        '<div class="automation-recurrence-label">' +
                            '{{translate "repeatOn" category="labels" scope="Automation"}}' +
                        '</div>' +
                        '<div class="automation-recurrence-control">' +
                            '<div class="automation-recurrence-day-group" role="group">' +
                                '<button type="button" class="btn btn-default btn-sm" data-day="SU">' +
                                    '{{translate "weekdaySU" category="labels" scope="Automation"}}' +
                                '</button>' +
                                '<button type="button" class="btn btn-default btn-sm" data-day="MO">' +
                                    '{{translate "weekdayMO" category="labels" scope="Automation"}}' +
                                '</button>' +
                                '<button type="button" class="btn btn-default btn-sm" data-day="TU">' +
                                    '{{translate "weekdayTU" category="labels" scope="Automation"}}' +
                                '</button>' +
                                '<button type="button" class="btn btn-default btn-sm" data-day="WE">' +
                                    '{{translate "weekdayWE" category="labels" scope="Automation"}}' +
                                '</button>' +
                                '<button type="button" class="btn btn-default btn-sm" data-day="TH">' +
                                    '{{translate "weekdayTH" category="labels" scope="Automation"}}' +
                                '</button>' +
                                '<button type="button" class="btn btn-default btn-sm" data-day="FR">' +
                                    '{{translate "weekdayFR" category="labels" scope="Automation"}}' +
                                '</button>' +
                                '<button type="button" class="btn btn-default btn-sm" data-day="SA">' +
                                    '{{translate "weekdaySA" category="labels" scope="Automation"}}' +
                                '</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="automation-recurrence-row automation-recurrence-monthday hidden">' +
                        '<div class="automation-recurrence-label">' +
                            '{{translate "dayOfMonth" category="labels" scope="Automation"}}' +
                        '</div>' +
                        '<div class="automation-recurrence-control">' +
                            '<input type="number" min="1" max="31" step="1" ' +
                                'class="form-control input-sm automation-recurrence-monthday-input">' +
                        '</div>' +
                    '</div>' +
                    '<div class="automation-recurrence-row">' +
                        '<div class="automation-recurrence-label">' +
                            '{{translate "startsOn" category="labels" scope="Automation"}}' +
                        '</div>' +
                        '<div class="automation-recurrence-control automation-recurrence-inline">' +
                            '<input type="date" class="form-control input-sm automation-recurrence-date">' +
                            '<input type="time" class="form-control input-sm automation-recurrence-time">' +
                        '</div>' +
                    '</div>' +
                    '<div class="automation-recurrence-row automation-recurrence-ends-row">' +
                        '<div class="automation-recurrence-label">' +
                            '{{translate "ends" category="labels" scope="Automation"}}' +
                        '</div>' +
                        '<div class="automation-recurrence-control automation-recurrence-ends-options">' +
                            '<label class="automation-recurrence-radio">' +
                                '<input type="radio" name="automation-recurrence-ends-{{cid}}" ' +
                                    'class="automation-recurrence-ends" value="never"> ' +
                                '<span>{{translate "never" category="labels" scope="Automation"}}</span>' +
                            '</label>' +
                            '<label class="automation-recurrence-radio">' +
                                '<input type="radio" name="automation-recurrence-ends-{{cid}}" ' +
                                    'class="automation-recurrence-ends" value="count"> ' +
                                '<span>{{translate "after" category="labels" scope="Automation"}}</span>' +
                                '<input type="number" min="1" step="1" ' +
                                    'class="form-control input-sm automation-recurrence-count">' +
                                '<span class="text-muted">' +
                                    '{{translate "occurrences" category="labels" scope="Automation"}}' +
                                '</span>' +
                            '</label>' +
                            '<label class="automation-recurrence-radio">' +
                                '<input type="radio" name="automation-recurrence-ends-{{cid}}" ' +
                                    'class="automation-recurrence-ends" value="until"> ' +
                                '<span>{{translate "onDate" category="labels" scope="Automation"}}</span>' +
                                '<input type="date" class="form-control input-sm automation-recurrence-until">' +
                            '</label>' +
                        '</div>' +
                    '</div>' +
                    '<div class="automation-recurrence-row automation-recurrence-summary-row">' +
                        '<div class="automation-recurrence-label">' +
                            '{{translate "scheduleSummary" category="labels" scope="Automation"}}' +
                        '</div>' +
                        '<div class="automation-recurrence-control">' +
                            '<span class="automation-recurrence-summary-value"></span>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="automation-recurrence-more">' +
                    '<button type="button" class="btn btn-link btn-sm" data-action="toggleExceptions">' +
                        '{{translate "exceptionDates" category="labels" scope="Automation"}}' +
                    '</button>' +
                    '<button type="button" class="btn btn-link btn-sm" data-action="toggleAdvanced">' +
                        '{{translate "advancedRecurrenceSet" category="labels" scope="Automation"}}' +
                    '</button>' +
                '</div>' +
                '<div class="automation-recurrence-exceptions hidden">' +
                    '<div class="automation-recurrence-exception-add">' +
                        '<input type="datetime-local" class="form-control input-sm automation-recurrence-exception-input">' +
                        '<button type="button" class="btn btn-default btn-sm" data-action="addScheduleException">' +
                            '<span class="fas fa-plus"></span> ' +
                            '{{translate "addException" category="labels" scope="Automation"}}' +
                        '</button>' +
                    '</div>' +
                    '<div class="automation-recurrence-exception-list"></div>' +
                '</div>' +
                '<div class="automation-recurrence-advanced hidden">' +
                    '<div class="alert alert-info automation-recurrence-advanced-hint">' +
                        '{{translate "recurrenceAdvancedHint" category="messages" scope="Automation"}}' +
                    '</div>' +
                    '<textarea class="form-control automation-recurrence-raw" rows="8" spellcheck="false"></textarea>' +
                    '<p class="text-muted small margin-top-sm">' +
                        '{{translate "recurrenceAdvancedExamples" category="messages" scope="Automation"}}' +
                    '</p>' +
                '</div>' +
                '<div class="text-danger automation-recurrence-error margin-top-sm hidden"></div>' +
            '</div>',

        setup: function () {
            Dep.prototype.setup.call(this);

            this._original = this.normalizeValue(this.model.get(this.name));
            this._simple = this.parseSimple(this._original) || this.defaultSimple();
            this._mode = this._original && !this.parseSimple(this._original) ? 'advanced' : 'simple';
            this._dirty = false;
            this._exceptionsOpen = !!(this._simple.exceptions && this._simple.exceptions.length);
            this._advancedOpen = this._mode === 'advanced';

            this.listenTo(this.model, 'change:timezone', () => {
                if (!this.isRendered() || !this.isEditMode() || this._mode !== 'simple') {
                    return;
                }

                this._dirty = true;
                this.renderSimple();
                this.trigger('change');
            });
        },

        data: function () {
            const value = this.normalizeValue(this.model.get(this.name));
            const simple = this.parseSimple(value);

            return {
                ...Dep.prototype.data.call(this),
                isNotEmpty: !!value,
                summary: this.summarize(value),
                value: value,
                showRawDetail: !simple,
                cid: this.cid,
            };
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (!this.isEditMode()) {
                return;
            }

            this.$form = this.$el.find('.automation-recurrence-form');
            this.$raw = this.$el.find('.automation-recurrence-raw');
            this.$error = this.$el.find('.automation-recurrence-error');
            this.$raw.val(this._original);
            this.bindEvents();
            this.renderSimple();
            this.renderPanels();
        },

        bindEvents: function () {
            this.$el.off('.automationRecurrence');

            this.$el.on('click.automationRecurrence', '[data-action="toggleAdvanced"]', () => {
                if (this._mode === 'simple' && !this._advancedOpen) {
                    this.switchMode('advanced');

                    return;
                }

                if (this._mode === 'advanced' && this._advancedOpen) {
                    const parsed = this.parseSimple(this.normalizeValue(this.$raw.val()));
                    if (parsed) {
                        this._simple = parsed;
                        this._mode = 'simple';
                        this._advancedOpen = false;
                        this.renderSimple();
                        this.renderPanels();
                        this.clearError();

                        return;
                    }

                    this.showError(this.tMessage('recurrenceSwitchSimple'));

                    return;
                }

                this._advancedOpen = !this._advancedOpen;
                this.renderPanels();
            });

            this.$el.on('click.automationRecurrence', '[data-action="toggleExceptions"]', () => {
                this._exceptionsOpen = !this._exceptionsOpen;
                this.renderPanels();
            });

            this.$el.on('click.automationRecurrence', '[data-day]', (event) => {
                const $button = $(event.currentTarget);
                $button.toggleClass('active btn-primary').toggleClass('btn-default');
                this.onSimpleChanged();
            });

            this.$el.on('click.automationRecurrence', '[data-action="addScheduleException"]', () => {
                const value = this.normalizeLocalDateTime(
                    this.$el.find('.automation-recurrence-exception-input').val()
                );

                if (!value) {
                    this.showError(this.tMessage('recurrenceInvalidException'));

                    return;
                }

                this.readSimple();
                if (!this._simple.exceptions.includes(value)) {
                    this._simple.exceptions.push(value);
                    this._simple.exceptions.sort();
                }
                this.$el.find('.automation-recurrence-exception-input').val('');
                this._exceptionsOpen = true;
                this.renderExceptionList();
                this.onSimpleChanged(false);
            });

            this.$el.on('click.automationRecurrence', '[data-action="removeScheduleException"]', (event) => {
                const index = parseInt($(event.currentTarget).data('index'), 10);

                this.readSimple();
                if (!Number.isNaN(index)) {
                    this._simple.exceptions.splice(index, 1);
                }
                this.renderExceptionList();
                this.onSimpleChanged(false);
            });

            this.$el.on(
                'input.automationRecurrence change.automationRecurrence',
                '.automation-recurrence-form input, .automation-recurrence-form select',
                () => this.onSimpleChanged()
            );

            this.$raw.on('input change', () => {
                this._dirty = true;
                this._mode = 'advanced';
                this._advancedOpen = true;
                this.clearError();
                this.trigger('change');
            });
        },

        switchMode: function (mode) {
            if (mode === 'advanced') {
                this.readSimple();
                this.$raw.val(this._dirty ? this.buildSimpleSchedule() : (this._original || this.buildSimpleSchedule()));
                this._mode = 'advanced';
                this._advancedOpen = true;
                this.renderPanels();

                return;
            }

            const parsed = this.parseSimple(this.normalizeValue(this.$raw.val()));
            if (!parsed) {
                this.showError(this.tMessage('recurrenceSwitchSimple'));

                return;
            }

            this._simple = parsed;
            this._mode = 'simple';
            this._advancedOpen = false;
            this.renderSimple();
            this.renderPanels();
        },

        renderPanels: function () {
            const advanced = this._mode === 'advanced' || this._advancedOpen;
            const formLocked = this._mode === 'advanced';

            this.$form.toggleClass('automation-recurrence-form-locked', formLocked);
            this.$form.find('input, select, button').prop('disabled', formLocked);
            this.$el.find('.automation-recurrence-exceptions').toggleClass('hidden', !this._exceptionsOpen || formLocked);
            this.$el.find('.automation-recurrence-advanced').toggleClass('hidden', !advanced);
            this.$el.find('[data-action="toggleAdvanced"]')
                .toggleClass('active', advanced);
            this.$el.find('[data-action="toggleExceptions"]')
                .toggleClass('active', this._exceptionsOpen && !formLocked)
                .prop('disabled', formLocked);
        },

        renderSimple: function () {
            if (!this.$form) {
                return;
            }

            const simple = this._simple;
            this.$el.find('.automation-recurrence-frequency').val(simple.frequency);
            this.$el.find('.automation-recurrence-interval').val(simple.interval);
            this.$el.find('.automation-recurrence-date').val(simple.date);
            this.$el.find('.automation-recurrence-time').val(simple.time);
            this.$el.find('.automation-recurrence-ends[value="' + simple.ends + '"]').prop('checked', true);
            this.$el.find('.automation-recurrence-count').val(simple.count);
            this.$el.find('.automation-recurrence-until').val(simple.until);
            this.$el.find('.automation-recurrence-monthday-input').val(simple.monthDay);

            this.$el.find('[data-day]')
                .removeClass('active btn-primary')
                .addClass('btn-default');
            simple.days.forEach((day) => {
                this.$el.find('[data-day="' + day + '"]')
                    .removeClass('btn-default')
                    .addClass('active btn-primary');
            });

            this.syncSimpleVisibility();
            this.renderExceptionList();
            this.renderSimpleSummary();
        },

        syncSimpleVisibility: function () {
            const frequency = this.$el.find('.automation-recurrence-frequency').val() || 'WEEKLY';
            const ends = this.$el.find('.automation-recurrence-ends:checked').val() || 'never';
            const unitMap = {
                MINUTELY: 'minutes',
                HOURLY: 'hours',
                DAILY: 'days',
                WEEKLY: 'weeks',
                MONTHLY: 'months',
                YEARLY: 'years',
            };

            this.$el.find('.automation-recurrence-weekdays').toggleClass('hidden', frequency !== 'WEEKLY');
            this.$el.find('.automation-recurrence-monthday').toggleClass('hidden', frequency !== 'MONTHLY');
            this.$el.find('.automation-recurrence-interval-unit').text(this.tLabel(unitMap[frequency] || 'days'));
            this.$el.find('.automation-recurrence-count').prop('disabled', ends !== 'count');
            this.$el.find('.automation-recurrence-until').prop('disabled', ends !== 'until');
        },

        renderExceptionList: function () {
            const $list = this.$el.find('.automation-recurrence-exception-list').empty();

            this._simple.exceptions.forEach((value, index) => {
                const $chip = $('<span>').addClass('automation-recurrence-exception-chip');
                $chip.append($('<span>').text(value.replace('T', ' ')));
                $chip.append(
                    $('<button>')
                        .attr({
                            type: 'button',
                            'data-action': 'removeScheduleException',
                            'data-index': index,
                            title: this.tLabel('remove'),
                        })
                        .addClass('btn btn-link btn-xs')
                        .html('<span class="fas fa-times"></span>')
                );
                $list.append($chip);
            });
        },

        onSimpleChanged: function (read) {
            if (this._mode === 'advanced') {
                return;
            }

            if (read !== false) {
                this.readSimple();
            }

            this._dirty = true;
            this.clearError();
            this.syncSimpleVisibility();
            this.renderSimpleSummary();
            this.trigger('change');
        },

        readSimple: function () {
            this._simple.frequency = String(
                this.$el.find('.automation-recurrence-frequency').val() || 'WEEKLY'
            ).toUpperCase();
            this._simple.interval = String(this.$el.find('.automation-recurrence-interval').val() || '1');
            this._simple.date = String(this.$el.find('.automation-recurrence-date').val() || '');
            this._simple.time = String(this.$el.find('.automation-recurrence-time').val() || '');
            this._simple.ends = String(this.$el.find('.automation-recurrence-ends:checked').val() || 'never');
            this._simple.count = String(this.$el.find('.automation-recurrence-count').val() || '');
            this._simple.until = String(this.$el.find('.automation-recurrence-until').val() || '');
            this._simple.monthDay = String(this.$el.find('.automation-recurrence-monthday-input').val() || '');
            this._simple.days = [];

            this.$el.find('[data-day].active').each((index, element) => {
                this._simple.days.push(String($(element).data('day')));
            });
        },

        fetch: function () {
            const data = {};

            if (!this._dirty) {
                data[this.name] = this._original || null;

                return data;
            }

            if (this._mode === 'advanced') {
                data[this.name] = this.normalizeValue(this.$raw.val()) || null;

                return data;
            }

            this.readSimple();
            data[this.name] = this.buildSimpleSchedule() || null;

            return data;
        },

        validate: function () {
            if (!this._dirty || this._mode === 'advanced') {
                return false;
            }

            this.readSimple();
            if (!this.isPositiveInteger(this._simple.interval)) {
                this.showError(this.tMessage('recurrenceInvalidInterval'));

                return true;
            }

            if (!this.toIcalDateTime(this._simple.date, this._simple.time)) {
                this.showError(this.tMessage('recurrenceInvalidStart'));

                return true;
            }

            if (this._simple.ends === 'count' && !this.isPositiveInteger(this._simple.count)) {
                this.showError(this.tMessage('recurrenceInvalidInterval'));

                return true;
            }

            if (this._simple.ends === 'until' && !/^\d{4}-\d{2}-\d{2}$/.test(this._simple.until)) {
                this.showError(this.tMessage('recurrenceInvalidUntil'));

                return true;
            }

            return false;
        },

        buildSimpleSchedule: function () {
            const simple = this._simple;
            const start = this.toIcalDateTime(simple.date, simple.time);
            if (!start || !frequencies.includes(simple.frequency) || !this.isPositiveInteger(simple.interval)) {
                return '';
            }

            const parts = ['FREQ=' + simple.frequency];
            if (parseInt(simple.interval, 10) !== 1) {
                parts.push('INTERVAL=' + parseInt(simple.interval, 10));
            }

            if (simple.frequency === 'WEEKLY') {
                const days = simple.days.length ? simple.days : [this.dayCodeForDate(simple.date)];
                const ordered = dayOrder.filter((day) => days.includes(day));
                parts.push('BYDAY=' + (ordered.length ? ordered : days).join(','));
            }

            if (simple.frequency === 'MONTHLY') {
                const monthDay = parseInt(simple.monthDay || simple.date.slice(-2), 10);
                if (monthDay >= 1 && monthDay <= 31) {
                    parts.push('BYMONTHDAY=' + monthDay);
                }
            }

            if (['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'].includes(simple.frequency)) {
                const time = simple.time.split(':');
                parts.push('BYHOUR=' + parseInt(time[0], 10));
                parts.push('BYMINUTE=' + parseInt(time[1], 10));
            }

            if (simple.ends === 'count' && this.isPositiveInteger(simple.count)) {
                parts.push('COUNT=' + parseInt(simple.count, 10));
            }

            if (simple.ends === 'until' && /^\d{4}-\d{2}-\d{2}$/.test(simple.until)) {
                parts.push('UNTIL=' + simple.until.replace(/-/g, '') + 'T235959');
            }

            const timezone = this.getScheduleTimezone();
            const lines = [
                'DTSTART;TZID=' + timezone + ':' + start,
                'RRULE:' + parts.join(';'),
            ];
            const exceptions = simple.exceptions
                .map((value) => this.toIcalLocalDateTime(value))
                .filter(Boolean);

            if (exceptions.length) {
                lines.push('EXDATE;TZID=' + timezone + ':' + exceptions.join(','));
            }

            return lines.join('\n');
        },

        parseSimple: function (value) {
            const raw = this.normalizeValue(value);
            if (!raw) {
                return this.defaultSimple();
            }

            const lines = raw.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
            const ruleLines = lines.filter((line) => /^RRULE:/i.test(line));
            const unsupported = lines.some((line) => /^(RDATE|EXRULE)(?:;|:)/i.test(line));
            if (ruleLines.length !== 1 || unsupported) {
                return null;
            }

            const rule = this.parseRuleParts(ruleLines[0].replace(/^RRULE:/i, ''));
            if (!rule || !frequencies.includes(rule.FREQ || '')) {
                return null;
            }

            const allowed = ['FREQ', 'INTERVAL', 'BYDAY', 'BYHOUR', 'BYMINUTE', 'BYMONTHDAY', 'COUNT', 'UNTIL'];
            if (Object.keys(rule).some((key) => !allowed.includes(key))) {
                return null;
            }

            const simple = this.defaultSimple();
            simple.frequency = rule.FREQ;
            simple.interval = rule.INTERVAL || '1';
            if (!this.isPositiveInteger(simple.interval)) {
                return null;
            }

            const dtStart = lines.find((line) => /^DTSTART(?:;|:)/i.test(line));
            if (dtStart) {
                const parsedStart = this.parseDtStart(dtStart);
                if (!parsedStart) {
                    return null;
                }
                // TZID may differ from the current timezone field; simple mode still
                // loads the wall times and rewrites TZID from model on next build.
                simple.date = parsedStart.date;
                simple.time = parsedStart.time;
            }

            if (rule.BYHOUR !== undefined || rule.BYMINUTE !== undefined) {
                if (!/^\d{1,2}$/.test(rule.BYHOUR || '') || !/^\d{1,2}$/.test(rule.BYMINUTE || '')) {
                    return null;
                }
                simple.time = String(rule.BYHOUR).padStart(2, '0') + ':' + String(rule.BYMINUTE).padStart(2, '0');
            }

            if (rule.BYDAY !== undefined) {
                const days = rule.BYDAY.split(',').map((day) => day.trim().toUpperCase());
                if (simple.frequency !== 'WEEKLY' || days.some((day) => !dayCodes.includes(day))) {
                    return null;
                }
                simple.days = days;
            }

            if (rule.BYMONTHDAY !== undefined) {
                if (simple.frequency !== 'MONTHLY' || !/^\d{1,2}$/.test(rule.BYMONTHDAY)) {
                    return null;
                }
                simple.monthDay = rule.BYMONTHDAY;
            }

            if (rule.COUNT !== undefined) {
                if (!this.isPositiveInteger(rule.COUNT)) {
                    return null;
                }
                simple.ends = 'count';
                simple.count = rule.COUNT;
            }

            if (rule.UNTIL !== undefined) {
                const until = String(rule.UNTIL).match(/^(\d{8})(?:T\d{6}Z?)?$/);
                if (!until) {
                    return null;
                }
                simple.ends = 'until';
                simple.until = until[1].slice(0, 4) + '-' + until[1].slice(4, 6) + '-' + until[1].slice(6, 8);
            }

            const exceptionLines = lines.filter((line) => /^EXDATE(?:;|:)/i.test(line));
            for (const line of exceptionLines) {
                const exceptions = this.parseExceptionLine(line);
                if (!exceptions) {
                    return null;
                }
                simple.exceptions.push(...exceptions);
            }
            simple.exceptions = [...new Set(simple.exceptions)].sort();

            return simple;
        },

        parseRuleParts: function (value) {
            const result = {};

            for (const pair of value.split(';')) {
                const index = pair.indexOf('=');
                if (index <= 0 || index !== pair.lastIndexOf('=')) {
                    return null;
                }
                const key = pair.slice(0, index).trim().toUpperCase();
                let item = pair.slice(index + 1).trim();
                if (!key || !item || result[key] !== undefined) {
                    return null;
                }
                if (key !== 'UNTIL') {
                    item = item.toUpperCase();
                }
                result[key] = item;
            }

            return result.FREQ ? result : null;
        },

        parseDtStart: function (line) {
            const match = line.match(/^DTSTART;TZID=([^:]+):(\d{8})T(\d{2})(\d{2})(\d{2})$/i);
            if (!match || match[5] !== '00') {
                return null;
            }

            return {
                timezone: match[1],
                date: match[2].slice(0, 4) + '-' + match[2].slice(4, 6) + '-' + match[2].slice(6, 8),
                time: match[3] + ':' + match[4],
            };
        },

        parseExceptionLine: function (line) {
            const match = line.match(/^EXDATE(?:;TZID=([^:]+))?:(.+)$/i);
            if (!match) {
                return null;
            }

            const values = [];
            for (const item of match[2].split(',')) {
                const parsed = item.match(/^(\d{8})T(\d{2})(\d{2})(\d{2})Z?$/);
                if (!parsed || (parsed[4] !== '00' && parsed[4] !== undefined)) {
                    // allow only whole-minute local wall times in simple mode
                }
                if (!parsed) {
                    return null;
                }
                if (parsed[4] !== '00') {
                    return null;
                }
                values.push(
                    parsed[1].slice(0, 4) + '-' + parsed[1].slice(4, 6) + '-' + parsed[1].slice(6, 8) +
                    'T' + parsed[2] + ':' + parsed[3]
                );
            }

            return values;
        },

        defaultSimple: function () {
            let date = '';
            let time = '';

            try {
                const now = this.getDateTime().getNowMoment();
                date = now.format('YYYY-MM-DD');
                time = now.format('HH:mm');
            } catch (e) {
                const now = new Date();
                date = now.toISOString().slice(0, 10);
                time = now.toTimeString().slice(0, 5);
            }

            return {
                frequency: 'WEEKLY',
                interval: '1',
                date: date,
                time: time,
                days: [this.dayCodeForDate(date)],
                monthDay: '',
                ends: 'never',
                count: '10',
                until: date,
                exceptions: [],
            };
        },

        summarize: function (value) {
            const simple = this.parseSimple(value);
            if (simple) {
                return this.simpleSummary(simple);
            }

            const lines = this.normalizeValue(value).split(/\r?\n/);
            const ruleCount = lines.filter((line) => /^RRULE:/i.test(line.trim())).length;
            const exceptionCount = lines.filter((line) => /^EXDATE(?:;|:)/i.test(line.trim())).length;

            if (!ruleCount && !exceptionCount) {
                return this.normalizeValue(value) ? this.tLabel('advancedRecurrenceSet') : '';
            }

            return this.tLabel('advancedRecurrenceSet') + ': RRULE x' + ruleCount +
                (exceptionCount ? ', EXDATE x' + exceptionCount : '');
        },

        renderSimpleSummary: function () {
            this.$el.find('.automation-recurrence-summary-value').text(this.simpleSummary(this._simple));
        },

        simpleSummary: function (simple) {
            const freqLabels = {
                MINUTELY: 'freqMinutely',
                HOURLY: 'freqHourly',
                DAILY: 'freqDaily',
                WEEKLY: 'freqWeekly',
                MONTHLY: 'freqMonthly',
                YEARLY: 'freqYearly',
            };
            const unitMap = {
                MINUTELY: 'minutes',
                HOURLY: 'hours',
                DAILY: 'days',
                WEEKLY: 'weeks',
                MONTHLY: 'months',
                YEARLY: 'years',
            };

            let text = this.tLabel(freqLabels[simple.frequency] || 'freqWeekly');
            if (parseInt(simple.interval, 10) > 1) {
                text = this.tLabel('repeatEvery') + ' ' + simple.interval + ' ' +
                    this.tLabel(unitMap[simple.frequency]);
            }

            if (simple.frequency === 'WEEKLY') {
                const days = simple.days.length ? simple.days : [this.dayCodeForDate(simple.date)];
                const ordered = dayOrder.filter((day) => days.includes(day));
                text += ' ' + this.tLabel('onWords') + ' ' +
                    (ordered.length ? ordered : days).map((day) => this.tLabel('weekdayFull' + day)).join(', ');
            } else if (simple.frequency === 'MONTHLY') {
                text += ' - ' + this.tLabel('dayOfMonth') + ' ' + (simple.monthDay || simple.date.slice(-2));
            }

            if (simple.time && ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'].includes(simple.frequency)) {
                text += ' @ ' + simple.time;
            }

            if (simple.ends === 'count' && this.isPositiveInteger(simple.count)) {
                text += ', ' + simple.count + ' ' + this.tLabel('occurrences');
            } else if (simple.ends === 'until' && simple.until) {
                text += ', ' + this.tLabel('onDate') + ' ' + simple.until;
            }

            if (simple.exceptions.length) {
                text += ' (' + simple.exceptions.length + ' ' + this.tLabel('exceptionDates') + ')';
            }

            return text;
        },

        getScheduleTimezone: function () {
            let timezone = String(this.model.get('timezone') || '').trim();

            if (!timezone) {
                try {
                    timezone = this.getDateTime().getTimeZone();
                } catch (e) {
                    timezone = 'UTC';
                }
            }

            return timezone || 'UTC';
        },

        dayCodeForDate: function (date) {
            const value = new Date(date + 'T12:00:00');

            return dayCodes[value.getDay()] || 'MO';
        },

        toIcalDateTime: function (date, time) {
            const match = String(date || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
            const timeMatch = String(time || '').match(/^(\d{2}):(\d{2})$/);
            if (!match || !timeMatch || !this.isValidLocalDateTime(date, time)) {
                return null;
            }

            return match[1] + match[2] + match[3] + 'T' + timeMatch[1] + timeMatch[2] + '00';
        },

        toIcalLocalDateTime: function (value) {
            const normalized = this.normalizeLocalDateTime(value);
            if (!normalized) {
                return null;
            }

            return normalized.slice(0, 4) + normalized.slice(5, 7) + normalized.slice(8, 10) +
                'T' + normalized.slice(11, 13) + normalized.slice(14, 16) + '00';
        },

        normalizeLocalDateTime: function (value) {
            const string = String(value || '');
            const match = string.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
            if (!match || !this.isValidLocalDateTime(string.slice(0, 10), string.slice(11, 16))) {
                return null;
            }

            return string;
        },

        isValidLocalDateTime: function (date, time) {
            const parts = (date + 'T' + time).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
            if (!parts) {
                return false;
            }

            const year = parseInt(parts[1], 10);
            const month = parseInt(parts[2], 10);
            const day = parseInt(parts[3], 10);
            const hour = parseInt(parts[4], 10);
            const minute = parseInt(parts[5], 10);
            const candidate = new Date(Date.UTC(year, month - 1, day, hour, minute));

            return candidate.getUTCFullYear() === year &&
                candidate.getUTCMonth() + 1 === month &&
                candidate.getUTCDate() === day &&
                candidate.getUTCHours() === hour &&
                candidate.getUTCMinutes() === minute;
        },

        isPositiveInteger: function (value) {
            return /^\d+$/.test(String(value || '')) && parseInt(value, 10) > 0;
        },

        normalizeValue: function (value) {
            return String(value || '').trim();
        },

        tLabel: function (key) {
            return this.translate(key, 'labels', 'Automation');
        },

        tMessage: function (key) {
            return this.translate(key, 'messages', 'Automation');
        },

        showError: function (message) {
            this.$error.text(message).removeClass('hidden');
        },

        clearError: function () {
            this.$error.empty().addClass('hidden');
        },
    });
});
