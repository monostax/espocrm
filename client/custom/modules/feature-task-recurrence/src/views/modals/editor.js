define('feature-task-recurrence:views/modals/editor', ['views/modal'], function (Modal) {
    const weekdays = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
    const presets = ['none', 'DAILY', 'WEEKLY', 'weekdays', 'MONTHLY', 'YEARLY', 'specific', 'custom', 'advanced'];
    return Modal.extend({
        className: 'dialog dialog-record',
        templateContent: `
            <form data-recurrence-form>
                <div class="form-group"><label>{{translate 'repeat' scope='TaskRecurrence'}}</label><select class="form-control" data-property="preset">{{#each presets}}<option value="{{value}}">{{label}}</option>{{/each}}</select></div>
                <div class="form-group" data-section="enabled"><label>{{translate 'basedOn' scope='TaskRecurrence'}}</label><select class="form-control" data-property="basis"><option value="ScheduledDate">{{translate 'ScheduledDate' scope='TaskRecurrence'}}</option><option value="CompletedDate">{{translate 'CompletedDate' scope='TaskRecurrence'}}</option></select></div>
                <p class="text-muted" data-section="completed">{{translate 'completionHint' scope='TaskRecurrence'}}</p>
                <div class="form-group" data-section="enabled"><label>{{translate 'deadline' scope='TaskRecurrence'}}</label><input class="form-control" data-property="deadline" type="{{deadlineType}}"><label><input type="checkbox" data-property="dateOnly"> {{translate 'dateOnly' scope='TaskRecurrence'}}</label><div data-shortcuts>{{#each shortcuts}}<button type="button" class="btn btn-default btn-sm" data-shortcut="{{key}}">{{label}} · {{date}}</button>{{/each}}</div></div>
                <div class="form-group" data-section="enabled"><label>{{translate 'timezone' scope='TaskRecurrence'}}</label><input class="form-control" type="text" data-property="timezone"></div>
                <div class="row" data-section="custom"><div class="col-sm-4 form-group"><label>{{translate 'every' scope='TaskRecurrence'}}</label><input class="form-control" type="number" min="1" max="1000" data-property="interval"></div><div class="col-sm-8 form-group"><label>{{translate 'unit' scope='TaskRecurrence'}}</label><select class="form-control" data-property="unit">{{#each units}}<option value="{{value}}">{{label}}</option>{{/each}}</select></div></div>
                <div class="form-group" data-section="weekdays" role="group" aria-label="{{translate 'weekdays' scope='TaskRecurrence'}}">{{#each weekdays}}<button class="btn btn-default btn-sm" type="button" data-day="{{value}}">{{label}}</button>{{/each}}</div>
                <div data-section="monthly"><label>{{translate 'monthlyPattern' scope='TaskRecurrence'}}</label><select class="form-control" data-property="monthlyPattern"><option value="days">{{translate 'monthDays' scope='TaskRecurrence'}}</option><option value="ordinal">{{translate 'ordinal' scope='TaskRecurrence'}}</option></select>
                    <div data-section="monthDays" role="group" aria-label="{{translate 'monthDays' scope='TaskRecurrence'}}">{{#each monthDays}}<button type="button" class="btn btn-default btn-sm" data-month-day="{{value}}">{{label}}</button>{{/each}}</div>
                    <div data-section="ordinal"><select class="form-control" data-property="ordinal">{{#each ordinals}}<option value="{{value}}">{{label}}</option>{{/each}}</select><div role="group" aria-label="{{translate 'weekdays' scope='TaskRecurrence'}}">{{#each weekdays}}<button type="button" class="btn btn-default btn-sm" data-ordinal-day="{{value}}">{{label}}</button>{{/each}}</div></div>
                </div>
                <div data-section="yearly" class="form-group"><label>{{translate 'months' scope='TaskRecurrence'}}</label><select multiple class="form-control" data-property="months">{{#each months}}<option value="{{value}}">{{label}}</option>{{/each}}</select></div>
                <div data-section="calendar" class="form-group"><label>{{translate 'dates' scope='TaskRecurrence'}}</label><select class="form-control" data-property="calendarMode"><option value="dates">{{translate 'specific' scope='TaskRecurrence'}}</option><option value="additions">{{translate 'additions' scope='TaskRecurrence'}}</option><option value="exclusions">{{translate 'exclusions' scope='TaskRecurrence'}}</option></select><div data-calendar></div><p data-selected-dates class="text-muted"></p></div>
                <fieldset data-section="ends"><legend>{{translate 'ends' scope='TaskRecurrence'}}</legend>{{#each endOptions}}<label class="radio-inline"><input type="radio" name="recurrence-end-{{../cid}}" data-property="ends" value="{{value}}"> {{label}}</label>{{/each}}<input class="form-control" type="date" data-property="until"><input class="form-control" type="number" min="1" max="10000" data-property="count"></fieldset>
                <div data-section="advanced" class="form-group"><label>{{translate 'advanced' scope='TaskRecurrence'}}</label><textarea class="form-control" rows="6" spellcheck="false" data-property="raw"></textarea></div>
                <div data-section="completed" class="form-group"><label>{{translate 'exampleCompletion' scope='TaskRecurrence'}}</label><input class="form-control" type="date" data-property="exampleCompletion"></div>
                {{#if existing}}<div class="form-group"><label>{{translate 'scope' scope='TaskRecurrence'}}</label><select class="form-control" data-property="scope"><option value="ThisAndFollowing">{{translate 'ThisAndFollowing' scope='TaskRecurrence'}}</option><option value="WholeSeries">{{translate 'WholeSeries' scope='TaskRecurrence'}}</option></select></div>{{#if nameEditable}}<div class="form-group"><label>{{translate 'name' category='fields' scope='Task'}}</label><input class="form-control" data-property="name" type="text"></div>{{/if}}{{/if}}
            </form>
            <div class="text-danger" role="alert" data-error></div>
            <div aria-live="polite"><p data-summary></p><p data-hypothetical></p><ol data-preview></ol><p data-context class="text-muted"></p><p data-impact class="text-muted"></p></div>
        `,

        setup: function () {
            Modal.prototype.setup.call(this);
            this.headerText = this.tr('repeat');
            this.buttonList = [{name: 'save', label: 'Save', style: 'primary'}, {name: 'cancel', label: 'Cancel'}];
            this.original = this.options.definition;
            this.nameEditable = this.getAcl().checkField('Task', 'name', 'read') && this.getAcl().checkField('Task', 'name', 'edit');
            const timezone = this.original?.timezone || this.getDateTime().getTimeZone();
            const dateOnly = this.original?.dateOnly ?? !!this.model.get('dateEndDate');
            const instant = this.model.get('dateEnd');
            const deadline = dateOnly ? this.model.get('dateEndDate') || '' : instant ? moment.utc(instant).tz(timezone).format('YYYY-MM-DDTHH:mm:ss') : '';
            const day = moment(deadline).day();
            this.draft = {
                preset: this.original ? (this.original.basis === 'CompletedDate' ? 'custom' : 'advanced') : 'none',
                basis: this.original?.basis || 'ScheduledDate', timezone, dateOnly, deadline,
                unit: this.original?.interval?.unit || 'week', interval: this.original?.interval?.value || 1,
                days: [weekdays[(day + 6) % 7] || 'MO'], monthDays: [Number(deadline.slice(8, 10)) || 1],
                ordinalDays: [weekdays[(day + 6) % 7] || 'MO'], ordinal: -1, monthlyPattern: 'days', months: [Number(deadline.slice(5, 7)) || 1],
                raw: this.original?.schedule || '', dates: [], additions: [], exclusions: [], calendarMode: 'dates',
                ends: this.original?.count ? 'count' : this.original?.until ? 'until' : 'never', count: this.original?.count || 10, until: this.original?.until || '',
                scope: 'ThisAndFollowing', name: this.model.get('name'), exampleCompletion: moment().format('YYYY-MM-DD'),
            };
            const editor = this.options.recurrence?.editor;
            if (editor?.representable) {
                Object.assign(this.draft, editor, {
                    preset: {daily: 'DAILY', weekly: 'WEEKLY', monthly: 'MONTHLY', yearly: 'YEARLY'}[editor.preset] || editor.preset,
                    unit: {DAILY: 'day', WEEKLY: 'week', MONTHLY: 'month', YEARLY: 'year'}[editor.frequency] || editor.unit || this.draft.unit,
                    days: editor.weekdays || this.draft.days, monthlyPattern: editor.monthPattern || this.draft.monthlyPattern,
                });
            }
            this.dirty = false;
            if (!this.original) this.draft.weekStart = weekdays[(this.getDateTime().weekStart + 6) % 7];
            this.sequence = 0;
            this.on('remove', () => { clearTimeout(this.timer); this.sequence++; this.$el.find('[data-calendar]').datepicker('destroy'); });
        },

        tr: function (key) { return this.translate(key, 'labels', 'TaskRecurrence'); },

        presetLabel: function (value) {
            const label = this.tr(value);
            const date = new Date(this.draft.deadline.slice(0, 10) + 'T12:00:00');
            if (Number.isNaN(date.getTime()) || !['WEEKLY', 'MONTHLY', 'YEARLY'].includes(value)) return label;
            const locale = (this.getPreferences().get('language') || this.getConfig().get('language') || 'en_US').replace('_', '-');
            const format = value === 'WEEKLY' ? {weekday: 'long'} : value === 'MONTHLY' ? {day: 'numeric'} : {day: 'numeric', month: 'long'};
            return `${label} (${new Intl.DateTimeFormat(locale, format).format(date)})`;
        },

        summaryText: function (summary) {
            if (summary.frequency === 'RDATE') return this.tr('specific');
            const unit = summary.unit || {DAILY: 'day', WEEKLY: 'week', MONTHLY: 'month', YEARLY: 'year'}[summary.frequency];
            const text = `${this.tr('every')} ${summary.interval} ${this.tr(unit || summary.frequency)}`;
            if (summary.basis === 'CompletedDate') return `${text} · ${this.tr('CompletedDate')}`;
            const parts = summary.parts || {};
            const details = [];
            if (parts.BYDAY) details.push(parts.BYDAY.split(',').map(day => {
                const [, ordinal, weekday] = day.match(/^(-?\d+)?([A-Z]{2})$/) || [];
                return `${ordinal ? this.tr('ordinal' + ordinal) + ' ' : ''}${this.tr(weekday)}`;
            }).join(', '));
            if (parts.BYSETPOS) details.unshift(this.tr('ordinal' + parts.BYSETPOS));
            if (parts.BYMONTHDAY) details.push(`${this.tr('monthDays')}: ${parts.BYMONTHDAY.split(',').map(day => day === '-1' ? this.tr('lastDay') : day).join(', ')}`);
            if (parts.BYMONTH) details.push(`${this.tr('months')}: ${parts.BYMONTH}`);
            return [text, ...details].join(' · ');
        },

        data: function () {
            const locale = (this.getPreferences().get('language') || this.getConfig().get('language') || 'en_US').replace('_', '-');
            return {
                shortcuts: this.shortcuts(),
                nameEditable: this.nameEditable,
                cid: this.cid, existing: !!this.options.recurrence?.seriesId, deadlineType: this.draft.dateOnly ? 'date' : 'datetime-local',
                presets: presets.map(value => ({value, label: this.presetLabel(value)})),
                weekdays: [...weekdays.slice((this.getDateTime().weekStart + 6) % 7), ...weekdays.slice(0, (this.getDateTime().weekStart + 6) % 7)].map(value => ({value, label: this.tr(value)})),
                units: ['day', 'week', 'month', 'year'].map(value => ({value, label: this.tr(value)})),
                endOptions: ['never', 'until', 'count'].map(value => ({value, label: this.tr(value)})),
                ordinals: [1, 2, 3, 4, 5, -1].map(value => ({value, label: this.tr('ordinal' + value)})),
                monthDays: [...Array.from({length: 31}, (_, i) => i + 1), -1].map(value => ({value, label: value === -1 ? this.tr('lastDay') : value})),
                months: Array.from({length: 12}, (_, i) => ({value: i + 1, label: new Intl.DateTimeFormat(locale, {month: 'long'}).format(new Date(2026, i, 1))})),
            };
        },

        shortcuts: function () {
            const today = moment.tz(this.draft.timezone).startOf('day');
            const start = this.getDateTime().weekStart;
            return [['today', 0], ['tomorrow', 1], ['nextWeek', (start - today.day() + 7) % 7 || 7], ['nextWeekend', (6 - today.day() + 7) % 7 || 7], ['nextMonth', null]]
                .map(([key, days]) => ({key, label: this.tr(key), date: days === null ? today.clone().add(1, 'month').format('YYYY-MM-DD') : today.clone().add(days, 'day').format('YYYY-MM-DD')}));
        },

        afterRender: function () {
            Modal.prototype.afterRender.call(this);
            const form = this.el.querySelector('form');
            for (const element of form.querySelectorAll('[data-property]')) {
                const key = element.dataset.property;
                if (element.type === 'checkbox') element.checked = this.draft[key];
                else if (element.type === 'radio') element.checked = element.value === this.draft[key];
                else if (element.multiple) for (const option of element.options) option.selected = this.draft[key].includes(Number(option.value));
                else element.value = this.draft[key] ?? '';
            }
            this.$el.find('[data-calendar]').datepicker({multidate: true, format: 'yyyy-mm-dd', todayHighlight: true,
                language: this.getConfig().get('language'), weekStart: this.getDateTime().weekStart, todayBtn: 'linked'});
            this.$el.find('[data-calendar]').on('changeDate', () => {
                if (this.settingCalendar) return;
                this.draft[this.draft.calendarMode] = this.$el.find('[data-calendar]').datepicker('getFormattedDate').split(',').filter(Boolean).sort();
                this.dirty = true;
                this.el.querySelector('[data-selected-dates]').textContent = this.draft[this.draft.calendarMode].join(', ');
                this.queuePreview();
            });
            form.addEventListener('submit', event => { event.preventDefault(); this.actionSave(); });
            form.addEventListener('change', event => {
                const element = event.target;
                const key = element.dataset.property;
                if (!key) return;
                if (!['exampleCompletion', 'calendarMode', 'scope', 'name'].includes(key)) this.dirty = true;
                this.draft[key] = element.type === 'checkbox' ? element.checked : element.multiple ? [...element.selectedOptions].map(item => Number(item.value)) : element.value;
                if (['interval', 'ordinal', 'count'].includes(key)) this.draft[key] = Number(element.value);
                if (key === 'preset') {
                    if (this.options.recurrence?.editor?.representable === false && element.value === 'custom') {
                        this.draft.preset = 'advanced'; element.value = 'advanced';
                        this.el.querySelector('[data-error]').textContent = this.tr('advancedPreserved');
                    } else { this.original = null; this.draft.start = ''; this.draft.time = ''; this.draft.interval = 1; }
                    if (!['custom', 'advanced'].includes(element.value)) this.draft.basis = 'ScheduledDate';
                }
                if (key === 'dateOnly') this.draft.deadline = this.draft.dateOnly ? this.draft.deadline.slice(0, 10) : this.draft.deadline.slice(0, 10) + 'T09:00:00';
                if (key === 'deadline') { this.original = null; this.draft.start = ''; this.draft.time = ''; }
                this.updateVisibility(); this.queuePreview();
            });
            form.addEventListener('click', event => {
                const button = event.target.closest('button');
                if (!button) return;
                if (button.dataset.shortcut) {
                    const date = this.shortcuts().find(item => item.key === button.dataset.shortcut).date;
                    this.draft.deadline = date + (this.draft.dateOnly ? '' : 'T' + (this.draft.deadline.slice(11) || '09:00:00'));
                    this.original = null; this.draft.start = ''; this.draft.time = ''; this.dirty = true;
                    this.updateVisibility(); this.queuePreview(); return;
                }
                for (const [attribute, key] of [['day', 'days'], ['monthDay', 'monthDays'], ['ordinalDay', 'ordinalDays']]) {
                    if (!button.dataset[attribute]) continue;
                    const value = attribute === 'monthDay' ? Number(button.dataset[attribute]) : button.dataset[attribute];
                    this.draft[key] = this.draft[key].includes(value) ? this.draft[key].filter(item => item !== value) : [...this.draft[key], value];
                    this.dirty = true;
                    this.updateVisibility(); this.queuePreview();
                }
            });
            this.updateVisibility(); this.queuePreview();
        },

        updateVisibility: function () {
            const d = this.draft;
            const enabled = d.preset !== 'none';
            const calendar = d.basis === 'ScheduledDate';
            const monthly = d.preset === 'custom' && calendar && ['month', 'year'].includes(d.unit);
            const sections = {enabled, completed: enabled && !calendar, custom: enabled && (d.preset === 'custom' || !calendar),
                weekdays: d.preset === 'custom' && calendar && d.unit === 'week', monthly,
                monthDays: monthly && d.monthlyPattern === 'days', ordinal: monthly && d.monthlyPattern === 'ordinal',
                yearly: monthly && d.unit === 'year', calendar: enabled && calendar && d.preset !== 'advanced',
                advanced: enabled && calendar && d.preset === 'advanced', ends: enabled && d.preset !== 'specific' && (d.preset !== 'advanced' || !calendar)};
            for (const [key, visible] of Object.entries(sections)) this.$el.find(`[data-section="${key}"]`).toggleClass('hidden', !visible);
            this.$el.find('[data-property="until"]').toggleClass('hidden', d.ends !== 'until');
            this.$el.find('[data-property="count"]').toggleClass('hidden', d.ends !== 'count');
            this.$el.find('[data-property="deadline"]').attr('type', d.dateOnly ? 'date' : 'datetime-local').val(d.deadline);
            this.$el.find('[data-property="deadline"]').prop('disabled', d.preset === 'advanced' && calendar);
            this.$el.find('[data-shortcuts]').toggleClass('hidden', d.preset === 'advanced' && calendar);
            for (const option of this.el.querySelector('[data-property="preset"]').options) option.textContent = this.presetLabel(option.value);
            for (const [attribute, key] of [['day', 'days'], ['month-day', 'monthDays'], ['ordinal-day', 'ordinalDays']]) {
                this.$el.find(`[data-${attribute}]`).each((_, element) => {
                    const value = key === 'monthDays' ? Number(element.getAttribute(`data-${attribute}`)) : element.getAttribute(`data-${attribute}`);
                    const selected = d[key].includes(value);
                    element.setAttribute('aria-pressed', String(selected));
                    element.classList.toggle('btn-primary', selected);
                });
            }
            this.settingCalendar = true;
            this.$el.find('[data-calendar]').datepicker('setDates', d[d.calendarMode]);
            this.settingCalendar = false;
            this.el.querySelector('[data-selected-dates]').textContent = d[d.calendarMode].join(', ');
        },

        definition: function () {
            const d = this.draft;
            if (d.preset === 'none') return null;
            if (this.original && !this.dirty) return this.original;
            const local = moment.tz(d.deadline, d.timezone);
            const definition = {basis: d.basis, timezone: d.timezone, dateOnly: d.dateOnly,
                anchor: (this.original?.dateOnly === d.dateOnly ? this.original.anchor : null) || (d.dateOnly ? d.deadline : local.clone().utc().format('YYYY-MM-DD HH:mm:ss'))};
            if (d.basis === 'CompletedDate') return {...definition, interval: {unit: d.unit, value: d.interval},
                ...(d.ends === 'count' ? {count: d.count} : {}), ...(d.ends === 'until' ? {until: d.until} : {})};
            if (d.preset === 'advanced') return {...definition, schedule: d.raw};
            const property = (name, dates) => `${name}${d.dateOnly ? ';VALUE=DATE' : ';TZID=' + d.timezone}:` + [...new Set(dates)].sort()
                .map(date => date.replace(/-/g, '') + (d.dateOnly ? '' : 'T' + local.format('HHmmss'))).join(',');
            const lines = [property('DTSTART', [d.start || d.deadline.slice(0, 10)])];
            if (d.preset !== 'specific') {
                const frequency = d.preset === 'custom' ? {day: 'DAILY', week: 'WEEKLY', month: 'MONTHLY', year: 'YEARLY'}[d.unit] : d.preset === 'weekdays' ? 'WEEKLY' : d.preset;
                const rule = [`FREQ=${frequency}`, `INTERVAL=${d.interval}`];
                if (frequency === 'WEEKLY') rule.push('BYDAY=' + (d.preset === 'weekdays' ? weekdays.slice(0, 5) : d.preset === 'custom' ? d.days : [weekdays[(local.day() + 6) % 7]]).join(','), 'WKST=' + (d.weekStart || 'MO'));
                if (['MONTHLY', 'YEARLY'].includes(frequency)) {
                    if (d.preset === 'custom' && d.monthlyPattern === 'ordinal') rule.push('BYDAY=' + d.ordinalDays.join(','), 'BYSETPOS=' + d.ordinal);
                    else rule.push('BYMONTHDAY=' + (d.preset === 'custom' ? d.monthDays.join(',') : local.date()));
                }
                if (frequency === 'YEARLY') rule.push('BYMONTH=' + (d.preset === 'custom' ? d.months.join(',') : local.month() + 1));
                if (d.ends === 'count') rule.push('COUNT=' + d.count);
                if (d.ends === 'until') rule.push('UNTIL=' + (d.dateOnly ? d.until.replace(/-/g, '') : moment.tz(d.until + 'T23:59:59', d.timezone).utc().format('YYYYMMDDTHHmmss[Z]')));
                lines.push('RRULE:' + rule.join(';'));
            }
            const dates = d.preset === 'specific' ? d.dates : d.additions;
            if (dates.length) lines.push(property('RDATE', dates));
            if (d.exclusions.length) lines.push(property('EXDATE', d.exclusions));
            return {...definition, schedule: lines.join('\n')};
        },

        queuePreview: function () {
            clearTimeout(this.timer); this.sequence++;
            this.el.querySelector('[data-preview]').replaceChildren();
            this.timer = setTimeout(() => this.preview(), 350);
        },

        preview: async function () {
            const sequence = ++this.sequence;
            this.el.querySelector('[data-error]').textContent = '';
            const definition = this.definition();
            if (!definition) return null;
            try {
                const result = await Espo.Ajax.postRequest('Task/recurrence/preview', {definition, taskId: this.model.id || null,
                    task: {dateStart: this.model.get('dateStart'), dateStartDate: this.model.get('dateStartDate'), dateEnd: this.model.get('dateEnd'), dateEndDate: this.model.get('dateEndDate')},
                    exampleCompletion: moment.tz(this.draft.exampleCompletion + 'T12:00:00', this.draft.timezone).utc().format('YYYY-MM-DD HH:mm:ss')});
                if (sequence !== this.sequence) return null;
                this.validated = result.definition;
                this.previewResult = result;
                this.el.querySelector('[data-summary]').textContent = this.summaryText(result.summary);
                this.el.querySelector('[data-hypothetical]').textContent = result.hypothetical ? this.tr('hypothetical') : '';
                this.el.querySelector('[data-context]').textContent = `${this.draft.timezone} · ${this.tr(this.draft.dateOnly ? 'dateOnly' : 'timed')}`;
                const list = this.el.querySelector('[data-preview]'); list.replaceChildren();
                for (const date of result.dates) {
                    const item = document.createElement('li');
                    item.textContent = this.draft.dateOnly ? date : moment.utc(date).tz(this.draft.timezone).format('LLL');
                    list.append(item);
                }
                return result.definition;
            } catch (error) {
                if (sequence === this.sequence) this.el.querySelector('[data-error]').textContent = error.responseJSON?.message || error.getResponseHeader?.('X-Status-Reason') || this.tr('invalid');
                return null;
            }
        },

        actionSave: async function () {
            if (this.saving) return;
            this.saving = true; this.disableButton('save'); clearTimeout(this.timer);
            try {
                const definition = this.draft.preset === 'none' ? null : await this.preview();
                if (this.draft.preset !== 'none' && !definition) return;
                let outcome = this.previewResult;
                if (this.model.id) {
                    const url = `Task/${encodeURIComponent(this.model.id)}/recurrence`;
                    if (this.options.recurrence?.seriesId) {
                        const body = {action: definition ? 'edit' : 'end', scope: this.draft.scope,
                            version: this.options.recurrence.version, ...(definition ? {definition} : {}), patch: this.nameEditable ? {name: this.draft.name} : {}};
                        const impact = await Espo.Ajax.postRequest(url + '/actions', {...body, preview: true});
                        this.el.querySelector('[data-impact]').textContent = `${impact.affectedCount} ${this.tr('affected')} · ${impact.retainedExceptions} ${this.tr('exceptions')}`;
                        outcome = await Espo.Ajax.postRequest(url + '/actions', body);
                    } else if (definition) await Espo.Ajax.postRequest(url, {definition});
                }
                this.trigger('saved', definition, outcome); this.close();
            } catch (error) {
                this.el.querySelector('[data-error]').textContent = error.responseJSON?.message || error.getResponseHeader?.('X-Status-Reason') || this.tr('invalid');
            } finally { this.saving = false; this.enableButton('save'); }
        },
    });
});
