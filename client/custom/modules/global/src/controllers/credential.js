define("global:controllers/credential", ["controllers/record"], function (
    Dep,
) {
    return Dep.extend({
        entityType: "Credential",
    });
});
