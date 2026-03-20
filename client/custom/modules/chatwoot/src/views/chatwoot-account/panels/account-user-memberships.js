define(
    "chatwoot:views/chatwoot-account/panels/account-user-memberships",
    ["views/record/panels/relationship"],
    function (Dep) {
        return Dep.extend({
            actionCreateRelated: function () {
                const accountTeamsIds = this.model.get("teamsIds") || [];
                const accountTeamsNames = this.model.get("teamsNames") || {};

                this.createView(
                    "dialogAddMembership",
                    "chatwoot:views/chatwoot-account/modals/add-user-membership",
                    {
                        accountTeamsIds: accountTeamsIds,
                        accountTeamsNames: accountTeamsNames,
                    },
                    (view) => {
                        this.listenToOnce(view, "apply", (payload) => {
                            this.createMembershipFromUser(payload);
                        });

                        view.render();
                    }
                );
            },

            createMembershipFromUser: function (payload) {
                Espo.Ui.notifyWait();

                Espo.Ajax.postRequest(
                    "ChatwootAccount/action/addUserMembership",
                    {
                        id: this.model.id,
                        ...payload,
                    }
                )
                    .then(() => {
                        Espo.Ui.success(
                            this.translate(
                                "accountUserMembershipUpserted",
                                "messages",
                                "ChatwootAccount"
                            )
                        );

                        this.collection.fetch();

                        this.model.trigger("after:relate");
                        this.model.trigger(`after:relate:${this.link}`);

                        this.processSyncBack();
                    })
                    .catch((e) => {
                        const rawMessage =
                            e && e.message
                                ? e.message
                                : this.translate(
                                      "couldNotUpsertAccountUserMembership",
                                      "messages",
                                      "ChatwootAccount"
                                  );

                        const knownErrorTranslationMap = {
                            selectedUserMustHaveEmail: this.translate(
                                "selectedUserMustHaveEmail",
                                "messages",
                                "ChatwootAccount"
                            ),
                        };

                        const message =
                            knownErrorTranslationMap[rawMessage] || rawMessage;

                        Espo.Ui.error(
                            message
                        );
                    })
                    .finally(() => {
                        Espo.Ui.notify(false);
                    });
            },
        });
    }
);
