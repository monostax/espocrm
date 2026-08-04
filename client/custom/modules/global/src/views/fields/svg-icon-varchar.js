import VarcharFieldView from 'views/fields/varchar';

const SVG_ICON_FILE_MAP = {
    whatsapp: 'whatsapp.svg',
    instagram: 'instagram.svg',
    telegram: 'telegram.svg',
    messenger: 'messenger.svg',
    mail: 'mail.svg',
    website: 'website.svg',
    google: 'google.svg',
    outlook: 'outlook.svg',
};

const DEFAULT_CONTAINS_MAP = {
    whatsapp: 'whatsapp',
    waha: 'whatsapp',
    telegram: 'telegram',
    instagram: 'instagram',
    facebook: 'messenger',
    messenger: 'messenger',
    webwidget: 'website',
    web_widget: 'website',
    website: 'website',
    gmail: 'google',
    google: 'google',
    outlook: 'outlook',
    microsoft: 'outlook',
    office365: 'outlook',
    email: 'mail',
    mail: 'mail',
};

class SvgIconVarcharFieldView extends VarcharFieldView {
    getDisplayMode() {
        return this.options.svgDisplayMode ||
            this.params.svgDisplayMode ||
            this.model.getFieldParam(this.name, 'svgDisplayMode') ||
            'iconLabel';
    }

    data() {
        const data = super.data();
        const value = this.getIconSourceValue();
        const iconFile = this.getIconFile(value);

        data.displayValue = this.model.get(this.name) || value;
        data.iconUrl = iconFile ? `${this.getBasePath()}client/custom/modules/global/res/icons/${iconFile}` : null;

        return data;
    }

    afterRender() {
        super.afterRender();

        if (!this.isReadMode()) {
            return;
        }

        const sourceValue = this.getIconSourceValue();
        const iconFile = this.getIconFile(sourceValue);

        if (!iconFile || this.$el.find('.svg-icon-field-img').length) {
            return;
        }

        const iconUrl = `${this.getBasePath()}client/custom/modules/global/res/icons/${iconFile}`;
        const $img = $('<img class="svg-icon-field-img" alt="" width="14" height="14">').attr('src', iconUrl);
        const displayMode = this.getDisplayMode();
        const value = this.model.get(this.name) || sourceValue || '';

        $img.css({
            display: 'inline-block',
            verticalAlign: 'middle',
            marginRight: '6px',
        });

        if (displayMode === 'iconOnly') {
            $img.css({ marginRight: '0' });
            this.$el.empty().append($img);

            if (value) {
                this.$el.attr('title', value);
            }

            return;
        }

        const $text = this.$el.find('span').first();

        if ($text.length) {
            $text.prepend($img);
        } else {
            this.$el.prepend($img);
        }
    }

    getIconSourceValue() {
        const value = this.model.get(this.name) || '';

        if (value) {
            return this.resolveEmailProviderIcon(value) || value;
        }

        const remote = this.model.get('remoteChannelType') || '';
        const provider = String(this.model.get('provider') || '').toLowerCase();

        if (remote) {
            if (this.isEmailRemote(remote)) {
                return this.providerToIconName(provider) || 'mail';
            }

            return remote;
        }

        if (provider) {
            return this.providerToIconName(provider) || provider;
        }

        return '';
    }

    resolveEmailProviderIcon(value) {
        const normalized = String(value || '').toLowerCase();

        if (normalized !== 'email' && normalized !== 'mail' && !this.isEmailRemote(value)) {
            return null;
        }

        const provider = String(this.model.get('provider') || '').toLowerCase();

        return this.providerToIconName(provider);
    }

    providerToIconName(provider) {
        if (!provider) {
            return null;
        }

        if (provider.includes('google') || provider.includes('gmail')) {
            return 'google';
        }

        if (
            provider.includes('microsoft') ||
            provider.includes('outlook') ||
            provider.includes('office365') ||
            provider.includes('office_365')
        ) {
            return 'outlook';
        }

        return null;
    }

    isEmailRemote(value) {
        const normalized = String(value || '').toLowerCase();

        return normalized.includes('email') || normalized === 'mail';
    }

    getIconFile(value) {
        const byValue = this.params.svgIconByValue || this.model.getFieldParam(this.name, 'svgIconByValue') || {};
        const byContains = this.params.svgIconByContains || this.model.getFieldParam(this.name, 'svgIconByContains') || {};

        const valueString = String(value || '');
        const iconName =
            byValue[valueString] ||
            this.pickByContains(valueString, byContains) ||
            this.pickByContains(valueString, DEFAULT_CONTAINS_MAP) ||
            (SVG_ICON_FILE_MAP[valueString] ? valueString : null);

        return iconName ? SVG_ICON_FILE_MAP[iconName] || null : null;
    }

    pickByContains(valueString, containsMap) {
        const normalizedValue = valueString.toLowerCase().replace(/::/g, '');

        for (const [needle, iconName] of Object.entries(containsMap)) {
            if (normalizedValue.includes(String(needle).toLowerCase().replace(/::/g, ''))) {
                return iconName;
            }
        }

        return null;
    }
}

export default SvgIconVarcharFieldView;
