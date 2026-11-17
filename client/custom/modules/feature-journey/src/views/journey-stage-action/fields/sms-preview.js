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
 * JourneyStageAction sendSms: dry-run render of the message against a sample
 * record of the journey's target type (no send). An Account previews once per
 * contact in its Contatos (the people the action sends to), with a tab per
 * person and the mobile numbers each SMS goes to.
 *
 * Options: getBody() → {mode, fixed, expression} of the message field,
 * resolveJourneyContext() → Promise<{entityType, tenantId}>.
 */
define("feature-journey:views/journey-stage-action/fields/sms-preview", [
    "view",
    "model",
], function (Dep, Model) {
    return Dep.extend({
        templateContent:
            '<div class="journey-sms-test" style="margin-top:14px;padding-top:12px;border-top:1px solid #e3e8ee">' +
                '<div class="text-muted small" style="margin-bottom:8px">{{testSectionLabel}}</div>' +
                '<div class="form-group" style="margin-bottom:8px">' +
                    '<label class="control-label small" style="margin-bottom:2px">{{testTargetLabel}}</label>' +
                    '<div class="field journey-sms-test-target" data-name="testTarget"></div>' +
                '</div>' +
                '<button type="button" class="btn btn-default btn-sm journey-sms-render-btn"' +
                    '{{#if isRendering}} disabled{{/if}}>' +
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
                '<div class="journey-sms-render-result" style="margin-top:10px">' +
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
                    '{{#if personError}}' +
                    '<div class="text-danger small"><span class="fas fa-exclamation-triangle"></span> {{personError}}</div>' +
                    '{{else}}' +
                    '<div class="text-muted small" style="margin-bottom:4px">{{previewLabel}}</div>' +
                    '<pre class="small" style="margin:0;padding:10px;background:#f7f8fa;border-radius:6px;white-space:pre-wrap;max-width:560px;max-height:280px;overflow:auto">{{renderedBody}}</pre>' +
                    '<div class="text-muted small" style="margin-top:6px">' +
                        '{{length}} {{lengthLabel}} · {{phonesLabel}}: {{phones}}' +
                    '</div>' +
                    '{{/if}}' +
                '</div>' +
                '{{/if}}' +
            '</div>',

        isRendering: false,
        renderError: null,
        renderResult: null,
        previewIndex: 0,
        testScopes: ["Contact"],
        journeyEntityType: null,
        testTenantId: null,
        _testModel: null,

        label: function (key, category) {
            return this.translate(key, category || "labels", "JourneyStageAction");
        },

        data: function () {
            const data = {
                testSectionLabel: this.label("emailTemplateTestSection"),
                testTargetLabel: this.label("emailTemplateTestTarget"),
                testButtonLabel: this.label("emailTemplateTestRender"),
                renderingLabel: this.label("emailTemplateRendering"),
                previewLabel: this.label("whatsappTemplatePreview"),
                peopleLabel: this.label("previewPeople"),
                phonesLabel: this.label("smsPreviewPhones"),
                lengthLabel: this.label("smsPreviewLength"),
                isRendering: !!this.isRendering,
                renderError: this.renderError,
                hasRenderResult: !!this.renderResult,
            };

            const result = this.renderResult;

            if (result) {
                const people = result.people || [];
                const current = people[this.previewIndex] || {};

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
                data.length = current.length || 0;
                data.phones = (current.phones || []).join(", ");
            }

            return data;
        },

        setup: function () {
            this._testModel = new Model();
            this._testModel.name = "JourneySmsTest";
            this._testModel.set({
                testTargetType: this.testScopes[0],
                testTargetId: null,
                testTargetName: null,
            });

            const resolve = this.options.resolveJourneyContext;

            if (resolve) {
                resolve().then((ctx) => {
                    const entityType = (ctx && ctx.entityType) || null;
                    this.testTenantId = (ctx && ctx.tenantId) || null;

                    if (!entityType || !this.getMetadata().get(["scopes", entityType, "entity"])) {
                        return;
                    }

                    // An Account journey can also be previewed from one of its contacts.
                    this.journeyEntityType = entityType;
                    this.testScopes = entityType === "Account" ? ["Account", "Contact"] : [entityType];
                    this.renderResult = null;
                    this._testModel.set({testTargetType: this.testScopes[0], testTargetId: null, testTargetName: null});

                    if (this.isRendered()) {
                        this.reRender();
                    }
                }).catch(() => {});
            }
        },

        afterRender: function () {
            if (this.getView("testTarget")) {
                this.clearView("testTarget");
            }

            this.createView(
                "testTarget",
                "views/fields/link-parent",
                {
                    model: this._testModel,
                    name: "testTarget",
                    foreignScopeList: this.testScopes,
                    el: this.getSelector() + " .journey-sms-test-target",
                    mode: "edit",
                    defs: {
                        name: "testTarget",
                        type: "linkParent",
                    },
                    params: {},
                },
                (view) => {
                    view.render();
                }
            );

            this.$el
                .find(".journey-sms-render-btn")
                .off("click.smsRender")
                .on("click.smsRender", (e) => {
                    e.preventDefault();
                    this.runRenderTest();
                });

            this.$el
                .find(".journey-preview-person")
                .off("click.smsPerson")
                .on("click.smsPerson", (e) => {
                    e.preventDefault();
                    this.previewIndex = parseInt($(e.currentTarget).attr("data-index"), 10) || 0;
                    this.reRender();
                });
        },

        fail: function (message) {
            this.isRendering = false;
            this.renderResult = null;
            this.renderError = message;
            this.reRender();
        },

        runRenderTest: function () {
            const state = this.options.getBody ? this.options.getBody() : null;

            if (state && state.mode === "expression") {
                return this.fail(this.label("smsPreviewFormula", "messages"));
            }

            const body = String((state && state.fixed) || "").trim();

            if (!body) {
                return this.fail(this.label("smsPreviewNoBody", "messages"));
            }

            const targetId = this._testModel.get("testTargetId");

            if (!targetId) {
                return this.fail(this.label("emailTemplatePickTarget", "messages"));
            }

            this.isRendering = true;
            this.renderError = null;
            this.reRender();

            Espo.Ajax.postRequest("JourneyStageAction/action/renderSms", {
                body: body,
                targetEntityType: this._testModel.get("testTargetType") || this.testScopes[0],
                journeyEntityType: this.journeyEntityType,
                tenantId: this.testTenantId,
                targetId: targetId,
            })
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
                    this.fail(this.previewErrorMessage(xhr, this.label("smsPreviewFailed", "messages")));
                });
        },

        /**
         * Server message for a failed preview: JSON body, then the status-reason
         * header, then the generic label.
         */
        previewErrorMessage: function (xhr, fallback) {
            if (!xhr) {
                return fallback;
            }

            xhr.errorIsHandled = true;

            let data = xhr.responseJSON || null;

            if (!data && xhr.responseText && xhr.responseText[0] === "{") {
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (e) {}
            }

            if (data && data.message) {
                return data.message;
            }

            return (xhr.getResponseHeader && xhr.getResponseHeader("X-Status-Reason")) || fallback;
        },
    });
});
