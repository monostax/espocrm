/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * JourneyStageAction sendEmail: EmailTemplate link (actual filter) +
 * dry-run render preview against a sample Contact (no send).
 */
define("feature-journey:views/journey-stage-action/fields/email-template", [
    "views/fields/base",
    "model",
], function (Dep, Model) {
    return Dep.extend({
        editTemplateContent:
            '<div class="journey-email-template-field">' +
                '<div class="field journey-email-template-link" data-name="emailTemplate"></div>' +
                '<div class="journey-email-template-test" style="margin-top:14px;padding-top:12px;border-top:1px solid #e3e8ee">' +
                    '<div class="text-muted small" style="margin-bottom:8px">' +
                        '{{testSectionLabel}}' +
                    '</div>' +
                    '<div class="form-group" style="margin-bottom:8px">' +
                        '<label class="control-label small" style="margin-bottom:2px">{{testTargetLabel}}</label>' +
                        '<div class="field journey-email-test-target" data-name="testTarget"></div>' +
                    '</div>' +
                    '<button type="button" class="btn btn-default btn-sm journey-email-render-btn"' +
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
                    '<div class="journey-email-render-result" style="margin-top:10px">' +
                        '<div class="text-muted small" style="margin-bottom:4px">{{subjectLabel}}</div>' +
                        '<div style="font-weight:600;margin-bottom:8px">{{renderedSubject}}</div>' +
                        '<div class="text-muted small" style="margin-bottom:4px">{{previewLabel}}</div>' +
                        '{{#if isHtml}}' +
                        '<div class="journey-email-preview-html" ' +
                            'style="width:100%;max-width:560px;max-height:280px;overflow:auto;border:1px solid #e3e8ee;border-radius:6px;background:#fff;padding:12px"></div>' +
                        '{{else}}' +
                        '<pre class="small" style="margin:0;padding:10px;background:#f7f8fa;border-radius:6px;white-space:pre-wrap;max-height:280px;overflow:auto">{{renderedBody}}</pre>' +
                        '{{/if}}' +
                        '{{#if hasAttachments}}' +
                        '<div class="text-muted small" style="margin-top:8px">{{attachmentsLabel}}: {{attachmentsSummary}}</div>' +
                        '{{/if}}' +
                        '<div class="text-muted small" style="margin-top:6px">' +
                            '{{templateMetaLabel}}: <code>{{previewTemplateName}}</code>' +
                        '</div>' +
                    '</div>' +
                    '{{/if}}' +
                '</div>' +
            '</div>',

        isRendering: false,
        renderError: null,
        renderResult: null,
        _testModel: null,
        _htmlBody: null,

        data: function () {
            const data = Dep.prototype.data.call(this);

            data.testSectionLabel = this.translate(
                "emailTemplateTestSection",
                "labels",
                "JourneyStageAction"
            );
            data.testTargetLabel = this.translate(
                "emailTemplateTestTarget",
                "labels",
                "JourneyStageAction"
            );
            data.testButtonLabel = this.translate(
                "emailTemplateTestRender",
                "labels",
                "JourneyStageAction"
            );
            data.renderingLabel = this.translate(
                "emailTemplateRendering",
                "labels",
                "JourneyStageAction"
            );
            data.previewLabel = this.translate(
                "emailTemplatePreview",
                "labels",
                "JourneyStageAction"
            );
            data.subjectLabel = this.translate(
                "emailTemplateSubject",
                "labels",
                "JourneyStageAction"
            );
            data.attachmentsLabel = this.translate(
                "emailTemplateAttachments",
                "labels",
                "JourneyStageAction"
            );
            data.templateMetaLabel = this.translate(
                "emailTemplateMeta",
                "labels",
                "JourneyStageAction"
            );

            data.isRendering = !!this.isRendering;
            data.renderError = this.renderError;
            data.renderDisabled =
                !this.model.get("emailTemplateId") || this.isRendering;

            const result = this.renderResult;
            data.hasRenderResult = !!result;

            if (result) {
                data.renderedSubject = result.subject || "";
                data.renderedBody = result.body || "";
                data.isHtml = !!result.isHtml;
                data.previewTemplateName =
                    result.emailTemplateName ||
                    this.model.get("emailTemplateName") ||
                    "";
                const ids = result.attachmentsIds || [];
                const names = result.attachmentsNames || {};
                data.hasAttachments = ids.length > 0;
                data.attachmentsSummary = ids
                    .map((id) => names[id] || id)
                    .join(", ");
            }

            return data;
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.isRendering = false;
            this.renderError = null;
            this.renderResult = null;
            this._htmlBody = null;
            this._testModel = new Model();
            this._testModel.name = "JourneyEmailTemplateTest";
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

            if (this.getView("emailTemplateLink")) {
                this.clearView("emailTemplateLink");
            }

            if (this.getView("testTarget")) {
                this.clearView("testTarget");
            }

            this.createView(
                "emailTemplateLink",
                "views/fields/link",
                {
                    model: this.model,
                    name: "emailTemplate",
                    foreignScope: "EmailTemplate",
                    el: this.getSelector() + " .journey-email-template-link",
                    mode: "edit",
                    defs: {
                        name: "emailTemplate",
                        type: "link",
                    },
                    params: {
                        primaryFilter: "actual",
                    },
                },
                (view) => {
                    view.render();
                    this.listenTo(view, "change", () => {
                        this.renderResult = null;
                        this.renderError = null;
                        this._htmlBody = null;
                        this.trigger("change");
                        this.reRender();
                    });
                }
            );

            this.createView(
                "testTarget",
                "views/fields/link",
                {
                    model: this._testModel,
                    name: "testTarget",
                    foreignScope: "Contact",
                    el: this.getSelector() + " .journey-email-test-target",
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

            this.$el
                .find(".journey-email-render-btn")
                .off("click.emailRender")
                .on("click.emailRender", (e) => {
                    e.preventDefault();
                    this.runRenderTest();
                });

            if (this.renderResult && this.renderResult.isHtml && this._htmlBody != null) {
                const $box = this.$el.find(".journey-email-preview-html");

                if ($box.length) {
                    // Rendered by server Htmlizer; isolate scripts via DOMPurify if
                    // available, otherwise strip script tags before innerHTML.
                    $box.html(this.sanitizePreviewHtml(this._htmlBody));
                }
            }
        },

        sanitizePreviewHtml: function (html) {
            const raw = String(html || "");

            if (
                typeof DOMPurify !== "undefined" &&
                DOMPurify &&
                typeof DOMPurify.sanitize === "function"
            ) {
                return DOMPurify.sanitize(raw, {
                    USE_PROFILES: { html: true },
                });
            }

            // Drop scripts/event handlers without touching layout markup.
            return raw
                .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, "")
                .replace(/\son\w+\s*=\s*(['"]).*?\1/gi, "")
                .replace(/\son\w+\s*=\s*[^\s>]+/gi, "");
        },

        runRenderTest: function () {
            const templateId = this.model.get("emailTemplateId");
            const targetId = this._testModel
                ? this._testModel.get("testTargetId")
                : null;

            if (!templateId) {
                this.renderError = this.translate(
                    "emailTemplateSelectFirst",
                    "messages",
                    "JourneyStageAction"
                );
                this.renderResult = null;
                this._htmlBody = null;
                this.reRender();

                return;
            }

            if (!targetId) {
                this.renderError = this.translate(
                    "emailTemplatePickTarget",
                    "messages",
                    "JourneyStageAction"
                );
                this.renderResult = null;
                this._htmlBody = null;
                this.reRender();

                return;
            }

            this.isRendering = true;
            this.renderError = null;
            this.reRender();

            Espo.Ajax.postRequest(
                "JourneyStageAction/action/renderEmailTemplate",
                {
                    emailTemplateId: templateId,
                    targetEntityType: "Contact",
                    targetId: targetId,
                }
            )
                .then((result) => {
                    this.isRendering = false;
                    this.renderError = null;
                    this.renderResult = result || null;
                    this._htmlBody =
                        result && result.isHtml ? result.body || "" : null;
                    this.reRender();
                })
                .catch((xhr) => {
                    this.isRendering = false;
                    this.renderResult = null;
                    this._htmlBody = null;

                    let msg = this.translate(
                        "emailTemplateRenderFailed",
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

        fetch: function () {
            const id = this.model.get("emailTemplateId") || null;
            const name = this.model.get("emailTemplateName") || null;
            const data = {
                emailTemplateId: id,
                emailTemplateName: name,
            };

            return data;
        },
    });
});
