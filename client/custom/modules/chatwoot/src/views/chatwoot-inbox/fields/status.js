/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import ForeignEnumFieldView from 'views/fields/foreign-enum';

/**
 * Inbox status is a foreign field from ChatwootInboxIntegration.
 * Website / Email inboxes synced from Chatwoot often have no integration
 * row, so status is null. Fall back to ACTIVE when the inbox exists on
 * Chatwoot (chatwootInboxId / remoteChannelType present).
 */
class ChatwootInboxStatusFieldView extends ForeignEnumFieldView {
    getValueForDisplay() {
        const value = this.getEffectiveStatus();

        if (!value) {
            return super.getValueForDisplay();
        }

        return this.getLanguage().translateOption(
            value,
            'status',
            this.foreignEntityType || 'ChatwootInboxIntegration'
        );
    }

    data() {
        const data = super.data();
        const value = this.getEffectiveStatus();

        if (!value) {
            return data;
        }

        data.value = value;
        data.valueIsSet = true;
        data.isNotEmpty = true;
        data.valueTranslated = this.getLanguage().translateOption(
            value,
            'status',
            this.foreignEntityType || 'ChatwootInboxIntegration'
        );

        const style = (this.styleMap && this.styleMap[value]) ||
            (this.params.style && this.params.style[value]) ||
            null;

        if (style && style !== 'default') {
            data.style = style;
        }

        return data;
    }

    afterRender() {
        super.afterRender();

        if (!this.isReadMode()) {
            return;
        }

        const value = this.getEffectiveStatus();

        if (!value || this.model.get(this.name)) {
            return;
        }

        // super may have rendered empty; paint ACTIVE fallback.
        const label = this.getLanguage().translateOption(
            value,
            'status',
            this.foreignEntityType || 'ChatwootInboxIntegration'
        );
        const style = (this.styleMap && this.styleMap[value]) ||
            (this.params.style && this.params.style[value]) ||
            'success';
        const styleClass = style && style !== 'default' ? `text-${style}` : 'text-default';

        this.$el.empty().append(
            $('<span>').addClass(styleClass).attr('title', label).text(label)
        );
    }

    getEffectiveStatus() {
        const status = this.model.get(this.name);

        if (status) {
            return status;
        }

        if (this.isSyncedChatwootInbox()) {
            return 'ACTIVE';
        }

        return null;
    }

    isSyncedChatwootInbox() {
        if (this.model.get('chatwootInboxId')) {
            return true;
        }

        const remote = this.model.get('remoteChannelType');

        return !!(remote && String(remote).length);
    }
}

export default ChatwootInboxStatusFieldView;
