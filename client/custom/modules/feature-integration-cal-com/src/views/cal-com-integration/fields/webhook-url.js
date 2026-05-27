/**
 * Computed read-only "Webhook URL" for a CalComIntegration.
 *
 * The URL is `${siteUrl}/api/v1/CalCom/receive/${id}` — i.e. the
 * cal.com webhook endpoint pinned to this integration's entity id.
 * Shows a copy-to-clipboard button next to the value.
 *
 * Mirrors the pattern of `views/settings/fields/oidc-redirect-uri`.
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
            <span class="none-value">{{translate 'webhookUrlPendingSave' category='messages' scope='CalComIntegration'}}</span>
        {{/if}}
    `

    setup() {
        // The parent varchar view only binds the [data-action="copyToClipboard"]
        // click handler when this param is truthy. Enable it before super.setup()
        // so the events hash is populated before render.
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

        return `${siteUrl}/api/v1/CalCom/receive/${id}`;
    }
}
