/***********************************************************************************
 * The contents of this file are subject to the Extension License Agreement
 * ("Agreement") which can be viewed at
 * https://www.espocrm.com/extension-license-agreement/.
 * By copying, installing downloading, or using this file, You have unconditionally
 * agreed to the terms and conditions of the Agreement, and You may not use this
 * file except in compliance with the Agreement. Under the terms of the Agreement,
 * You shall not license, sublicense, sell, resell, rent, lease, lend, distribute,
 * redistribute, market, publish, commercialize, or otherwise transfer rights or
 * usage to the software or any modified version or derivative work of the software
 * created by or for you.
 *
 * Copyright (C) 2015-2025 EspoCRM, Inc.
 *
 * License ID: 99e925c7f52e4853679eb7c383162336
 ************************************************************************************/

define('google:views/google/integration', ['view', 'model'], function (View, Model) {

    return class GoogleIntegrationView extends View {

        templateContent = `
            <div class="button-container">
                <div class="btn-group">
                    <button class="btn btn-primary btn-xs-wide" data-action="save">{{translate 'Save'}}</button>
                    <button class="btn btn-default btn-xs-wide" data-action="cancel">{{translate 'Cancel'}}</button>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-6">
                    <div class="panel panel-default">
                        <div class="panel-body panel-body-form">
                            <div class="cell form-group" data-name="enabled">
                                <label
                                    class="control-label"
                                    data-name="enabled"
                                >{{translate 'enabled' scope='Integration' category='fields'}}</label>
                                <div class="field" data-name="enabled">{{{enabled}}}</div>
                            </div>
                            {{#each fieldDataList}}
                                <div
                                    class="cell form-group"
                                    data-name="{{name}}"
                                >
                                    <label
                                        class="control-label"
                                        data-name="{{name}}"
                                    >{{label}}</label>
                                    <div
                                        class="field"
                                        data-name="{{name}}"
                                    >{{{var name ../this}}}</div>
                                </div>
                            {{/each}}
                        </div>
                    </div>
                </div>
                <div class="col-sm-6">
                    {{#if helpText}}
                    <div class="well">
                        {{complexText helpText}}
                    </div>
                    {{/if}}
                </div>
            </div>
        `

        /**
         * @protected
         * @type {string}
         */
        integration

        /**
         * @private
         * @type {string|null}
         */
        helpText = null

        /**
         * @private
         * @type {{name: string, label: string}[]}
         */
        fieldDataList

        /**
         * @private
         * @type {string[]}
         */
        fieldList

        data() {
            return {
                integration: this.integration,
                fieldDataList: this.fieldDataList,
                helpText: this.helpText,
            };
        }

        setup() {
            this.addActionHandler('save', () => this.save());
            this.addActionHandler('cancel', () => this.actionCancel());

            this.integration = this.options.integration;

            if (this.getLanguage().has(this.integration, 'help', 'Integration')) {
                this.helpText = this.translate(this.integration, 'help', 'Integration');
            }

            this.fieldList = [];
            this.fieldDataList = [];

            this.model = new Model({}, {
                entityType: 'Integration',
                urlRoot: 'Integration',
            });

            this.model.id = this.integration;

            const fieldDefs = {
                enabled: {
                    required: true,
                    type: 'bool',
                },
                redirectUri: {
                    type: 'varchar',
                    readOnly: true,
                    copyToClipboard: true,
                },
            };

            const fields = /** @type {Record<string, Record>} */
                this.getMetadata().get(`integrations.${this.integration}.fields`) ?? {};

            Object.keys(fields).forEach(name => {
                const defs = {...fields[name]};

                fieldDefs[name] = defs;

                let label = this.translate(name, 'fields', 'Integration');

                if (defs.labelTranslation) {
                    label = this.getLanguage().translatePath(defs.labelTranslation);
                }

                this.fieldDataList.push({
                    name: name,
                    label: label,
                });
            });

            this.fieldDataList.push({
                name: 'redirectUri',
                label: this.translate('redirectUri', 'fields', 'Integration')
            });

            this.model.setDefs({fields: fieldDefs});
            this.model.populateDefaults();

            this.model.set('redirectUri', this.getConfig().get('siteUrl') + '?entryPoint=oauthCallback');

            this.wait(
                (async () => {
                    await this.model.fetch();

                    this.createFieldView('bool', 'enabled');
                    this.createFieldView('varchar', 'redirectUri', true, fieldDefs.redirectUri);

                    Object.keys(fields).forEach(name => {
                        this.createFieldView(fields[name].type, name, undefined, fields[name]);
                    });
                })()
            );
        }

        /**
         * @private
         */
        actionCancel() {
            this.getRouter().navigate('#Admin/integrations', {trigger: true});
        }

        /**
         * @protected
         * @param {string} name
         */
        hideField(name) {
            this.$el.find('label[data-name="' + name + '"]').addClass('hide');
            this.$el.find('div.field[data-name="' + name + '"]').addClass('hide');

            const view = this.getView(name);

            if (view) {
                view.disabled = true;
            }
        }

        /**
         * @protected
         * @param {string} name
         */
        showField(name) {
            this.$el.find(`label[data-name="${name}"]`).removeClass('hide');
            this.$el.find(`div.field[data-name="${name}"]`).removeClass('hide');

            const view = this.getFieldView(name);

            if (view) {
                view.disabled = false;
            }
        }

        /**
         * @since 9.0.0
         * @param {string} name
         * @return {import('views/fields/base').default}
         */
        getFieldView(name) {
            return this.getView(name)
        }

        afterRender() {
            if (!this.model.attributes.enabled) {
                this.fieldDataList.forEach(it => this.hideField(it.name));
            }

            this.listenTo(this.model, 'change:enabled', () => {
                if (this.model.attributes.enabled) {
                    this.fieldDataList.forEach(it => this.showField(it.name));
                } else {
                    this.fieldDataList.forEach(it => this.hideField(it.name));
                }
            });
        }

        /**
         * @protected
         * @param {string} type
         * @param {string} name
         * @param {boolean} [readOnly]
         * @param {Record} [params]
         */
        createFieldView(type, name, readOnly, params) {
            const viewName = this.model.getFieldParam(name, 'view') || this.getFieldManager().getViewName(type);

            let labelText = undefined;

            if (params && params.labelTranslation) {
                labelText = this.getLanguage().translatePath(params.labelTranslation);
            }

            this.createView(name, viewName, {
                name: name,
                model: this.model,
                selector: `.field[data-name="${name}"]`,
                params: params,
                mode: readOnly ? 'detail' : 'edit',
                readOnly: readOnly,
                labelText: labelText,
            });

            this.fieldList.push(name);
        }

        /**
         * @protected
         */
        async save() {
            this.fieldList.forEach(field => {
                const view = this.getFieldView(field);

                if (!view.readOnly) {
                    view.fetchToModel();
                }
            });

            let notValid = false;

            this.fieldList.forEach(field => {
                const fieldView = this.getFieldView(field);

                if (fieldView && !fieldView.disabled) {
                    notValid = fieldView.validate() || notValid;
                }
            });

            if (notValid) {
                Espo.Ui.error(this.translate('Not valid'));

                return;
            }

            Espo.Ui.notify(this.translate('saving', 'messages'));

            const attributes = {...this.model.attributes};

            delete attributes.redirectUri;

            await Espo.Ajax.putRequest(`Integration/${this.integration}`, attributes);

            Espo.Ui.success(this.translate('Saved'));
        }
    }
});
