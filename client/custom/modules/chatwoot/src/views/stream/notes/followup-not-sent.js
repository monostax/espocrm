import NoteStreamView from 'views/stream/note';

class FollowupNotSentNoteStreamView extends NoteStreamView {
    template = 'chatwoot:stream/notes/event';

    data() {
        const data = this.model.get('data') || {};
        return {
            ...super.data(),
            iconClass: 'fas fa-ban',
            label: this.translate('opportunityFollowupNotSent', 'labels', 'Note')
                .replace('{name}', data.aiAgentName || this.model.get('createdByName') || ''),
            eventUrl: `#${encodeURIComponent(this.model.get('parentType'))}/view/${encodeURIComponent(this.model.get('parentId'))}`,
        };
    }
}

export default FollowupNotSentNoteStreamView;
