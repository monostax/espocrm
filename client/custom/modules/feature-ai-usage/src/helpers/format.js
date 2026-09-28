/** Locale-aware usage values; keep the export below the header for Espo's transpiler. */
export default class Format {
    constructor(locale = 'en-US') {
        this.locale = locale.replace('_', '-');
    }

    number(value) {
        if (value === null || value === undefined) return '—';
        return new Intl.NumberFormat(this.locale, {
            notation: 'standard', maximumFractionDigits: 0,
        }).format(value);
    }

    money(value, currency) {
        return new Intl.NumberFormat(this.locale, {
            style: 'currency', currency, notation: 'standard',
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        }).format(value);
    }

    charges(charges) {
        if (charges === null || charges === undefined) return '—';
        return charges.length ? charges.map(c => this.money(c.amount, c.currency)).join(' · ') : '0';
    }

    date(value, timeZone, withTime = false) {
        if (!value) return '—';
        const date = new Date(value.includes('T') ? value : value.includes(' ') ? value.replace(' ', 'T') + 'Z' : value + 'T12:00:00Z');
        return new Intl.DateTimeFormat(this.locale, {
            ...(withTime ? {dateStyle: 'medium', timeStyle: 'short'} : {day: 'numeric', month: 'short'}),
            timeZone: withTime ? timeZone : 'UTC',
        }).format(date);
    }

    month(value) {
        return new Intl.DateTimeFormat(this.locale, {month: 'long', year: 'numeric', timeZone: 'UTC'})
            .format(new Date(value + '-01T12:00:00Z'));
    }
}
