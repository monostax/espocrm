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
 * A/B/n test wizard modal for WhatsAppCampaign.
 *
 * Lets the operator split the campaign's audience between the original
 * campaign (variant A) and N template variants. On save, the backend clones
 * the campaign per variant, moves the audience onto a new
 * WhatsAppCampaignDistribution with weighted entries, and optionally
 * activates it.
 */
define(
    "chatwoot:views/whatsapp-campaign/modals/create-ab-test",
    ["views/modal", "model"],
    function (Dep, Model) {
        return Dep.extend({
            className: "dialog dialog-record",

            templateContent:
                '<p class="text-muted small">{{hintText}}</p>' +
                '<div class="record-main no-side-margin"></div>' +
                '<div class="variants-list">' +
                "{{#each variants}}" +
                '<div class="variant-block" data-key="{{key}}"' +
                ' style="border-top: 1px solid var(--border-color, #ededed);' +
                ' padding-top: 10px; margin-top: 10px;">' +
                '<div class="clearfix" style="margin-bottom: 4px;">' +
                "{{#if ../canRemove}}" +
                '<span class="pull-right">' +
                '<a role="button" data-action="removeVariant" data-key="{{key}}"' +
                ' class="text-danger small">{{../removeLabel}}</a>' +
                "</span>" +
                "{{/if}}" +
                '<span class="text-muted small">{{label}}</span>' +
                "</div>" +
                '<div class="record no-side-margin variant-record" data-key="{{key}}"></div>' +
                "</div>" +
                "{{/each}}" +
                "</div>" +
                '<div style="margin-top: 12px;">' +
                '<button type="button" class="btn btn-default btn-sm" data-action="addVariant">' +
                '<span class="fas fa-plus"></span> {{addVariantLabel}}' +
                "</button>" +
                "</div>",

            events: {
                'click [data-action="addVariant"]': function () {
                    this.addVariant();
                },
                'click [data-action="removeVariant"]': function (e) {
                    this.removeVariant(e.currentTarget.getAttribute("data-key"));
                },
            },

            data: function () {
                return {
                    hintText: this.translateScoped("abTestHint"),
                    addVariantLabel: this.translateScoped("Add Variant", "labels"),
                    removeLabel: this.translate("Remove"),
                    canRemove: this.variantKeys.length > 1,
                    variants: this.variantKeys.map((key) => ({
                        key: key,
                        label:
                            this.translateScoped("Variant", "labels") +
                            " " +
                            this.variantLetters[key],
                    })),
                };
            },

            translateScoped: function (key, category = "messages") {
                return this.translate(key, category, "WhatsAppCampaign");
            },

            setup: function () {
                Dep.prototype.setup.call(this);

                this.sourceModel = this.options.model;

                this.headerText =
                    this.translateScoped("Create A/B Test", "labels") +
                    " · " +
                    (this.sourceModel.get("name") || "");

                this.buttonList = [
                    {
                        name: "create",
                        label: this.translateScoped("Create A/B Test", "labels"),
                        style: "primary",
                    },
                    {
                        name: "cancel",
                        label: "Cancel",
                    },
                ];

                this.variantKeys = [];
                this.variantModels = {};
                this.variantLetters = {};
                this.variantSeq = 0;

                const mainModel = (this.mainModel = new Model());

                mainModel.name = "WhatsAppCampaignAbTestMain";

                mainModel.setDefs({
                    fields: {
                        weight: {
                            type: "int",
                            required: true,
                            min: 1,
                            max: 99,
                        },
                        activate: {
                            type: "bool",
                        },
                    },
                });

                mainModel.set({
                    weight: 50,
                    activate: true,
                });

                this.createView("recordMain", "views/record/edit-for-modal", {
                    model: mainModel,
                    selector: ".record-main",
                    detailLayout: [
                        {
                            rows: [
                                [
                                    {
                                        name: "weight",
                                        labelText: this.translateScoped(
                                            "abWeightOriginal",
                                            "fields",
                                        ),
                                    },
                                    {
                                        name: "activate",
                                        labelText: this.translateScoped(
                                            "abActivate",
                                            "fields",
                                        ),
                                    },
                                ],
                            ],
                        },
                    ],
                });

                this.addVariant();
            },

            addVariant: function () {
                const seq = this.variantSeq++;
                const key = "variantRecord" + seq;
                const letter = String.fromCharCode(66 + seq); // B, C, D...

                const model = new Model();

                model.name = "WhatsAppCampaignAbTestVariant";

                model.setDefs({
                    fields: {
                        name: {
                            type: "varchar",
                            required: true,
                            maxLength: 255,
                        },
                        weight: {
                            type: "int",
                            required: true,
                            min: 1,
                            max: 99,
                        },
                        templateName: {
                            type: "varchar",
                            required: true,
                            view: "chatwoot:views/whatsapp-campaign/fields/template-name",
                        },
                    },
                });

                model.set(
                    {
                        name:
                            (this.sourceModel.get("name") || "") +
                            " (" +
                            letter +
                            ")",
                        chatwootInboxId: this.sourceModel.get("chatwootInboxId"),
                        chatwootAccountId: this.sourceModel.get("chatwootAccountId"),
                        credentialId: this.sourceModel.get("credentialId"),
                        wabaId: this.sourceModel.get("wabaId"),
                    },
                    { silent: true },
                );

                this.variantKeys.push(key);
                this.variantModels[key] = model;
                this.variantLetters[key] = letter;

                this.createView(key, "views/record/edit-for-modal", {
                    model: model,
                    selector: '.variant-record[data-key="' + key + '"]',
                    detailLayout: [
                        {
                            rows: [
                                [
                                    {
                                        name: "name",
                                        labelText: this.translateScoped(
                                            "name",
                                            "fields",
                                        ),
                                    },
                                    {
                                        name: "weight",
                                        labelText: this.translateScoped(
                                            "abWeight",
                                            "fields",
                                        ),
                                    },
                                ],
                                [
                                    {
                                        name: "templateName",
                                        labelText: this.translateScoped(
                                            "templateName",
                                            "fields",
                                        ),
                                    },
                                    false,
                                ],
                            ],
                        },
                    ],
                });

                this.rebalanceWeights();

                if (this.isRendered()) {
                    this.reRender();
                }
            },

            removeVariant: function (key) {
                if (this.variantKeys.length <= 1) {
                    return;
                }

                const index = this.variantKeys.indexOf(key);

                if (index === -1) {
                    return;
                }

                this.clearView(key);
                this.variantKeys.splice(index, 1);
                delete this.variantModels[key];
                delete this.variantLetters[key];

                this.rebalanceWeights();
                this.reRender();
            },

            /**
             * Even split across all arms (original + variants); the first
             * arms absorb the remainder so the sum is always exactly 100.
             */
            rebalanceWeights: function () {
                const arms = 1 + this.variantKeys.length;
                const base = Math.floor(100 / arms);
                let remainder = 100 - base * arms;

                const take = () => base + (remainder-- > 0 ? 1 : 0);

                this.mainModel.set("weight", take());

                this.variantKeys.forEach((key) => {
                    this.variantModels[key].set("weight", take());
                });
            },

            afterRender: function () {
                Dep.prototype.afterRender.call(this);

                const mainView = this.getView("recordMain");

                if (mainView) {
                    mainView.render();
                }

                this.variantKeys.forEach((key) => {
                    const view = this.getView(key);

                    if (view) {
                        view.render();
                    }
                });
            },

            actionCreate: function () {
                const mainView = this.getView("recordMain");
                const variantViews = this.variantKeys.map((key) => this.getView(key));

                let invalid = mainView.validate();

                variantViews.forEach((view) => {
                    invalid = view.validate() || invalid;
                });

                if (invalid) {
                    return;
                }

                mainView.processFetch();
                variantViews.forEach((view) => view.processFetch());

                const weights = [
                    parseInt(this.mainModel.get("weight"), 10) || 0,
                    ...this.variantKeys.map(
                        (key) =>
                            parseInt(this.variantModels[key].get("weight"), 10) || 0,
                    ),
                ];

                const sum = weights.reduce((a, b) => a + b, 0);

                if (sum !== 100) {
                    Espo.Ui.error(
                        this.translateScoped("weightsMustSumTo100").replace(
                            "{sum}",
                            String(sum),
                        ),
                    );

                    return;
                }

                const payload = {
                    weight: weights[0],
                    activate: !!this.mainModel.get("activate"),
                    variants: this.variantKeys.map((key) => {
                        const model = this.variantModels[key];

                        return {
                            name: model.get("name"),
                            weight: parseInt(model.get("weight"), 10),
                            templateName: model.get("templateName"),
                            templateLanguage: model.get("templateLanguage"),
                            templateCategory: model.get("templateCategory"),
                            templateBody: model.get("templateBody"),
                            parameterMapping: model.get("parameterMapping"),
                            headerMediaUrl: model.get("headerMediaUrl"),
                            headerMediaType: model.get("headerMediaType"),
                        };
                    }),
                };

                this.disableButton("create");

                Espo.Ui.notify(this.translateScoped("creatingAbTest"));

                Espo.Ajax.postRequest(
                    "WhatsAppCampaign/" + this.sourceModel.id + "/createAbTest",
                    payload,
                )
                    .then((response) => {
                        Espo.Ui.notify(false);

                        if (response.activationError) {
                            Espo.Ui.warning(
                                this.translateScoped(
                                    "abTestCreatedActivationFailed",
                                ).replace("{error}", response.activationError),
                            );
                        } else {
                            Espo.Ui.success(this.translateScoped("abTestCreated"));
                        }

                        this.trigger("done", response);
                        this.close();
                    })
                    .catch((xhr) => {
                        this.enableButton("create");

                        let errorMsg = this.translateScoped("failedToCreateAbTest");

                        if (xhr?.responseJSON?.message) {
                            errorMsg = xhr.responseJSON.message;
                        }

                        Espo.Ui.error(errorMsg);
                    });
            },
        });
    },
);
