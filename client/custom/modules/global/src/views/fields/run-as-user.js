/************************************************************************
 * Monostax – runAsUser picker ACL:
 * - Espo admin: any active user
 * - tenant-admin: users in their tenants (onlyInMyTenants)
 * - regular tenant user: self only (onlyMe)
 ***********************************************************************/

import UserFieldView from 'views/fields/user';

class RunAsUserFieldView extends UserFieldView {

    getSelectPrimaryFilterName() {
        return 'active';
    }

    /**
     * @return {string[]|null}
     */
    getSelectBoolFilterList() {
        if (this.getUser().isAdmin()) {
            return null;
        }

        if (this.isTenantAdmin()) {
            return ['onlyInMyTenants'];
        }

        return ['onlyMe'];
    }

    /**
     * Prefer self when autocomplete is empty (non-admin).
     *
     * @return {Promise<Array<{id: string, name: string}>>|undefined}
     */
    getOnEmptyAutocomplete() {
        if (this.getUser().isAdmin()) {
            return undefined;
        }

        return Promise.resolve([
            {
                id: this.getUser().id,
                name: this.getUser().get('name'),
            },
        ]);
    }

    /**
     * @return {boolean}
     */
    isTenantAdmin() {
        const params = this.getHelper().getAppParam('isTenantAdmin');

        return params === true;
    }
}

export default RunAsUserFieldView;
