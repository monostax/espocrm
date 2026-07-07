/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Computed read-only public short URL for a TrackingLink.
 *
 * Prefers the dedicated short-link origin (config `trackingLinkDomain`,
 * e.g. https://mstx.to) — kept separate from the CRM origin so public
 * links never carry the login domain's trust (phishing) nor put it at
 * blocklist risk. Falls back to the API path on siteUrl when no dedicated
 * domain is configured.
 *
 * Mirrors the ingest-url field pattern (copy-to-clipboard, pending-save
 * placeholder).
 */
import VarcharFieldView from 'views/fields/varchar';

// noinspection JSUnusedGlobalSymbols
export default class extends VarcharFieldView {

    detailTemplateContent = `
        {{#if isNotEmpty}}
            <a
                role="button"
                data-action="copyToClipboard"
                class="pull-right text-soft"
                title="{{translate 'Copy to Clipboard'}}"
            ><span class="far fa-copy"></span></a>
            <span class="text-monospace" style="word-break: break-all;">{{value}}</span>
        {{else}}
            <span class="none-value">{{translate 'shortUrlPendingSave' category='messages' scope='TrackingLink'}}</span>
        {{/if}}
    `

    setup() {
        this.params.copyToClipboard = true;

        super.setup();
    }

    data() {
        const value = this.getValueForDisplay();

        return {
            value: value,
            isNotEmpty: !!value,
        };
    }

    /**
     * @protected
     */
    copyToClipboard() {
        const value = this.getValueForDisplay();

        if (!value) {
            return;
        }

        navigator.clipboard.writeText(value).then(() => {
            Espo.Ui.success(this.translate('Copied to clipboard'));
        });
    }

    /**
     * @return {string|null}
     */
    getValueForDisplay() {
        const slug = this.model.get('slug');

        if (!this.model.id || !slug) {
            return null;
        }

        const linkDomain = (this.getConfig().get('trackingLinkDomain') || '').replace(/\/+$/, '');

        if (linkDomain) {
            return `${linkDomain}/${slug}`;
        }

        const siteUrl = (this.getConfig().get('siteUrl') || '').replace(/\/+$/, '');

        if (!siteUrl) {
            return null;
        }

        return `${siteUrl}/api/v1/TrackingLink/go/${slug}`;
    }
}
