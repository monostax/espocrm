import BaseFieldView from 'views/fields/base';
import RecordIcon from 'helpers/record-icon';
import IconPicker from 'global:helpers/record-icon-picker';

/** Optional standalone field for custom layouts. The name field includes the same picker. */
export default class RecordIconFieldView extends BaseFieldView {
    detailTemplateContent = '{{{iconHtml}}}';
    listTemplateContent = '{{{iconHtml}}}';
    editTemplateContent = '<button type="button" class="btn btn-default" data-action="pickIcon" aria-haspopup="dialog" aria-label="{{label}}">{{{iconHtml}}}</button>';

    data() {
        return {
            ...super.data(),
            label: this.translate('title', 'labels', 'RecordIcon'),
            iconHtml: RecordIcon.html(this.model.get(this.name), this.getMetadata(),
                RecordIcon.fallback(this, this.model.entityType)),
        };
    }

    setup() {
        super.setup();
        this.addActionHandler('pickIcon', (event, target) => {
            if (!IconPicker.canEdit(this)) { return; }
            IconPicker.open(this, target, this.model.get(this.name), async icon => {
                if (this.isEditMode()) {
                    this.model.set(this.name, icon, {ui: true, fromField: this.name});
                    target.innerHTML = RecordIcon.html(icon, this.getMetadata(), RecordIcon.fallback(this, this.model.entityType));
                    this.trigger('change');
                } else {
                    await IconPicker.save(this, icon);
                }
            });
        });
    }

    fetch() { return {[this.name]: this.model.get(this.name) ?? null}; }
}
