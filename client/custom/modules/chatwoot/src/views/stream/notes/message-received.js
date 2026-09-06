import NoteStreamView from 'views/stream/note';

class MessageReceivedNoteStreamView extends NoteStreamView {
    template = 'chatwoot:stream/notes/event';
    isSystemAvatar = true;

    data() {
        const data = this.model.get('data') || {};
        const baseUrl = this.getHelper().getAppParam('chatwootFrontendUrl');
        const accountId = encodeURIComponent(data.chatwootAccountId);
        const conversationId = encodeURIComponent(data.chatwootConversationId);
        const messageId = encodeURIComponent(data.chatwootMessageId);

        return {
            ...super.data(),
            iconClass: 'fas fa-comment',
            label: this.translate('messageReceivedInConversation', 'labels', 'Note')
                .replace('{conversationId}', data.chatwootConversationId),
            eventUrl: baseUrl
                ? `${baseUrl.replace(/\/$/, '')}/app/accounts/${accountId}/conversations/${conversationId}?messageId=${messageId}`
                : `#ChatwootConversation/view/${encodeURIComponent(this.model.get('relatedId'))}`,
        };
    }
}

export default MessageReceivedNoteStreamView;
