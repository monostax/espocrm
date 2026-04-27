define("feature-oauth-enhanced:views/o-auth-provider/record/detail", ["views/record/detail"], function (
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
                        [{ name: "authorizationRedirectUri" }, false],
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
                {
                    tabBreak: true,
                    tabLabel: "[Admin]",
                    label: "[Admin]",
                    name: "admin",
                    rows: [
                        [{ name: "isGloballyShared" }, false],
                    ],
                },
            ];

            Dep.prototype.setup.call(this);
        },
    });
});
