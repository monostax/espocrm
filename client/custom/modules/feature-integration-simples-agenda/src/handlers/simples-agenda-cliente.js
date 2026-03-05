define(["action-handler"], (Dep) => {
    return class extends Dep {
        async syncNow() {
            this.view.disableMenuItem("syncNow");

            Espo.Ui.notify(
                this.view.translate("Syncing...", "labels", "SimplesAgendaCliente")
            );

            try {
                await Espo.Ajax.postRequest(
                    "SimplesAgendaCliente/action/syncNow"
                );

                Espo.Ui.success(
                    this.view.translate("Sync completed", "labels", "SimplesAgendaCliente")
                );

                if (this.view.collection) {
                    this.view.collection.fetch();
                }
            } catch (e) {
                Espo.Ui.error(
                    this.view.translate("Sync failed", "labels", "SimplesAgendaCliente")
                );
            }

            this.view.enableMenuItem("syncNow");
        }
    };
});
