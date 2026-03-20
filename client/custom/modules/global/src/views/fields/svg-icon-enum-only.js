import SvgIconEnumFieldView from 'global:views/fields/svg-icon-enum';

class SvgIconEnumOnlyFieldView extends SvgIconEnumFieldView {
    getDisplayMode() {
        return 'iconOnly';
    }
}

export default SvgIconEnumOnlyFieldView;
