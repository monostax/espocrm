import Ajax from 'ajax';

/**
 * KB → Email attributes. Always HTML body for compose.
 * Converts Markdown articles via EspoLexical / marked.
 */
class KnowledgeBaseHelper {
    /**
     * @param {module:language} language
     */
    constructor(language) {
        this.language = language;
    }

    /**
     * @param {module:model} model
     * @param {Object} attributes
     * @param {function(Object)} callback
     */
    getAttributesForEmail(model, attributes, callback) {
        attributes = attributes || {};

        attributes.body = this.resolveHtmlBody(model);

        if (attributes.name) {
            attributes.name = attributes.name + ' ';
        } else {
            attributes.name = '';
        }

        attributes.name += this.language.translate('KnowledgeBaseArticle', 'scopeNames') + ': ' +
            model.get('name');

        Ajax.postRequest('KnowledgeBaseArticle/action/getCopiedAttachments', {
            id: model.id,
            parentType: 'Email',
            field: 'attachments',
        }).then(data => {
            attributes.attachmentsIds = data.ids;
            attributes.attachmentsNames = data.names;
            attributes.isHtml = true;

            callback(attributes);
        });
    }

    /**
     * @param {module:model} model
     * @return {string}
     */
    resolveHtmlBody(model) {
        const body = model.get('body') || '';
        const format = model.get('bodyFormat') || 'Html';

        if (format !== 'Markdown') {
            return body;
        }

        if (window.EspoLexical && typeof window.EspoLexical.markdownToHtml === 'function') {
            return window.EspoLexical.markdownToHtml(body);
        }

        // Lazy require lib then convert synchronously if already cached
        if (window.marked) {
            if (typeof window.marked.parse === 'function') {
                return window.marked.parse(body);
            }

            if (typeof window.marked === 'function') {
                return window.marked(body);
            }
        }

        return body;
    }
}

export default KnowledgeBaseHelper;
