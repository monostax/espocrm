define('feature-automation:handlers/automation/detail-actions', ['action-handler'], function (Dep) {

    return Dep.extend({

        isActivateAvailable: function () {
            var s = this.view.model.get('status');
            return s === 'Draft' || s === 'Paused';
        },

        isPauseAvailable: function () {
            return this.view.model.get('status') === 'Active';
        },

        isRunNowAvailable: function () {
            var s = this.view.model.get('status');
            return s === 'Active' || s === 'Draft' || s === 'Paused';
        },

        isArchiveAvailable: function () {
            return this.view.model.get('status') !== 'Archived';
        },

        activate: function () {
            this._post('activate', 'confirmActivate', 'activated');
        },

        pause: function () {
            this._post('pause', 'confirmPause', 'paused');
        },

        archive: function () {
            this._post('archive', 'confirmArchive', 'archived');
        },

        _msg: function (key, vars) {
            var msg = this.view.translate(key, 'messages', 'Automation');
            Object.keys(vars || {}).forEach(function (k) {
                msg = msg.replace(new RegExp('\\{' + k + '\\}', 'g'), String(vars[k]));
            });
            return msg;
        },

        runNow: function () {
            var view = this.view;
            var self = this;
            var id = view.model.id;
            var kind = view.model.get('kind');
            var subjectType = view.model.get('subjectEntityType') || '';
            var entityId;
            var payload = {};

            Espo.Ui.confirm(
                view.translate('confirmRunNow', 'messages', 'Automation'),
                {
                    confirmText: view.translate('Yes'),
                    cancelText: view.translate('Cancel'),
                }
            ).then(function () {
                if (kind === 'Machine' && subjectType) {
                    entityId = window.prompt(
                        self._msg('machineSubjectIdPrompt', {entityType: subjectType}),
                        ''
                    );
                    if (!entityId) {
                        Espo.Ui.warning(view.translate('machineSubjectIdRequired', 'messages', 'Automation'));
                        return;
                    }
                    payload = {
                        triggerPayload: {
                            entityType: subjectType,
                            entityId: entityId,
                        },
                    };
                }

                Espo.Ajax.postRequest('Automation/' + id + '/runNow', payload)
                    .then(function () {
                        view.model.fetch();
                        Espo.Ui.success(view.translate('runStarted', 'messages', 'Automation'));
                    })
                    .catch(function () {});
            });
        },

        simulate: function () {
            var view = this.view;
            var self = this;
            var id = view.model.id;
            var kind = view.model.get('kind');
            var subjectType = view.model.get('subjectEntityType') || '';
            var payload = {};
            var entityId;

            if (kind === 'Machine' && subjectType) {
                entityId = window.prompt(
                    self._msg('simulateSubjectIdPrompt', {entityType: subjectType}),
                    ''
                );
                if (!entityId) {
                    Espo.Ui.warning(view.translate('simulateSubjectIdRequired', 'messages', 'Automation'));
                    return;
                }
                payload = {
                    triggerPayload: {
                        entityType: subjectType,
                        entityId: entityId,
                    },
                };
            }

            Espo.Ajax.postRequest('Automation/' + id + '/simulate', payload)
                .then(function (result) {
                    var text;
                    try {
                        text = JSON.stringify(result, null, 2);
                    } catch (e) {
                        text = String(result);
                    }
                    var count = (result && result.itemCount) != null ? result.itemCount : '?';
                    var suffix = view.translate('simulateItemsSuffix', 'messages', 'Automation');
                    Espo.Ui.success(
                        view.translate('simulateDone', 'messages', 'Automation') +
                        ' (' + count + ' ' + suffix + ')'
                    );
                    console.info('[Automation simulate]', result);
                    window.alert(
                        view.translate('simulateResultTitle', 'messages', 'Automation') +
                        '\n\n' + text.slice(0, 4000)
                    );
                })
                .catch(function () {});
        },

        _post: function (action, confirmKey, successKey) {
            var view = this.view;
            var id = view.model.id;
            Espo.Ui.confirm(
                view.translate(confirmKey, 'messages', 'Automation'),
                {
                    confirmText: view.translate('Yes'),
                    cancelText: view.translate('Cancel'),
                }
            ).then(function () {
                Espo.Ajax.postRequest('Automation/' + id + '/' + action)
                    .then(function () {
                        view.model.fetch();
                        Espo.Ui.success(view.translate(successKey, 'messages', 'Automation'));
                    })
                    .catch(function () {});
            });
        },
    });
});
