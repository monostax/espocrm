define("feature-credential:views/credential-type/record/detail", [
    "views/record/detail",
], function (Dep) {
    return Dep.extend({
        setup: function () {
            Dep.prototype.setup.call(this);
        },
    });
});
