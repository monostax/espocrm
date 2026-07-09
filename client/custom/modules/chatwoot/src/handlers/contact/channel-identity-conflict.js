/**
 * Save-error handler for the `channelIdentityConflict` 409 reason thrown by
 * Espo\Modules\Chatwoot\Hooks\Contact\ChannelIdentities when a submitted
 * channel identity (tenantId, channelType, sourceId) already belongs to
 * another contact.
 *
 * Registered in clientDefs/Contact.json under `saveErrorHandlers`.
 */
export default class ChannelIdentityConflictHandler {

    /**
     * @param {module:views/record/base} view
     */
    constructor(view) {
        this.view = view;
    }

    /**
     * @param {{channelType?: string, sourceId?: string, contactId?: string, contactName?: string}} data
     */
    process(data) {
        data = data || {};

        const language = this.view.getLanguage();

        const channelLabel = data.channelType ?
            language.translateOption(data.channelType, 'channelType', 'ContactChannelIdentity') :
            '';

        let message = language.translate('channelIdentityConflict', 'messages', 'Contact')
            .replace('{channelType}', channelLabel)
            .replace('{sourceId}', data.sourceId || '');

        if (data.contactName) {
            message += '\n' + language.translate('channelIdentityConflictContact', 'messages', 'Contact')
                .replace('{contactName}', data.contactName);
        }

        Espo.Ui.error(message, true);
    }
}
