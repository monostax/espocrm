define('global:helpers/opportunity-stage-requirements', [], () => {
    const parse = xhr => {
        try { return JSON.parse(xhr.responseText); } catch { return null; }
    };

    const install = (view, model = view.model) => {
        if (!model) return;
        model.stageRequirementsHost = view;
        if (model.stageRequirementsInstalled) return;
        model.stageRequirementsInstalled = true;
        const originalSave = model.save;

        // Keep the original save promise pending while completing requirements. This
        // covers full/inline saves and both Kanban implementations without a second
        // partial save of the custom-field bag.
        model.save = function (attributes, options = {}) {
            const host = this.stageRequirementsHost;
            const submitted = Espo.Utils.clone(attributes || this.attributes);
            let request;
            const run = async () => {
                let values = submitted;
                while (true) {
                    try {
                        request = originalSave.call(this, values, { ...options, error: undefined });
                        return await request;
                    } catch (xhr) {
                        const data = parse(xhr);
                        if (data?.code === 'opportunityStageConfiguration') {
                            xhr.errorIsHandled = true;
                            Espo.Ui.error(host.translate('stageRequirementsConfiguration', 'messages', 'Opportunity'));
                            throw xhr;
                        }
                        if (data?.code !== 'opportunityStageRequirements') {
                            options.error?.call(options.context, this, xhr, options);
                            throw xhr;
                        }
                        xhr.errorIsHandled = true;
                        if (data.canEdit === false || host.getAcl().getScopeForbiddenFieldList('Opportunity', 'edit').includes('customFields')) {
                            Espo.Ui.error(host.translate('stageRequirementsForbidden', 'messages', 'Opportunity'));
                            throw xhr;
                        }
                        const stored = this.isNew() ? {} : await Espo.Ajax.getRequest('Opportunity/' + this.id);
                        const bag = Object.prototype.hasOwnProperty.call(values, 'customFields')
                            ? (values.customFields || {})
                            : (this.isNew() ? this.get('customFields') || {} : stored.customFields || {});
                        const result = await new Promise((resolve, reject) => {
                            host.createView('stageRequirementsDialog', 'global:views/opportunity/modals/stage-requirements', {
                                requirements: data,
                                opportunityName: stored.name || this.get('name'),
                                values: bag,
                            }).then(dialog => {
                                let completed = false;
                                dialog.once('complete', customFields => {
                                    completed = true;
                                    resolve(customFields);
                                });
                                dialog.once('remove', () => {
                                    if (!completed) reject(xhr);
                                });
                                dialog.render();
                            }).catch(reject);
                        });
                        values = { ...values, customFields: result, stageRequirementsSnapshot: data.snapshot };
                    }
                }
            };
            const promise = run();
            Object.defineProperty(promise, 'xhr', { get: () => request?.xhr });
            promise.abort = () => request?.abort();
            return promise;
        };
    };
    return { install };
});
