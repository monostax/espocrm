define('global:views/user/fields/user-name', ['views/user/fields/user-name'], function (Dep) {
    return class extends Dep {
        setup() {
            super.setup();

            this.listenTo(this.model, 'change:emailAddress change:type', () => this.syncEmail());
            this.listenTo(this.model, 'change:emailAddressData', () => this.syncEmail(true));
            this.syncEmail();
        }

        syncEmail(fromData = false) {
            const human = !['api', 'system'].includes(this.model.get('type'));
            if (!human) {
                this.setNotReadOnly();
                return;
            }

            if (!this.readOnly) {
                this.setReadOnly();
            }

            const data = this.model.get('emailAddressData');
            const primary = Array.isArray(data) ? (data.find(row => row.primary) || data[0]) : null;
            const email = fromData ? (primary?.emailAddress ?? '') : (this.model.get('emailAddress') ?? '');
            this.model.set('userName', email.trim().toLowerCase());
        }

        validateUserName() {
            if (['api', 'system'].includes(this.model.get('type'))) {
                return super.validateUserName();
            }
            // Email syntax is validated by the email field and by the server.
        }
    };
});
