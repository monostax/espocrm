import NoteStreamView from 'views/stream/note';

class FollowupSentNoteStreamView extends NoteStreamView {
    template = 'chatwoot:stream/notes/event';

    data() {
        const data = this.model.get('data') || {};
        return {
            ...super.data(),
            iconClass: 'fas fa-paper-plane',
            label: this.translate(data.last ? 'opportunityFollowupSentLast' : 'opportunityFollowupSent', 'labels', 'Note')
                .replace('{name}', this.model.get('createdByName') || data.aiAgentName || ''),
            eventUrl: `#${encodeURIComponent(this.model.get('parentType'))}/view/${encodeURIComponent(this.model.get('parentId'))}`,
        };
    }
}

export default FollowupSentNoteStreamView;
