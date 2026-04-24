import EnumFieldView from 'views/fields/enum';

const SVG_ICON_FILE_MAP = {
    whatsapp: 'whatsapp.svg',
    instagram: 'instagram.svg',
};

class SvgIconEnumFieldView extends EnumFieldView {
    getDisplayMode() {
        return this.options.svgDisplayMode ||
            this.params.svgDisplayMode ||
            this.model.getFieldParam(this.name, 'svgDisplayMode') ||
            'iconLabel';
    }

    data() {
        const data = super.data();
        const value = this.model.get(this.name) || '';
        const iconFile = this.getIconFile(value);

        data.displayValue = data.valueTranslated || value;
        data.iconUrl = iconFile ? `${this.getBasePath()}client/custom/modules/global/res/icons/${iconFile}` : null;

        return data;
    }

    afterRender() {
        super.afterRender();

        if (!this.isReadMode()) {
            return;
        }

        const iconFile = this.getIconFile(this.model.get(this.name) || '');

        if (!iconFile || this.$el.find('.svg-icon-field-img').length) {
            return;
        }

        const iconUrl = `${this.getBasePath()}client/custom/modules/global/res/icons/${iconFile}`;
        const $img = $('<img class="svg-icon-field-img" alt="" width="14" height="14">').attr('src', iconUrl);
        const displayMode = this.getDisplayMode();
        const valueTranslated = this.getLanguage().translateOption(this.model.get(this.name) || '', this.name, this.entityType);

        $img.css({
            display: 'inline-block',
            verticalAlign: 'middle',
            marginRight: '6px',
        });

        if (displayMode === 'iconOnly') {
            $img.css({ marginRight: '0' });
            this.$el.empty().append($img);

            if (valueTranslated) {
                this.$el.attr('title', valueTranslated);
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

    getIconFile(value) {
        const byValue = this.params.svgIconByValue || this.model.getFieldParam(this.name, 'svgIconByValue') || {};
        const byContains = this.params.svgIconByContains || this.model.getFieldParam(this.name, 'svgIconByContains') || {};

        const valueString = String(value || '');
        const iconName =
            byValue[valueString] ||
            this.pickByContains(valueString, byContains) ||
            this.pickByContains(valueString, { whatsapp: 'whatsapp', waha: 'whatsapp' });

        return iconName ? SVG_ICON_FILE_MAP[iconName] || null : null;
    }

    pickByContains(valueString, containsMap) {
        const normalizedValue = valueString.toLowerCase();

        for (const [needle, iconName] of Object.entries(containsMap)) {
            if (normalizedValue.includes(String(needle).toLowerCase())) {
                return iconName;
            }
        }

        return null;
    }
}

export default SvgIconEnumFieldView;
