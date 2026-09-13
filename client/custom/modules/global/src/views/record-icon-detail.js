import DetailView from 'views/detail';
import RecordIcon from 'helpers/record-icon';
import IconPicker from 'global:helpers/record-icon-picker';

export default class RecordIconDetailView extends DetailView {
    setup() {
        super.setup();
        this.addHandler('click', '[data-action="pickRecordIcon"]', (event, target) => {
            event.preventDefault();
            event.stopPropagation();
            if (!IconPicker.canEdit(this) || this.getRecordMode() !== 'detail') { return; }
            IconPicker.open(this, target, this.model.get(RecordIcon.attribute(this, this.model.entityType)),
                icon => IconPicker.save(this, icon));
        });
        this.listenTo(this.model, `change:${RecordIcon.attribute(this, this.model.entityType)}`, () => {
            this.getHeaderView()?.reRender();
        });
        this.listenTo(this.model, 'sync', (model, response, options) => {
            RecordIcon.remember(this, this.model.entityType, this.model.attributes, options?.action === 'save');
        });
    }

    getHeader() {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = super.getHeader();
        const title = wrapper.querySelector('.title');
        if (!title) { return wrapper.innerHTML; }
        const editable = this.getRecordMode() === 'detail' && IconPicker.canEdit(this);
        const icon = document.createElement(editable ? 'button' : 'span');
        icon.className = editable ? 'record-icon-button' : 'record-icon-slot';
        icon.innerHTML = RecordIcon.html(this.model.get(RecordIcon.attribute(this, this.model.entityType)),
            this.getMetadata(), RecordIcon.fallback(this, this.model.entityType));
        if (editable) {
            icon.type = 'button';
            icon.dataset.action = 'pickRecordIcon';
            icon.title = this.translate('title', 'labels', 'RecordIcon');
            icon.setAttribute('aria-label', icon.title);
            icon.setAttribute('aria-haspopup', 'dialog');
            icon.setAttribute('aria-expanded', 'false');
        }
        title.before(icon);
        return wrapper.innerHTML;
    }
}
