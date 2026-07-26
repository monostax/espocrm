define("feature-credential:views/credential/fields/agent-egress", [
    "views/fields/base",
], function (Dep) {
    /**
     * Structured editor for Credential / OAuthAccount.agentEgress.
     * Stores: { enabled, placeholderMode, secrets:[{ envName, configPath, hosts, replaceInQuery }] }
     * Multi-env: one secrets[] row per guest env var (e.g. username + password → 2 rows).
     */
    return Dep.extend({

        validations: ["agentEgress"],

        // language=Handlebars
        detailTemplateContent:
            '{{#unless isConfigured}}' +
                '<span class="none-value">{{translate "None"}}</span>' +
            '{{else}}' +
                '<div class="agent-egress-detail">' +
                    '<div class="agent-egress-status" style="margin-bottom:10px;">' +
                        '{{#if enabled}}' +
                            '<span class="label label-success">{{labelEnabled}}</span>' +
                        '{{else}}' +
                            '<span class="label label-default">{{labelDisabled}}</span>' +
                        '{{/if}}' +
                        ' <span class="text-muted" style="margin-left:8px;">' +
                            '{{labelPlaceholderMode}}: ' +
                            '<strong>{{placeholderModeLabel}}</strong>' +
                        '</span>' +
                    '</div>' +
                    '{{#if hasSecrets}}' +
                    '<table class="table table-bordered" style="margin-bottom:0;">' +
                        '<thead><tr>' +
                            '<th>{{labelEnvName}}</th>' +
                            '<th>{{labelConfigPath}}</th>' +
                            '<th>{{labelHosts}}</th>' +
                            '<th>{{labelReplaceInQuery}}</th>' +
                        '</tr></thead>' +
                        '<tbody>' +
                        '{{#each secrets}}' +
                        '<tr>' +
                            '<td><code>{{envName}}</code></td>' +
                            '<td><code>{{configPath}}</code></td>' +
                            '<td>{{#each hosts}}<span class="label label-default" style="margin-right:4px;">{{this}}</span>{{/each}}</td>' +
                            '<td>{{#if replaceInQuery}}{{translate "Yes"}}{{else}}{{translate "No"}}{{/if}}</td>' +
                        '</tr>' +
                        '{{/each}}' +
                        '</tbody>' +
                    '</table>' +
                    '{{else}}' +
                    '<span class="text-muted">{{labelNoSecrets}}</span>' +
                    '{{/if}}' +
                '</div>' +
            '{{/unless}}',

        // language=Handlebars
        editTemplateContent:
            '<div class="agent-egress-edit">' +
                '<p class="text-muted" style="margin-bottom:12px;">{{msgHelp}}</p>' +
                '<div class="form-group">' +
                    '<div class="checkbox">' +
                        '<label>' +
                            '<input type="checkbox" data-name="enabled" class="agent-egress-enabled">' +
                            ' {{labelEnabled}}' +
                        '</label>' +
                    '</div>' +
                '</div>' +
                '<div class="form-group agent-egress-options">' +
                    '<label class="control-label">{{labelPlaceholderMode}}</label>' +
                    '<select class="form-control agent-egress-placeholder-mode" data-name="placeholderMode" style="max-width:280px;">' +
                        '<option value="shared">{{labelModeShared}}</option>' +
                        '<option value="unique">{{labelModeUnique}}</option>' +
                    '</select>' +
                    '<div class="help-block text-muted" style="margin-top:4px;">{{msgPlaceholderModeHelp}}</div>' +
                '</div>' +
                '<div class="form-group agent-egress-secrets-wrap">' +
                    '<label class="control-label">{{labelSecrets}}</label>' +
                    '<div class="agent-egress-secrets"></div>' +
                    '<button type="button" class="btn btn-default btn-sm agent-egress-add-secret" style="margin-top:8px;">' +
                        '<span class="fas fa-plus"></span> {{labelAddSecret}}' +
                    '</button>' +
                '</div>' +
            '</div>',

        // language=Handlebars
        listTemplateContent:
            '{{#if isConfigured}}' +
                '{{#if enabled}}' +
                    '<span class="label label-success" title="{{summary}}">{{count}} {{labelSecretUnit}}</span>' +
                '{{else}}' +
                    '<span class="label label-default" title="{{summary}}">{{labelDisabled}}</span>' +
                '{{/if}}' +
            '{{else}}' +
                '<span class="none-value">{{translate "None"}}</span>' +
            '{{/if}}',

        setup: function () {
            Dep.prototype.setup.call(this);

            this.listenTo(this.model, "change:" + this.name, function () {
                if (this.isRendered() && !this.isEditMode()) {
                    this.reRender();
                }
            }.bind(this));
        },

        /**
         * i18n scope follows the host entity (Credential or OAuthAccount).
         * @returns {string}
         */
        getI18nScope: function () {
            return this.model.entityType || this.entityType || "Credential";
        },

        /**
         * Translate agentEgress_* keys from entity scope with Credential fallback.
         */
        tAgent: function (name, category) {
            category = category || "labels";
            var scope = this.getI18nScope();
            var translated = this.translate(name, category, scope);

            if (
                !translated ||
                translated === name ||
                translated.indexOf(category + ".") === 0
            ) {
                translated = this.translate(name, category, "Credential");
            }

            return translated;
        },

        /**
         * @returns {{enabled: boolean, placeholderMode: string, secrets: Array}}
         */
        getValueObject: function () {
            var raw = this.model.get(this.name);

            if (!raw) {
                return {enabled: false, placeholderMode: "shared", secrets: []};
            }

            if (typeof raw === "string") {
                try {
                    raw = JSON.parse(raw);
                } catch (e) {
                    return {enabled: false, placeholderMode: "shared", secrets: []};
                }
            }

            if (typeof raw !== "object" || raw === null || Array.isArray(raw)) {
                return {enabled: false, placeholderMode: "shared", secrets: []};
            }

            var secrets = Array.isArray(raw.secrets) ? raw.secrets : [];
            secrets = secrets.map(function (s) {
                return {
                    envName: s && s.envName ? String(s.envName) : "",
                    configPath: s && s.configPath ? String(s.configPath) : "",
                    hosts: Array.isArray(s && s.hosts)
                        ? s.hosts.map(function (h) { return String(h); }).filter(Boolean)
                        : (typeof (s && s.hosts) === "string" && s.hosts
                            ? s.hosts.split(/[\s,]+/).map(function (h) { return h.trim(); }).filter(Boolean)
                            : []),
                    replaceInQuery: !!(s && s.replaceInQuery),
                };
            });

            return {
                enabled: typeof raw.enabled === "boolean"
                    ? raw.enabled
                    : secrets.length > 0,
                placeholderMode: raw.placeholderMode === "unique" ? "unique" : "shared",
                secrets: secrets,
            };
        },

        data: function () {
            var data = Dep.prototype.data.call(this);
            var value = this.getValueObject();
            var isConfigured = value.secrets.length > 0 || value.enabled;

            data.isConfigured = isConfigured;
            data.enabled = !!value.enabled;
            data.placeholderMode = value.placeholderMode;
            data.placeholderModeLabel = value.placeholderMode === "unique"
                ? this.tAgent("agentEgressModeUnique")
                : this.tAgent("agentEgressModeShared");
            data.secrets = value.secrets;
            data.hasSecrets = value.secrets.length > 0;
            data.count = value.secrets.length;
            data.summary = value.secrets.map(function (s) {
                return s.envName + " → " + (s.hosts || []).join(",");
            }).join("; ");

            data.labelEnabled = this.tAgent("agentEgressEnabled");
            data.labelDisabled = this.tAgent("agentEgressDisabled");
            data.labelPlaceholderMode = this.tAgent("agentEgressPlaceholderMode");
            data.labelModeShared = this.tAgent("agentEgressModeShared");
            data.labelModeUnique = this.tAgent("agentEgressModeUnique");
            data.labelSecrets = this.tAgent("agentEgressSecrets");
            data.labelAddSecret = this.tAgent("agentEgressAddSecret");
            data.labelEnvName = this.tAgent("agentEgressEnvName");
            data.labelConfigPath = this.tAgent("agentEgressConfigPath");
            data.labelHosts = this.tAgent("agentEgressHosts");
            data.labelReplaceInQuery = this.tAgent("agentEgressReplaceInQuery");
            data.labelNoSecrets = this.tAgent("agentEgressNoSecrets");
            data.labelSecretUnit = this.tAgent("agentEgressSecretUnit");
            data.msgHelp = this.tAgent("agentEgressHelp", "messages");
            data.msgPlaceholderModeHelp = this.tAgent("agentEgressPlaceholderModeHelp", "messages");

            return data;
        },

        afterRender: function () {
            if (this.isEditMode()) {
                this.afterRenderEdit();
            }
        },

        afterRenderEdit: function () {
            var value = this.getValueObject();

            this.$el.find(".agent-egress-enabled").prop("checked", !!value.enabled);
            this.$el.find(".agent-egress-placeholder-mode").val(value.placeholderMode || "shared");

            var $list = this.$el.find(".agent-egress-secrets");
            $list.empty();

            if (value.secrets.length === 0) {
                this.addSecretRow($list, {
                    envName: "",
                    configPath: "",
                    hosts: [],
                    replaceInQuery: false,
                });
            } else {
                value.secrets.forEach(function (secret) {
                    this.addSecretRow($list, secret);
                }.bind(this));
            }

            this.$el.find(".agent-egress-add-secret").off("click").on("click", function (e) {
                e.preventDefault();
                this.addSecretRow(this.$el.find(".agent-egress-secrets"), {
                    envName: "",
                    configPath: "",
                    hosts: [],
                    replaceInQuery: false,
                });
                this.trigger("change", {ui: true});
            }.bind(this));

            this.$el.find(".agent-egress-enabled, .agent-egress-placeholder-mode")
                .off("change.agentEgress")
                .on("change.agentEgress", function () {
                    this.trigger("change", {ui: true});
                }.bind(this));

            this.toggleEnabledUi();
            this.$el.find(".agent-egress-enabled").on("change", this.toggleEnabledUi.bind(this));
        },

        toggleEnabledUi: function () {
            var enabled = this.$el.find(".agent-egress-enabled").is(":checked");
            this.$el.find(".agent-egress-options, .agent-egress-secrets-wrap")
                .css("opacity", enabled ? 1 : 0.55);
        },

        addSecretRow: function ($list, secret) {
            var hostsStr = Array.isArray(secret.hosts) ? secret.hosts.join(", ") : "";
            var pathPlaceholder = this.getI18nScope() === "OAuthAccount"
                ? "accessToken"
                : "username";
            var envPlaceholder = this.getI18nScope() === "OAuthAccount"
                ? "ACCESS_TOKEN"
                : "API_USERNAME";
            var $row = $("<div>")
                .addClass("agent-egress-secret-row panel panel-default")
                .css({padding: "12px", marginBottom: "10px", position: "relative"});

            $row.append(
                $("<button>")
                    .attr("type", "button")
                    .addClass("btn btn-link btn-sm text-danger agent-egress-remove-secret")
                    .css({position: "absolute", top: "6px", right: "6px"})
                    .html('<span class="fas fa-times"></span>')
                    .attr("title", this.translate("Remove"))
            );

            $row.append(this.buildFieldGroup(
                "envName",
                this.tAgent("agentEgressEnvName"),
                $("<input>")
                    .attr("type", "text")
                    .addClass("form-control")
                    .attr("data-field", "envName")
                    .attr("placeholder", envPlaceholder)
                    .attr("autocomplete", "off")
                    .val(secret.envName || "")
            ));

            $row.append(this.buildFieldGroup(
                "configPath",
                this.tAgent("agentEgressConfigPath"),
                $("<input>")
                    .attr("type", "text")
                    .addClass("form-control")
                    .attr("data-field", "configPath")
                    .attr("placeholder", pathPlaceholder)
                    .attr("autocomplete", "off")
                    .val(secret.configPath || "")
            ).append(
                $("<div>")
                    .addClass("help-block text-muted")
                    .css("margin-bottom", 0)
                    .text(this.tAgent("agentEgressConfigPathHelp", "messages"))
            ));

            $row.append(this.buildFieldGroup(
                "hosts",
                this.tAgent("agentEgressHosts"),
                $("<input>")
                    .attr("type", "text")
                    .addClass("form-control")
                    .attr("data-field", "hosts")
                    .attr("placeholder", "api.example.com, *.example.com")
                    .attr("autocomplete", "off")
                    .val(hostsStr)
            ).append(
                $("<div>")
                    .addClass("help-block text-muted")
                    .css("margin-bottom", 0)
                    .text(this.tAgent("agentEgressHostsHelp", "messages"))
            ));

            var $queryCheck = $("<input>")
                .attr("type", "checkbox")
                .attr("data-field", "replaceInQuery");
            if (secret.replaceInQuery) {
                $queryCheck.prop("checked", true);
            }

            $row.append(
                $("<div>")
                    .addClass("checkbox")
                    .append(
                        $("<label>")
                            .append($queryCheck)
                            .append(" " + this.tAgent("agentEgressReplaceInQuery"))
                    )
                    .append(
                        $("<div>")
                            .addClass("help-block text-muted")
                            .text(this.tAgent("agentEgressReplaceInQueryHelp", "messages"))
                    )
            );

            $row.find("input, select, textarea").on("change input", function () {
                this.trigger("change", {ui: true});
            }.bind(this));

            $row.find(".agent-egress-remove-secret").on("click", function (e) {
                e.preventDefault();
                $row.remove();
                if (this.$el.find(".agent-egress-secret-row").length === 0) {
                    this.addSecretRow(this.$el.find(".agent-egress-secrets"), {
                        envName: "",
                        configPath: "",
                        hosts: [],
                        replaceInQuery: false,
                    });
                }
                this.trigger("change", {ui: true});
            }.bind(this));

            $list.append($row);
        },

        buildFieldGroup: function (name, label, $input) {
            return $("<div>")
                .addClass("form-group")
                .attr("data-secret-field", name)
                .append($("<label>").addClass("control-label").text(label))
                .append($input);
        },

        /**
         * Read DOM into normalized object. Empty forms → null (clear field).
         */
        readFromDom: function () {
            var enabled = this.$el.find(".agent-egress-enabled").is(":checked");
            var placeholderMode = this.$el.find(".agent-egress-placeholder-mode").val() || "shared";
            if (placeholderMode !== "unique") {
                placeholderMode = "shared";
            }

            var secrets = [];
            this.$el.find(".agent-egress-secret-row").each(function (_i, el) {
                var $row = $(el);
                var envName = String($row.find('[data-field="envName"]').val() || "").trim();
                var configPath = String($row.find('[data-field="configPath"]').val() || "").trim();
                var hostsRaw = String($row.find('[data-field="hosts"]').val() || "").trim();
                var hosts = hostsRaw
                    ? hostsRaw.split(/[\s,]+/).map(function (h) { return h.trim(); }).filter(Boolean)
                    : [];
                var replaceInQuery = $row.find('[data-field="replaceInQuery"]').is(":checked");

                if (!envName && !configPath && hosts.length === 0) {
                    return;
                }

                secrets.push({
                    envName: envName,
                    configPath: configPath,
                    hosts: hosts,
                    replaceInQuery: replaceInQuery,
                });
            });

            if (!enabled && secrets.length === 0) {
                return null;
            }

            return {
                enabled: enabled,
                placeholderMode: placeholderMode,
                secrets: secrets,
            };
        },

        fetch: function () {
            var data = {};
            data[this.name] = this.readFromDom();
            return data;
        },

        /**
         * @returns {boolean} true if invalid
         */
        validateAgentEgress: function () {
            if (!this.isEditMode()) {
                return false;
            }

            var value = this.readFromDom();
            if (!value) {
                return false;
            }

            if (!value.enabled) {
                return false;
            }

            var hasError = false;
            var envNameRe = /^[A-Z][A-Z0-9_]*$/;
            var seenEnv = {};
            var isOAuth = this.getI18nScope() === "OAuthAccount";

            if (!value.secrets.length) {
                this.showValidationMessage(
                    this.tAgent("agentEgressRequiresSecret", "messages"),
                    this.$el.find(".agent-egress-add-secret")
                );
                return true;
            }

            this.$el.find(".agent-egress-secret-row").each(function (_i, el) {
                var $row = $(el);
                var envName;
                var configPath;
                var hostsRaw;
                var hosts;
                var $env;
                var $envDup;
                var $path;
                var $hosts;
                var pathErrorKey;

                $row.find(".has-error").removeClass("has-error");

                envName = String($row.find('[data-field="envName"]').val() || "").trim();
                configPath = String($row.find('[data-field="configPath"]').val() || "").trim();
                hostsRaw = String($row.find('[data-field="hosts"]').val() || "").trim();
                hosts = hostsRaw
                    ? hostsRaw.split(/[\s,]+/).map(function (h) { return h.trim(); }).filter(Boolean)
                    : [];

                if (!envName && !configPath && hosts.length === 0) {
                    return;
                }

                if (!envName || !envNameRe.test(envName)) {
                    hasError = true;
                    $env = $row.find('[data-field="envName"]');
                    $env.closest(".form-group").addClass("has-error");
                    this.showValidationMessage(
                        this.tAgent("agentEgressInvalidEnvName", "messages"),
                        $env
                    );
                } else if (seenEnv[envName]) {
                    hasError = true;
                    $envDup = $row.find('[data-field="envName"]');
                    $envDup.closest(".form-group").addClass("has-error");
                    this.showValidationMessage(
                        this.tAgent("agentEgressDuplicateEnvName", "messages"),
                        $envDup
                    );
                } else {
                    seenEnv[envName] = true;
                }

                if (!configPath) {
                    hasError = true;
                    $path = $row.find('[data-field="configPath"]');
                    $path.closest(".form-group").addClass("has-error");
                    this.showValidationMessage(
                        this.tAgent("agentEgressConfigPathRequired", "messages"),
                        $path
                    );
                } else if (!this.isValidConfigPath(configPath, isOAuth)) {
                    hasError = true;
                    $path = $row.find('[data-field="configPath"]');
                    $path.closest(".form-group").addClass("has-error");
                    pathErrorKey = isOAuth
                        ? "agentEgressInvalidOAuthConfigPath"
                        : "agentEgressInvalidConfigPath";
                    this.showValidationMessage(
                        this.tAgent(pathErrorKey, "messages"),
                        $path
                    );
                }

                if (!hosts.length) {
                    hasError = true;
                    $hosts = $row.find('[data-field="hosts"]');
                    $hosts.closest(".form-group").addClass("has-error");
                    this.showValidationMessage(
                        this.tAgent("agentEgressHostsRequired", "messages"),
                        $hosts
                    );
                }
            }.bind(this));

            return hasError;
        },

        /**
         * @param {string} path
         * @param {boolean} isOAuth
         * @returns {boolean}
         */
        isValidConfigPath: function (path, isOAuth) {
            var segmentRe = /^[A-Za-z_][A-Za-z0-9_]*$/;
            var parts;
            var i;
            var oauthExact;

            if (!path || path.indexOf("..") !== -1 ||
                path.charAt(0) === "." || path.charAt(path.length - 1) === "."
            ) {
                return false;
            }

            parts = path.split(".");
            for (i = 0; i < parts.length; i++) {
                if (!segmentRe.test(parts[i])) {
                    return false;
                }
            }

            if (!isOAuth) {
                return true;
            }

            oauthExact = {
                accessToken: true,
                access_token: true,
                refreshToken: true,
                refresh_token: true,
            };

            if (oauthExact[path]) {
                return true;
            }

            return parts[0] === "data" && parts.length >= 2;
        },
    });
});
