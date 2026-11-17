import NoteStreamView from 'views/stream/note';

class StageChangedNoteStreamView extends NoteStreamView {
    template = 'chatwoot:stream/notes/event';

    data() {
        const data = this.model.get('data') || {};
        return {
            ...super.data(),
            iconClass: 'fas fa-exchange-alt',
            label: this.translate('opportunityStageChanged', 'labels', 'Note')
                .replace('{name}', this.model.get('createdByName') || '')
                .replace('{stage}', data.toStageName || ''),
            eventUrl: `#${encodeURIComponent(this.model.get('parentType'))}/view/${encodeURIComponent(this.model.get('parentId'))}`,
        };
    }
}

export default StageChangedNoteStreamView;
