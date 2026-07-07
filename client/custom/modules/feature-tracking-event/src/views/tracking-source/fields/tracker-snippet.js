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
 * Computed read-only install snippet for a kind=Website TrackingSource.
 *
 * Renders the copy-paste <script> tag a customer drops into their site:
 * an async command-queue loader for
 * `client/custom/modules/feature-tracking-event/lib/tracker.js` (served
 * statically via the /client/ Apache alias) followed by
 * `mstx('init', ingestUrl)` + `mstx('page')`.
 *
 * Only shown for kind=Website (dynamicLogic in clientDefs). Mirrors the
 * ingest-url field pattern (copy-to-clipboard, pending-save placeholder).
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
            <pre
                class="text-monospace small"
                style="white-space: pre-wrap; word-break: break-all; margin: 0; user-select: all;"
            >{{value}}</pre>
        {{else}}
            <span class="none-value">{{translate 'trackerSnippetPendingSave' category='messages' scope='TrackingSource'}}</span>
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

        const trackerUrl = `${siteUrl}/client/custom/modules/feature-tracking-event/lib/tracker.js`;
        const ingestUrl = `${siteUrl}/api/v1/TrackingEvent/receive/${id}`;

        return '<!-- Monostax Tracker -->\n' +
            '<script>\n' +
            '(function(w,d,u,n){w[n]=w[n]||function(){(w[n].q=w[n].q||[]).push(arguments)};\n' +
            'var s=d.createElement("script");s.async=true;s.src=u;d.head.appendChild(s)})\n' +
            `(window,document,"${trackerUrl}","mstx");\n` +
            `mstx("init","${ingestUrl}");\n` +
            'mstx("page");\n' +
            '</script>';
    }
}
