define("feature-oauth-enhanced:views/o-auth-provider/record/edit", ["views/record/edit"], function (
    Dep,
) {
    return Dep.extend({
        setup: function () {
            this.detailLayout = [
                {
                    rows: [
                        [{ name: "name" }, { name: "isActive" }],
                        [{ name: "provider" }, false],
                        [{ name: "clientId" }, { name: "clientSecret" }],
                        [{ name: "isGloballyShared" }, { name: "authorizationRedirectUri" }],
                    ],
                },
                {
                    rows: [
                        [{ name: "authorizationEndpoint" }, { name: "tokenEndpoint" }],
                    ],
                },
                {
                    rows: [
                        [{ name: "scopes" }],
                        [{ name: "scopeSeparator" }, false],
                        [{ name: "authorizationParams" }, { name: "authorizationPrompt" }],
                    ],
                },
                {
                    rows: [[{ name: "description" }]],
                },
            ];

            Dep.prototype.setup.call(this);
        },
    });
});
