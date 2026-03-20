define("global:helpers/type-confirmation-dialog", [], function () {
    const CANCEL_ERROR_CODE = "TYPE_CONFIRMATION_CANCELLED";

    const escapeHtml = function (value) {
        return Handlebars.Utils.escapeExpression(value);
    };

    return {
        confirm: function (view, options) {
            options = options || {};

            const message = options.message || "";
            const expectedValue = String(options.expectedValue || "DELETE");
            const instruction = options.instruction || `Type ${expectedValue} to continue.`;
            const confirmText = options.confirmText || view.translate("Yes");
            const cancelText = options.cancelText || view.translate("Cancel");
            const confirmStyle = options.confirmStyle || "danger";

            const expectedTrimmed = expectedValue.trim();
            const expectedEscaped = escapeHtml(expectedTrimmed);

            const body =
                `<span class="confirm-message">${escapeHtml(message)}</span>` +
                `<div class="margin-top">` +
                `<div class="small text-muted margin-bottom">${escapeHtml(instruction)}</div>` +
                `<div class="small text-muted margin-bottom">${expectedEscaped}</div>` +
                `<input type="text" class="form-control" data-role="type-confirm-input" autocomplete="off" spellcheck="false">` +
                `</div>`;

            return new Promise((resolve, reject) => {
                let isSettled = false;

                const rejectAsCancelled = function () {
                    const error = new Error("Type confirmation cancelled.");

                    error.code = CANCEL_ERROR_CODE;

                    reject(error);
                };

                const settleResolve = function () {
                    if (isSettled) {
                        return;
                    }

                    isSettled = true;
                    resolve();
                };

                const settleReject = function () {
                    if (isSettled) {
                        return;
                    }

                    isSettled = true;
                    rejectAsCancelled();
                };

                const dialog = Espo.Ui.dialog({
                    backdrop: true,
                    header: null,
                    closeButton: false,
                    className: "dialog-confirm",
                    backdropClassName: "backdrop-confirm",
                    body: body,
                    buttonList: [
                        {
                            text: cancelText,
                            name: "cancel",
                            className: "btn-s-wide",
                            onClick: () => {
                                settleReject();
                                dialog.close();
                            },
                            position: "left",
                        },
                        {
                            text: ` ${confirmText} `,
                            name: "confirm",
                            className: "btn-s-wide",
                            disabled: true,
                            onClick: () => {
                                const $input = dialog.$el.find('[data-role="type-confirm-input"]');

                                if (($input.val() || "").trim() !== expectedTrimmed) {
                                    return;
                                }

                                settleResolve();
                                dialog.close();
                            },
                            style: confirmStyle,
                            position: "right",
                        },
                    ],
                    onClose: () => {
                        settleReject();
                    },
                });

                dialog.$el.on("shown.bs.modal", () => {
                    const $input = dialog.$el.find('[data-role="type-confirm-input"]');
                    const $confirm = dialog.$el.find('button[data-name="confirm"]');

                    const syncConfirmState = function () {
                        const isValid = (($input.val() || "").trim() === expectedTrimmed);

                        $confirm.prop("disabled", !isValid);
                        $confirm.toggleClass("disabled", !isValid);
                    };

                    $input.on("input", syncConfirmState);
                    $input.on("paste", (event) => {
                        event.preventDefault();
                    });
                    $input.on("keydown", (event) => {
                        if (event.key !== "Enter") {
                            return;
                        }

                        if ($confirm.prop("disabled")) {
                            return;
                        }

                        event.preventDefault();

                        settleResolve();
                        dialog.close();
                    });

                    syncConfirmState();
                    $input.trigger("focus");
                });

                dialog.show();
            });
        },
    };
});
