import IntFieldView from 'views/fields/int';

class PlanIncludedUsageFieldView extends IntFieldView {
    setup() {
        super.setup();
        this.listenTo(this.model, 'change:billingModel', () => {
            if (this.isRendered()) {
                this.updateLabel();
            }
        });
    }

    afterRender() {
        super.afterRender();
        this.updateLabel();
    }

    getLabelText() {
        const label = {
            pack199: 'planIncludedPacks',
            extra049: 'planIncludedBases',
        }[this.model.get('billingModel')];

        return label ? this.translate(label, 'labels', 'TenantAiBillingRate') : super.getLabelText();
    }

    updateLabel() {
        this.getLabelElement()?.find('.label-text').text(this.getLabelText());
    }
}

export default PlanIncludedUsageFieldView;
