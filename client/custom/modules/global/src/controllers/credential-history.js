define("global:controllers/credential-history", [
    "controllers/record",
], function (Dep) {
    return Dep.extend({
        entityType: "CredentialHistory",
    });
});
