import VarcharFieldView from 'views/fields/varchar';
import RecordIcon from 'helpers/record-icon';
import IconPicker from 'global:helpers/record-icon-picker';

/** A normal name field with an independently stored record icon. */
export default class RecordIconNameFieldView extends VarcharFieldView {
    getAttributeList() {
        return [...super.getAttributeList(), RecordIcon.attribute(this, this.model.entityType)].filter(Boolean);
    }

    setup() {
        super.setup();
        this.addHandler('click', '.record-icon-button', (event, target) => {
            event.preventDefault();
            event.stopPropagation();
            if (!IconPicker.canEdit(this)) { return; }
            const attribute = RecordIcon.attribute(this, this.model.entityType);
            IconPicker.open(this, target, this.model.get(attribute), async icon => {
                if (this.isEditMode()) {
                    this.model.set(attribute, icon, {ui: true, fromField: this.name});
                    target.innerHTML = this.iconHtml();
                    this.trigger('change');
                } else {
                    await IconPicker.save(this, icon);
                }
            });
        });
        this.listenTo(this.model, 'sync', (model, response, options) => {
            RecordIcon.remember(this, this.model.entityType, this.model.attributes, options?.action === 'save');
        });
    }

    iconHtml() {
        return RecordIcon.html(this.model.get(RecordIcon.attribute(this, this.model.entityType)),
            this.getMetadata(), RecordIcon.fallback(this, this.model.entityType));
    }

    afterRender() {
        super.afterRender();
        if (this.isSearchMode()) { return; }
        const editable = !this.isListMode() && IconPicker.canEdit(this);
        const icon = document.createElement(editable ? 'button' : 'span');
        icon.innerHTML = this.iconHtml();
        icon.className = editable ? 'record-icon-button' : 'record-icon-slot';
        if (editable) {
            icon.type = 'button';
            icon.title = this.translate('title', 'labels', 'RecordIcon');
            icon.setAttribute('aria-label', icon.title);
            icon.setAttribute('aria-haspopup', 'dialog');
            icon.setAttribute('aria-expanded', 'false');
        }
        if (this.isEditMode()) {
            this.element.classList.add('record-icon-name-edit');
            this.element.prepend(icon);
        } else {
            this.element.classList.remove('record-icon-name-edit');
            const link = this.element.querySelector('a[href]');
            if (link && !editable) { link.prepend(icon); }
            else { this.element.prepend(icon); }
        }
    }

    fetch() {
        return {
            ...super.fetch(),
            [RecordIcon.attribute(this, this.model.entityType)]:
                this.model.get(RecordIcon.attribute(this, this.model.entityType)) ?? null,
        };
    }
}
