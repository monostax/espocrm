define("global:views/credential/record/list", ["views/record/list"], function (
    Dep,
) {
    return Dep.extend({
        setup: function () {
            Dep.prototype.setup.call(this);
        },
    });
});

