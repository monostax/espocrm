define("feature-credential:views/credential-type/fields/schema", [
    "feature-credential:views/credential/fields/metadata",
], function (Dep) {
    return Dep.extend({
        fetch: function () {
            var value = null;

            if (!this.editor) {
                var currentValue = this.model.get(this.name);
                var currentObj = {};
                currentObj[this.name] = currentValue || null;

                return currentObj;
            }

            var raw = this.editor.getValue();

            if (!raw || !raw.trim()) {
                var emptyObj = {};
                emptyObj[this.name] = null;

                return emptyObj;
            }

            try {
                value = JSON.stringify(JSON.parse(raw), null, 2);
            } catch (e) {
                value = raw;
            }

            var obj = {};
            obj[this.name] = value;

            return obj;
        },
    });
});
