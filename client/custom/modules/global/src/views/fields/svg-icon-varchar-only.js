import SvgIconVarcharFieldView from 'global:views/fields/svg-icon-varchar';

class SvgIconVarcharOnlyFieldView extends SvgIconVarcharFieldView {
    getDisplayMode() {
        return 'iconOnly';
    }
}

export default SvgIconVarcharOnlyFieldView;
