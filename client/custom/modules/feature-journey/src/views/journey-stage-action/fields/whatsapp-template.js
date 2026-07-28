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
 * Extends the WhatsApp Campaign templateName field.
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
                    '<div class="text-muted small" style="margin-bottom:4px">{{previewLabel}}</div>' +
                    '<div class="journey-wa-bubble" style="background:#dcf8c6;border-radius:8px;padding:10px 12px;white-space:pre-wrap;font-size:13px;max-width:420px">' +
                        '{{renderedBody}}' +
                    '</div>' +
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
                data.renderedBody = result.renderedBody || "";
                data.headerMediaUrl = result.headerMediaUrl || "";
                data.headerMediaType = result.headerMediaType || "";
                data.previewTemplateName = result.templateName || "";
                data.previewLanguage = result.templateLanguage || "";
                data.previewCategory = result.templateCategory || "";
                data.hasResolvedParams =
                    result.resolvedParams &&
                    Object.keys(result.resolvedParams).length > 0;
                data.resolvedParamsJson = data.hasResolvedParams
                    ? JSON.stringify(result.resolvedParams, null, 2)
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
                    foreignScope: "Contact",
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
                targetEntityType: "Contact",
                targetId: targetId,
                templateName: templateName,
                templateLanguage: templateLanguage,
                templateCategory: this.model.get("templateCategory") || "UTILITY",
                templateBody: this.model.get("templateBody") || "",
                parameterMapping: this.model.get("parameterMapping") || {},
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

