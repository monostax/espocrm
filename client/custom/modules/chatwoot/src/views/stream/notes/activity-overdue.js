import NoteStreamView from 'views/stream/note';

class ActivityOverdueNoteStreamView extends NoteStreamView {
    template = 'chatwoot:stream/notes/event';
    isSystemAvatar = true;

    data() {
        return {
            ...super.data(),
            iconClass: 'fas fa-calendar-times',
            label: this.translate('activityBecameOverdue', 'labels', 'Note')
                .replace('{name}', this.model.get('data')?.activityName || ''),
            eventUrl: `#${encodeURIComponent(this.model.get('relatedType'))}/view/${encodeURIComponent(this.model.get('relatedId'))}`,
        };
    }
}

export default ActivityOverdueNoteStreamView;
