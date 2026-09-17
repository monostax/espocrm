define('global:helpers/stage-time', [], function () {
    const format = seconds => {
        seconds = Math.max(0, Math.floor(Number(seconds) || 0));
        if (seconds < 60) return `${seconds}s`;
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor(seconds % 86400 / 3600);
        const minutes = Math.floor(seconds % 3600 / 60);
        return [days ? `${days}d` : '', hours ? `${hours}h` : '', minutes ? `${minutes}m` : '']
            .filter(Boolean).join(' ');
    };

    const timestamp = value => value ? Date.parse(value.replace(' ', 'T') + 'Z') : NaN;

    const describe = (attributes, translate, now = Date.now()) => {
        if (attributes.status === 'Won' || attributes.status === 'Lost') {
            return {text: translate('Timing stopped'), overdue: false, active: false};
        }
        const start = timestamp(attributes.stageEnteredAt);
        if (!attributes.currentStageVisitId || !Number.isFinite(start)) {
            return {text: translate('Timing unavailable'), overdue: false, active: false};
        }
        const elapsed = Math.max(0, Math.floor((now - start) / 1000));
        const target = attributes.stageTargetTimeSeconds;
        const overdue = target != null && elapsed > target;
        let text = format(elapsed);
        if (target != null) {
            text += ` / ${format(target)} · ` + (overdue ?
                `${translate('Overdue by')} ${format(elapsed - target)}` :
                `${translate('Remaining')} ${format(target - elapsed)}`);
        }
        if (attributes.stageTimingIsPartial) text += ` · ${translate('Partial history')}`;
        return {text, overdue, active: true};
    };

    return {format, timestamp, describe};
});
