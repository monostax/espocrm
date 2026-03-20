define(
    "chatwoot:views/chatwoot-account/modals/add-user-membership",
    ["views/modal", "model"],
    function (Dep, Model) {
        return Dep.extend({
            className: "dialog dialog-record",

            templateContent: `<div class="record no-side-margin">{{{record}}}</div>`,

            setup: function () {
                Dep.prototype.setup.call(this);

                this.headerText = this.translate(
                    "Add Account User",
                    "labels",
                    "ChatwootAccount"
                );

                this.buttonList = [
                    {
                        name: "save",
                        label: "Save",
                        style: "primary",
                    },
                    {
                        name: "cancel",
                        label: "Cancel",
                    },
                ];

                this.shortcutKeys = {
                    "Control+Enter": () => this.actionSave(),
                };

                const model = (this.model = new Model());

                model.name = "ChatwootAccountUserMembershipCreate";

                model.setDefs({
                    fields: {
                        assignedUser: {
                            type: "link",
                            entity: "User",
                            required: true,
                            view: "chatwoot:views/fields/user-with-create",
                        },
                        role: {
                            type: "enum",
                            required: true,
                            options: ["agent", "administrator"],
                            default: "agent",
                        },
                    },
                });

                model.set("role", "agent");

                this.createView("record", "views/record/edit-for-modal", {
                    model: model,
                    selector: ".record",
                    detailLayout: [
                        {
                            rows: [
                                [
                                    {
                                        name: "assignedUser",
                                        labelText: this.translate(
                                            "assignedUser",
                                            "fields",
                                            "ChatwootUser"
                                        ),
                                        params: {
                                            accountTeamsIds: this.options.accountTeamsIds || [],
                                            accountTeamsNames:
                                                this.options.accountTeamsNames || {},
                                        },
                                    },
                                    false,
                                ],
                                [
                                    {
                                        name: "role",
                                        labelText: this.translate(
                                            "role",
                                            "fields",
                                            "ChatwootAccountUserMembership"
                                        ),
                                    },
                                    false,
                                ],
                            ],
                        },
                    ],
                });
            },

            actionSave: function () {
                const recordView = this.getView("record");

                if (recordView.validate()) {
                    return;
                }

                const data = recordView.processFetch() || {};
                const userId = this.model.get("assignedUserId");
                const role = data.role || "agent";

                if (!userId) {
                    Espo.Ui.error(
                        this.translate("fieldIsRequired", "messages").replace(
                            "{field}",
                            this.translate("assignedUser", "fields", "ChatwootUser")
                        )
                    );

                    return;
                }

                this.trigger("apply", {
                    userId: userId,
                    role: role,
                });

                this.close();
            },
        });
    }
);
