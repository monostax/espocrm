import DocumentListView from 'crm:views/document/list';

export default class DocumentPagesListView extends DocumentListView {
    quickCreate = false

    setupCreateButton() {
        this.addMenuItem('buttons', {
            action: 'create',
            text: this.translate('New Page', 'labels', 'Document'),
            iconHtml: '<span class="fas fa-plus fa-sm"></span>',
            style: 'default',
            acl: 'create',
            aclScope: this.scope,
        }, true);

        this.addMenuItem('buttons', {
            action: 'quickCreate',
            text: this.translate('Upload File', 'labels', 'Document'),
            iconHtml: '<span class="fas fa-upload fa-sm"></span>',
            style: 'default',
            acl: 'create',
            aclScope: this.scope,
        });
    }

    getCreateAttributes() {
        return {
            ...super.getCreateAttributes(),
            contentType: this.createContentType || 'File',
        };
    }

    actionCreate(data) {
        this.createContentType = 'Page';

        try {
            return super.actionCreate(data);
        } finally {
            this.createContentType = null;
        }
    }

    // Native quick-create (including drag-and-drop) uses File attributes synchronously.
}
