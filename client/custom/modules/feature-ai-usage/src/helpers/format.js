/** Locale-aware usage values; keep the export below the header for Espo's transpiler. */
export default class Format {
    constructor(locale = 'en-US') {
        this.locale = locale.replace('_', '-');
    }

    number(value, compact = false) {
        if (value === null || value === undefined) return '—';
        return new Intl.NumberFormat(this.locale, {
            notation: compact ? 'compact' : 'standard', maximumFractionDigits: compact ? 1 : 0,
        }).format(value);
    }

    money(value, currency, compact = false) {
        const abbreviated = compact && Math.abs(value) >= 1000;
        return new Intl.NumberFormat(this.locale, {
            style: 'currency', currency, notation: abbreviated ? 'compact' : 'standard',
            minimumFractionDigits: abbreviated ? 0 : 2, maximumFractionDigits: abbreviated ? 1 : 2,
        }).format(value);
    }

    charges(charges, compact = false) {
        if (charges === null || charges === undefined) return '—';
        return charges.length ? charges.map(c => this.money(c.amount, c.currency, compact)).join(' · ') : '0';
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
