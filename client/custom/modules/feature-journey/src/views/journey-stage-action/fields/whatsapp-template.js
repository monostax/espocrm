/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * JourneyStageAction params: Meta template picker (by inbox account) +
 * parameter mapping + dry-run render preview (no send).
 *
 * Extends the WhatsApp Campaign templateName field. The sample record has the
 * journey's target type; an Account previews once per contact in its Contatos
 * (the people the action sends to), with a tab per person.
 */
define("feature-journey:views/journey-stage-action/fields/whatsapp-template", [
    "chatwoot:views/whatsapp-campaign/fields/template-name",
    "model",
], function (Dep, Model) {
    return Dep.extend({
        editTemplateContent:
            Dep.prototype.editTemplateContent +
            '<div class="journey-wa-template-test" style="margin-top:14px;padding-top:12px;border-top:1px solid #e3e8ee">' +
                '<div class="text-muted small" style="margin-bottom:8px">' +
                    '{{testSectionLabel}}' +
                '</div>' +
                '<div class="form-group" style="margin-bottom:8px">' +
                    '<label class="control-label small" style="margin-bottom:2px">{{testTargetLabel}}</label>' +
                    '<div class="field journey-wa-test-target" data-name="testTarget"></div>' +
                '</div>' +
                '<button type="button" class="btn btn-default btn-sm journey-wa-render-btn"' +
                    '{{#if renderDisabled}} disabled{{/if}}>' +
                    '<span class="fas fa-eye"></span> {{testButtonLabel}}' +
                '</button>' +
                '{{#if isRendering}}' +
                '<span class="text-muted small" style="margin-left:8px">' +
                    '<span class="fas fa-spinner fa-spin"></span> {{renderingLabel}}' +
                '</span>' +
                '{{/if}}' +
                '{{#if renderError}}' +
                '<div class="text-danger small" style="margin-top:8px">' +
                    '<span class="fas fa-exclamation-triangle"></span> {{renderError}}' +
                '</div>' +
                '{{/if}}' +
                '{{#if hasRenderResult}}' +
                '<div class="journey-wa-render-result" style="margin-top:10px">' +
                    '{{#if hasPeople}}' +
                    '<div style="margin-bottom:8px">' +
                        '<span class="text-muted small" style="margin-right:6px">{{peopleLabel}}</span>' +
                        '{{#each people}}' +
                        '<button type="button" class="btn btn-xs {{#if selected}}btn-primary{{else}}btn-default{{/if}} journey-preview-person" ' +
                            'data-index="{{index}}" style="margin:0 4px 4px 0">' +
                            '{{#if error}}<span class="fas fa-exclamation-triangle"></span> {{/if}}{{label}}' +
                        '</button>' +
                        '{{/each}}' +
                    '</div>' +
                    '{{/if}}' +
                    '<div class="text-muted small" style="margin-bottom:4px">{{previewLabel}}</div>' +
                    '{{#if personError}}' +
                    '<div class="text-danger small"><span class="fas fa-exclamation-triangle"></span> {{personError}}</div>' +
                    '{{else}}' +
                    '<div class="journey-wa-bubble" style="background:#dcf8c6;border-radius:8px;padding:10px 12px;white-space:pre-wrap;font-size:13px;max-width:420px">' +
                        '<strong>{{renderedHeader}}</strong>\n{{renderedBody}}' +
                    '</div>' +
                    '{{/if}}' +
                    '{{#if headerMediaUrl}}' +
                    '<div class="text-muted small" style="margin-top:6px">' +
                        'Header ({{headerMediaType}}): <code>{{headerMediaUrl}}</code>' +
                    '</div>' +
                    '{{/if}}' +
                    '{{#if hasResolvedParams}}' +
                    '<div class="text-muted small" style="margin-top:8px">{{resolvedParamsLabel}}</div>' +
                    '<pre class="small" style="margin:4px 0 0;padding:8px;background:#f7f8fa;border-radius:4px;max-height:160px;overflow:auto">{{resolvedParamsJson}}</pre>' +
                    '{{/if}}' +
                    '<div class="text-muted small" style="margin-top:6px">' +
                        '{{templateMetaLabel}}: <code>{{previewTemplateName}}</code> / {{previewLanguage}} [{{previewCategory}}]' +
                    '</div>' +
                '</div>' +
                '{{/if}}' +
            '</div>',

        isRendering: false,
        renderError: null,
        renderResult: null,
        previewIndex: 0,
        testScope: "Contact",
        _testModel: null,

        data: function () {
            const data = Dep.prototype.data.call(this);

            data.testSectionLabel = this.translate(
                "whatsappTemplateTestSection",
                "labels",
                "JourneyStageAction"
            );
            data.testTargetLabel = this.translate(
                "whatsappTemplateTestTarget",
                "labels",
                "JourneyStageAction"
            );
            data.testButtonLabel = this.translate(
                "whatsappTemplateTestRender",
                "labels",
                "JourneyStageAction"
            );
            data.renderingLabel = this.translate(
                "whatsappTemplateRendering",
                "labels",
                "JourneyStageAction"
            );
            data.previewLabel = this.translate(
                "whatsappTemplatePreview",
                "labels",
                "JourneyStageAction"
            );
            data.resolvedParamsLabel = this.translate(
                "whatsappTemplateResolvedParams",
                "labels",
                "JourneyStageAction"
            );
            data.templateMetaLabel = this.translate(
                "whatsappTemplateMeta",
                "labels",
                "JourneyStageAction"
            );

            data.isRendering = !!this.isRendering;
            data.renderError = this.renderError;
            data.renderDisabled =
                !this.model.get("templateName") || this.isRendering;

            const result = this.renderResult;
            data.hasRenderResult = !!result;

            if (result) {
                const people = result.people || [];
                const current = people[this.previewIndex] || result;

                data.peopleLabel = this.translate("previewPeople", "labels", "JourneyStageAction");
                data.hasPeople = people.length > 1 ||
                    (people.length === 1 && people[0].personType !== result.targetEntityType);
                data.people = people.map((person, index) => ({
                    index: index,
                    label: person.personName || person.personId,
                    selected: index === this.previewIndex,
                    error: !!person.error,
                }));
                data.personError = current.error || null;
                data.renderedBody = current.renderedBody || "";
                data.renderedHeader = current.renderedHeader || "";
                data.headerMediaUrl = result.headerMediaUrl || "";
                data.headerMediaType = result.headerMediaType || "";
                data.previewTemplateName = result.templateName || "";
                data.previewLanguage = result.templateLanguage || "";
                data.previewCategory = result.templateCategory || "";
                data.hasResolvedParams = !current.error && (
                    Object.keys(current.resolvedParams || {}).length > 0 ||
                    Object.keys(current.resolvedHeaderParams || {}).length > 0
                );
                data.resolvedParamsJson = data.hasResolvedParams
                    ? JSON.stringify({body: current.resolvedParams, header: current.resolvedHeaderParams}, null, 2)
                    : "";
            }

            return data;
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.isRendering = false;
            this.renderError = null;
            this.renderResult = null;
            this._testModel = new Model();
            this._testModel.name = "JourneyWhatsAppTemplateTest";
            this._testModel.set({
                testTargetId: null,
                testTargetName: null,
            });

            const resolve = this.options.resolveTargetEntityType;

            if (resolve) {
                resolve().then((entityType) => {
                    if (!entityType || entityType === this.testScope ||
                        !this.getMetadata().get(["scopes", entityType, "entity"])) {
                        return;
                    }

                    this.testScope = entityType;
                    this.renderResult = null;
                    this._testModel.set({testTargetId: null, testTargetName: null});

                    if (this.isRendered()) {
                        this.reRender();
                    }
                }).catch(() => {});
            }
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (!this.isEditMode()) {
                return;
            }

            if (this.getView("testTarget")) {
                this.clearView("testTarget");
            }

            this.createView(
                "testTarget",
                "views/fields/link",
                {
                    model: this._testModel,
                    name: "testTarget",
                    foreignScope: this.testScope,
                    el: this.getSelector() + " .journey-wa-test-target",
                    mode: "edit",
                    defs: {
                        name: "testTarget",
                        type: "link",
                    },
                    params: {},
                },
                (view) => {
                    view.render();
                }
            );

            this.$el.find(".journey-wa-render-btn").off("click.waRender").on("click.waRender", (e) => {
                e.preventDefault();
                this.runRenderTest();
            });

            this.$el.find(".journey-preview-person").off("click.waPerson").on("click.waPerson", (e) => {
                e.preventDefault();
                this.previewIndex = parseInt($(e.currentTarget).attr("data-index"), 10) || 0;
                this.reRender();
            });
        },

        runRenderTest: function () {
            // Flush mapping / header inputs into the helper model first.
            this.onMappingInputChange();
            this.onHeaderMediaUrlChange();

            const templateName = this.model.get("templateName");
            const templateLanguage = this.model.get("templateLanguage") || "pt_BR";
            const targetId = this._testModel
                ? this._testModel.get("testTargetId")
                : null;

            if (!templateName) {
                this.renderError = this.translate(
                    "whatsappTemplateSelectFirst",
                    "messages",
                    "JourneyStageAction"
                );
                this.renderResult = null;
                this.reRender();

                return;
            }

            if (!targetId) {
                this.renderError = this.translate(
                    "whatsappTemplatePickTarget",
                    "messages",
                    "JourneyStageAction"
                );
                this.renderResult = null;
                this.reRender();

                return;
            }

            this.isRendering = true;
            this.renderError = null;
            this.reRender();

            const payload = {
                targetEntityType: this.testScope,
                targetId: targetId,
                templateName: templateName,
                templateLanguage: templateLanguage,
                templateCategory: this.model.get("templateCategory") || "UTILITY",
                templateBody: this.model.get("templateBody") || "",
                parameterMapping: this.model.get("parameterMapping") || {},
                headerParameterMapping: this.model.get("headerParameterMapping") || {},
                templateHeader: this.model.get("templateHeader") || "",
                headerMediaUrl: this.model.get("headerMediaUrl") || null,
                headerMediaType: this.model.get("headerMediaType") || null,
            };

            Espo.Ajax.postRequest(
                "JourneyStageAction/action/renderWhatsAppTemplate",
                payload
            )
                .then((result) => {
                    this.isRendering = false;
                    this.renderError = null;
                    this.renderResult = result || null;
                    this.previewIndex = Math.max(
                        0,
                        ((result && result.people) || []).findIndex((person) => !person.error)
                    );
                    this.reRender();
                })
                .catch((xhr) => {
                    this.isRendering = false;
                    this.renderResult = null;

                    let msg = this.translate(
                        "whatsappTemplateRenderFailed",
                        "messages",
                        "JourneyStageAction"
                    );

                    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }

                    this.renderError = msg;
                    this.reRender();
                });
        },

        onSelectChange: function () {
            Dep.prototype.onSelectChange.call(this);
            this.renderResult = null;
            this.renderError = null;
            this.trigger("change");
        },

        onMappingInputChange: function () {
            Dep.prototype.onMappingInputChange.call(this);
            this.trigger("change");
        },

        onHeaderMediaUrlChange: function () {
            Dep.prototype.onHeaderMediaUrlChange.call(this);
            this.trigger("change");
        },

        /**
         * Parent fetch already gathers template + mapping + media keys.
         */
        fetch: function () {
            return Dep.prototype.fetch.call(this);
        },
    });
});
