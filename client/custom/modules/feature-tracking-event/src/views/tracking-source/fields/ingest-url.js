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
 * Computed read-only "Ingest URL" for a TrackingSource.
 *
 * The URL is `${siteUrl}/api/v1/TrackingEvent/receive/${id}` — the public
 * ingestion endpoint pinned to this source's entity id. Shows a copy-to-
 * clipboard button next to the value.
 *
 * Mirrors `feature-integration-cal-com:views/cal-com-integration/fields/webhook-url`.
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
            <span class="none-value">{{translate 'ingestUrlPendingSave' category='messages' scope='TrackingSource'}}</span>
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
        const id = this.model.id;

        if (!id) {
            return null;
        }

        const siteUrl = (this.getConfig().get('siteUrl') || '').replace(/\/+$/, '');

        if (!siteUrl) {
            return null;
        }

        return `${siteUrl}/api/v1/TrackingEvent/receive/${id}`;
    }
}
