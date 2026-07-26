import RowActionHandler from 'handlers/row-action';
import KnowledgeBaseHelper from 'feature-knowledge-base-editor:helpers/knowledge-base-helper';

class SendInEmailHandler extends RowActionHandler {
    isAvailable(model, action) {
        return this.view.getAcl().checkScope('Email', 'create');
    }

    process(model, action) {
        const parentModel = this.view.getParentView().model;
        const modelFactory = this.view.getModelFactory();
        const collectionFactory = this.view.getCollectionFactory();

        Espo.Ui.notifyWait();

        const ensureLexical = () => {
            if (window.EspoLexical) {
                return Promise.resolve();
            }

            return Espo.loader.requirePromise('lib!lexical-kb').catch(() => {});
        };

        ensureLexical()
            .then(() => model.fetch())
            .then(() => {
                return new Promise(resolve => {
                    if (
                        parentModel.get('contactsIds') &&
                        parentModel.get('contactsIds').length
                    ) {
                        collectionFactory.create('Contact', contactList => {
                            const contactListFinal = [];
                            contactList.url = 'Case/' + parentModel.id + '/contacts';

                            contactList.fetch().then(() => {
                                contactList.forEach(contact => {
                                    if (contact.id === parentModel.get('contactId')) {
                                        contactListFinal.unshift(contact);
                                    } else {
                                        contactListFinal.push(contact);
                                    }
                                });

                                resolve(contactListFinal);
                            });
                        });

                        return;
                    }

                    if (parentModel.get('accountId')) {
                        modelFactory.create('Account', account => {
                            account.id = parentModel.get('accountId');

                            account.fetch()
                                .then(() => resolve([account]));
                        });

                        return;
                    }

                    if (parentModel.get('leadId')) {
                        modelFactory.create('Lead', lead => {
                            lead.id = parentModel.get('leadId');

                            lead.fetch()
                                .then(() => resolve([lead]));
                        });

                        return;
                    }

                    resolve([]);
                });
            })
            .then(list => {
                const attributes = {
                    parentType: 'Case',
                    parentId: parentModel.id,
                    parentName: parentModel.get('name'),
                    name: '[#' + parentModel.get('number') + ']',
                };

                attributes.to = '';
                attributes.cc = '';
                attributes.nameHash = {};

                list.forEach((item, i) => {
                    if (item.get('emailAddress')) {
                        if (i === 0) {
                            attributes.to += item.get('emailAddress') + ';';
                        } else {
                            attributes.cc += item.get('emailAddress') + ';';
                        }

                        attributes.nameHash[item.get('emailAddress')] = item.get('name');
                    }
                });

                const helper = new KnowledgeBaseHelper(this.view.getLanguage());

                helper.getAttributesForEmail(model, attributes, attrs => {
                    const viewName = this.view.getMetadata().get('clientDefs.Email.modalViews.compose') ||
                        'views/modals/compose-email';

                    this.view.createView('composeEmail', viewName, {
                        attributes: attrs,
                        selectTemplateDisabled: true,
                        signatureDisabled: true,
                    }, view => {
                        Espo.Ui.notify(false);

                        view.render();

                        this.view.listenToOnce(view, 'after:send', () => {
                            parentModel.trigger('after:relate');
                        });
                    });
                });
            })
            .catch(() => {
                Espo.Ui.notify(false);
            });
    }
}

export default SendInEmailHandler;
