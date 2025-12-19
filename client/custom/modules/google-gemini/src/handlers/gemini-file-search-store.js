define(["action-handler"], (Dep) => {
    return class extends Dep {
        // ==================== List View Actions ====================

        /**
         * Sync all stores from Gemini API.
         */
        async syncFromGemini() {
            this.view.disableMenuItem("syncFromGemini");

            Espo.Ui.notify(
                this.view.translate(
                    "Syncing...",
                    "labels",
                    "GeminiFileSearchStore"
                )
            );

            try {
                const response = await Espo.Ajax.postRequest(
                    "GeminiFileSearchStore/action/syncFromGemini"
                );

                Espo.Ui.success(
                    this.view.translate(
                        "Sync completed",
                        "labels",
                        "GeminiFileSearchStore"
                    ) +
                        `: ${response.created} created, ${response.updated} updated`
                );

                // Refresh the list
                if (this.view.collection) {
                    this.view.collection.fetch();
                }
            } catch (e) {
                Espo.Ui.error(
                    this.view.translate(
                        "Sync failed",
                        "labels",
                        "GeminiFileSearchStore"
                    )
                );
            }

            this.view.enableMenuItem("syncFromGemini");
        }

        // ==================== Detail View Actions ====================

        /**
         * Sync this specific store from Gemini API.
         */
        async syncStore() {
            this.view.disableMenuItem("syncStore");

            Espo.Ui.notify(
                this.view.translate(
                    "Syncing...",
                    "labels",
                    "GeminiFileSearchStore"
                )
            );

            try {
                const response = await Espo.Ajax.postRequest(
                    "GeminiFileSearchStore/action/syncStore",
                    {
                        id: this.view.model.id,
                    }
                );

                if (response.success) {
                    Espo.Ui.success(
                        this.view.translate(
                            "Sync completed",
                            "labels",
                            "GeminiFileSearchStore"
                        )
                    );

                    // Refresh the model
                    this.view.model.fetch();
                } else {
                    Espo.Ui.error(
                        this.view.translate(
                            "Sync failed",
                            "labels",
                            "GeminiFileSearchStore"
                        )
                    );
                }
            } catch (e) {
                Espo.Ui.error(
                    this.view.translate(
                        "Sync failed",
                        "labels",
                        "GeminiFileSearchStore"
                    )
                );
            }

            this.view.enableMenuItem("syncStore");
        }

        /**
         * Check if sync button should be visible.
         */
        isSyncVisible() {
            return this.view.model.get("geminiStoreName") !== null;
        }
    };
});

