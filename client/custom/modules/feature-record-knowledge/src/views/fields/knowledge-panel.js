import BaseFieldView from 'views/fields/base';

/** Hosts an independently saved knowledge panel in a regular record tab. */
export default class extends BaseFieldView {
    templateContent = '<div data-name="knowledgePanel">{{{panel}}}</div>';
    inlineEditDisabled = true;

    setup() {
        super.setup();
        const panel = this.options.panel;
        // Bullbone attaches the parent only after setup and nested views are ready.
        const recordViewObject = this.options.recordViewObject;
        this.wait(this.createView('panel', panel.view, {
            selector: '[data-name="knowledgePanel"]',
            model: this.model,
            panelName: panel.name,
            defs: panel,
            mode: this.mode,
            recordHelper: this.recordHelper,
            recordViewObject,
            readOnly: this.readOnly,
            inlineEditDisabled: true,
        }));
    }

    fetch() { return {}; }
    getAttributeList() { return []; }
    validate() { return false; }
}
