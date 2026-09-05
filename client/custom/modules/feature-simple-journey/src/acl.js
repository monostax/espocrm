define('feature-simple-journey:acl', ['acl'], function (Acl) {
    return Acl.extend({
        checkModel: function (model, data, action, precise) {
            if (this.getUser().isAdmin() || model.isNew()) {
                return this.checkScope(data, action, precise);
            }

            if (data === false || data == null) {
                return false;
            }

            // The server checks both scope permissions and the live journey/source record.
            // Guessing ownership from createdBy/assignedUser misses inherited teams.
            const allowed = model.get('simpleJourneyAccess')?.[action || 'read'];

            return typeof allowed === 'boolean' ? allowed : (precise ? null : false);
        },

        checkModelDelete: function (model, data, precise) {
            // Do not let the default creator fallback override a server-side denial.
            return this.checkModel(model, data, 'delete', precise);
        },
    });
});
