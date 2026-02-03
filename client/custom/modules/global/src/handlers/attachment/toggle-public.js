/************************************************************************
 * Toggle Public Handler for Attachment
 *
 * Provides actions to mark attachments as public or private.
 ************************************************************************/

class TogglePublicHandler {

    /**
     * @param {import('views/record/detail').default} view
     */
    constructor(view) {
        this.view = view;
        this.model = view.model;
    }

    /**
     * Check if "Make Public" action should be visible.
     * @returns {boolean}
     */
    isPublicActionAvailable() {
        return !this.model.get('isPublic');
    }

    /**
     * Check if "Make Private" action should be visible.
     * @returns {boolean}
     */
    isPrivateActionAvailable() {
        return !!this.model.get('isPublic');
    }

    /**
     * Make the attachment public.
     */
    makePublic() {
        this._togglePublic(true);
    }

    /**
     * Make the attachment private.
     */
    makePrivate() {
        this._togglePublic(false);
    }

    /**
     * @param {boolean} isPublic
     * @private
     */
    _togglePublic(isPublic) {
        Espo.Ui.notify(this.view.translate('saving', 'messages'));

        this.model
            .save({isPublic: isPublic}, {patch: true})
            .then(() => {
                Espo.Ui.success(this.view.translate('Done'));

                // Refresh the view to update button visibility
                this.view.model.fetch();
            })
            .catch(() => {
                Espo.Ui.error(this.view.translate('Error'));
            });
    }
}

// noinspection JSUnusedGlobalSymbols
export default TogglePublicHandler;
