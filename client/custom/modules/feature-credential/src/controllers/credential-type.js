define("feature-credential:controllers/credential-type", ["controllers/record"], function (
    Dep,
) {
    return Dep.extend({
        entityType: "CredentialType",
    });
});
