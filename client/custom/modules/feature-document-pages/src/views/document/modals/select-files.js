import DocumentSelectRecordsModalView from 'crm:views/document/modals/select-records';

/** Only used by attachment insertion; relationship pickers can still select pages. */
export default class SelectDocumentFilesModalView extends DocumentSelectRecordsModalView {
    setupSearch() {
        super.setupSearch();
        this.applyCategoryToCollection();
    }

    applyCategoryToCollection() {
        super.applyCategoryToCollection();

        const categoryWhere = this.collection.whereFunction;

        // Keep the file restriction when searching, resetting filters, or changing folders.
        this.collection.whereFunction = () => [
            ...(categoryWhere.call(this.collection) || []),
            {type: 'equals', attribute: 'contentType', value: 'File'},
            {type: 'isNotNull', attribute: 'fileId'},
        ];
    }
}
